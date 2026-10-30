import Foundation
import MatomoTracker

// MARK: - Tracker Manager

/// Holds the single Matomo tracker for the app.
///
/// The tracker is created lazily from the build-time config (MATOMO_URL / MATOMO_SITE_ID,
/// written to Info.plist as MatomoURL / MatomoSiteId) or from a runtime Matomo.Configure call.
final class MatomoManager {
    static let shared = MatomoManager()

    private let lock = NSLock()
    private var tracker: MatomoTracker?
    private var apiUrl: URL?
    private var siteId: String?

    private init() {}

    @discardableResult
    func configure(url: String, siteId: String) -> MatomoTracker? {
        lock.lock()
        defer { lock.unlock() }

        return configureLocked(url: url, siteId: siteId)
    }

    func resolve() -> MatomoTracker? {
        lock.lock()
        defer { lock.unlock() }

        if let tracker = tracker {
            return tracker
        }

        guard let url = configString("MatomoURL"), let siteId = configString("MatomoSiteId") else {
            return nil
        }

        return configureLocked(url: url, siteId: siteId)
    }

    func status() -> [String: Any] {
        lock.lock()
        defer { lock.unlock() }

        return [
            "configured": tracker != nil,
            "url": apiUrl?.absoluteString ?? "",
            "siteId": Int(siteId ?? "") ?? 0,
            "optedOut": tracker?.isOptedOut ?? false,
            "userId": tracker?.userId ?? ""
        ]
    }

    private func configureLocked(url: String, siteId: String) -> MatomoTracker? {
        guard let apiUrl = normalizeUrl(url) else {
            print("Matomo: invalid URL \(url)")
            return nil
        }

        if let tracker = tracker, apiUrl == self.apiUrl, siteId == self.siteId {
            return tracker
        }

        tracker?.dispatch()

        let created = MatomoTracker(siteId: siteId, baseURL: apiUrl)
        tracker = created
        self.apiUrl = apiUrl
        self.siteId = siteId

        print("Matomo: tracker configured for site \(siteId) at \(apiUrl.absoluteString)")

        return created
    }

    /// Accepts both https://stats.example.com and https://stats.example.com/matomo.php
    private func normalizeUrl(_ url: String) -> URL? {
        var trimmed = url.trimmingCharacters(in: .whitespacesAndNewlines)
        while trimmed.hasSuffix("/") {
            trimmed.removeLast()
        }

        return URL(string: trimmed.hasSuffix(".php") ? trimmed : "\(trimmed)/matomo.php")
    }

    private func configString(_ key: String) -> String? {
        guard let raw = Bundle.main.object(forInfoDictionaryKey: key) else {
            return nil
        }

        let value = "\(raw)".trimmingCharacters(in: .whitespacesAndNewlines)

        // An unresolved ${PLACEHOLDER} means the secret was not set at build time
        return value.isEmpty || value.hasPrefix("${") ? nil : value
    }
}

// MARK: - Matomo Function Namespace

enum MatomoFunctions {

    static func notConfigured() -> [String: Any] {
        return BridgeResponse.error(
            code: "NOT_CONFIGURED",
            message: "Matomo is not configured. Set MATOMO_URL and MATOMO_SITE_ID or call Matomo.Configure."
        )
    }

    static func invalid(_ message: String) -> [String: Any] {
        return BridgeResponse.error(code: "INVALID_PARAMETERS", message: message)
    }

    static func string(_ parameters: [String: Any], _ key: String) -> String? {
        guard let value = parameters[key] as? String, !value.isEmpty else {
            return nil
        }

        return value
    }

    static func number(_ parameters: [String: Any], _ key: String) -> NSNumber? {
        switch parameters[key] {
        case let value as NSNumber:
            return value
        case let value as String:
            return Double(value).map { NSNumber(value: $0) }
        default:
            return nil
        }
    }

    /// Dimensions arrive as a JSON object keyed by dimension index: {"1": "value"}
    static func dimensions(_ parameters: [String: Any]) -> [CustomDimension] {
        guard let raw = parameters["dimensions"] as? [String: Any] else {
            return []
        }

        return raw.compactMap { key, value in
            guard let index = Int(key), !(value is NSNull) else {
                return nil
            }

            return CustomDimension(index: index, value: "\(value)")
        }
    }

    // MARK: - Matomo.Configure

    /// Parameters:
    ///   - url: string - Matomo base URL (matomo.php is appended when missing)
    ///   - site_id: int - Matomo site ID
    ///   - dispatch_interval: (optional) int - seconds between automatic dispatches
    class Configure: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let url = string(parameters, "url") else {
                return invalid("url is required")
            }

            guard let siteId = number(parameters, "site_id")?.intValue else {
                return invalid("site_id is required")
            }

            guard let tracker = MatomoManager.shared.configure(url: url, siteId: String(siteId)) else {
                return invalid("url is not a valid URL")
            }

            if let interval = number(parameters, "dispatch_interval") {
                tracker.dispatchInterval = interval.doubleValue
            }

            return BridgeResponse.success(data: MatomoManager.shared.status())
        }
    }

    // MARK: - Matomo.TrackScreen

    /// Parameters:
    ///   - path: string - screen path, e.g. "/articles/42"
    ///   - title: (optional) string - human readable screen title
    ///   - dimensions: (optional) object - {index: value} for this hit only
    class TrackScreen: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            guard let path = string(parameters, "path") else {
                return invalid("path is required")
            }

            // Match Android: the title becomes the action name, the path becomes the URL
            let components = path.split(separator: "/").map(String.init)
            let view = string(parameters, "title").map { [$0] } ?? (components.isEmpty ? ["/"] : components)
            let relativePath = path.hasPrefix("/") ? String(path.dropFirst()) : path
            let url = tracker.contentBase.flatMap { URL(string: relativePath, relativeTo: $0)?.absoluteURL }

            tracker.track(view: view, url: url, dimensions: dimensions(parameters))

            return BridgeResponse.success(data: ["tracked": true])
        }
    }

    // MARK: - Matomo.TrackEvent

    /// Parameters:
    ///   - category: string
    ///   - action: string
    ///   - name: (optional) string
    ///   - value: (optional) number
    ///   - dimensions: (optional) object - {index: value} for this hit only
    class TrackEvent: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            guard let category = string(parameters, "category") else {
                return invalid("category is required")
            }

            guard let action = string(parameters, "action") else {
                return invalid("action is required")
            }

            tracker.track(
                eventWithCategory: category,
                action: action,
                name: string(parameters, "name"),
                value: number(parameters, "value")?.floatValue,
                dimensions: dimensions(parameters)
            )

            return BridgeResponse.success(data: ["tracked": true])
        }
    }

    // MARK: - Matomo.TrackSearch

    /// Parameters:
    ///   - keyword: string
    ///   - category: (optional) string
    ///   - count: (optional) int - number of results
    class TrackSearch: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            guard let keyword = string(parameters, "keyword") else {
                return invalid("keyword is required")
            }

            tracker.trackSearch(
                query: keyword,
                category: string(parameters, "category"),
                resultCount: number(parameters, "count")?.intValue,
                dimensions: dimensions(parameters)
            )

            return BridgeResponse.success(data: ["tracked": true])
        }
    }

    // MARK: - Matomo.TrackGoal

    /// Parameters:
    ///   - goal_id: int
    ///   - revenue: (optional) number
    class TrackGoal: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            guard let goalId = number(parameters, "goal_id")?.intValue else {
                return invalid("goal_id is required")
            }

            tracker.trackGoal(id: goalId, revenue: number(parameters, "revenue")?.floatValue)

            return BridgeResponse.success(data: ["tracked": true])
        }
    }

    // MARK: - Matomo.SetUserId

    /// Parameters:
    ///   - user_id: (optional) string - omit or null to clear
    class SetUserId: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            tracker.userId = string(parameters, "user_id")

            return BridgeResponse.success(data: MatomoManager.shared.status())
        }
    }

    // MARK: - Matomo.SetDimension

    /// Sets a dimension on every following hit.
    /// Parameters:
    ///   - id: int - dimension index
    ///   - value: (optional) string - omit or null to remove
    class SetDimension: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            guard let id = number(parameters, "id")?.intValue else {
                return invalid("id is required")
            }

            if let value = string(parameters, "value") {
                tracker.set(dimension: CustomDimension(index: id, value: value))
            } else {
                tracker.remove(dimensionAtIndex: id)
            }

            return BridgeResponse.success(data: ["id": id])
        }
    }

    // MARK: - Matomo.SetOptOut

    /// Parameters:
    ///   - opt_out: bool
    class SetOptOut: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            tracker.isOptedOut = parameters["opt_out"] as? Bool ?? true

            return BridgeResponse.success(data: MatomoManager.shared.status())
        }
    }

    // MARK: - Matomo.Dispatch

    class Dispatch: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            tracker.dispatch()

            return BridgeResponse.success(data: ["dispatched": true])
        }
    }

    // MARK: - Matomo.StartNewSession

    class StartNewSession: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let tracker = MatomoManager.shared.resolve() else {
                return notConfigured()
            }

            tracker.startNewSession()

            return BridgeResponse.success(data: ["started": true])
        }
    }

    // MARK: - Matomo.GetStatus

    class GetStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            // Resolve the build-time config so the status reflects it before the first hit
            _ = MatomoManager.shared.resolve()

            return BridgeResponse.success(data: MatomoManager.shared.status())
        }
    }
}
