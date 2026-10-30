package com.redbit.plugins.matomo

import android.content.Context
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import org.json.JSONObject
import org.matomo.sdk.Matomo
import org.matomo.sdk.TrackMe
import org.matomo.sdk.Tracker
import org.matomo.sdk.TrackerBuilder
import org.matomo.sdk.extra.CustomDimension
import org.matomo.sdk.extra.TrackHelper

/**
 * Holds the single Matomo tracker for the app.
 *
 * The tracker is created lazily from the build-time config (MATOMO_URL / MATOMO_SITE_ID,
 * written to res/values/matomo_config.xml) or from a runtime Matomo.Configure call.
 */
object MatomoManager {
    private const val TAG = "Matomo"

    private var tracker: Tracker? = null
    private var apiUrl: String? = null
    private var siteId: Int? = null

    @Synchronized
    fun configure(context: Context, url: String, siteId: Int): Tracker {
        val apiUrl = normalizeUrl(url)
        val current = tracker

        if (current != null && apiUrl == this.apiUrl && siteId == this.siteId) {
            return current
        }

        current?.dispatch()

        val created = TrackerBuilder.createDefault(apiUrl, siteId)
            .build(Matomo.getInstance(context.applicationContext))

        tracker = created
        this.apiUrl = apiUrl
        this.siteId = siteId

        Log.d(TAG, "Tracker configured for site $siteId at $apiUrl")

        return created
    }

    @Synchronized
    fun tracker(context: Context): Tracker? {
        tracker?.let { return it }

        val url = configString(context, "matomo_url") ?: return null
        val siteId = configString(context, "matomo_site_id")?.toIntOrNull() ?: return null

        return configure(context, url, siteId)
    }

    fun status(): Map<String, Any> {
        val current = tracker

        return mapOf(
            "configured" to (current != null),
            "url" to (apiUrl ?: ""),
            "siteId" to (siteId ?: 0),
            "optedOut" to (current?.isOptOut ?: false),
            "userId" to (current?.userId ?: "")
        )
    }

    /** Accepts both https://stats.example.com and https://stats.example.com/matomo.php */
    private fun normalizeUrl(url: String): String {
        val trimmed = url.trim().trimEnd('/')

        return if (trimmed.endsWith(".php")) trimmed else "$trimmed/matomo.php"
    }

    private fun configString(context: Context, name: String): String? {
        val id = context.resources.getIdentifier(name, "string", context.packageName)
        if (id == 0) {
            return null
        }

        val value = context.getString(id).trim()

        // An unresolved ${PLACEHOLDER} means the secret was not set at build time
        return if (value.isEmpty() || value.startsWith("\${")) null else value
    }
}

/**
 * Bridge functions for Matomo analytics
 * Namespace: "Matomo.*"
 */
object MatomoFunctions {

    private fun notConfigured(): Map<String, Any> = BridgeResponse.error(
        "NOT_CONFIGURED",
        "Matomo is not configured. Set MATOMO_URL and MATOMO_SITE_ID or call Matomo.Configure."
    )

    private fun string(parameters: Map<String, Any>, key: String): String? =
        (parameters[key] as? String)?.takeIf { it.isNotEmpty() }

    private fun number(parameters: Map<String, Any>, key: String): Number? =
        when (val value = parameters[key]) {
            is Number -> value
            is String -> value.toDoubleOrNull()
            else -> null
        }

    /** Dimensions arrive as a JSON object keyed by dimension index: {"1": "value"} */
    private fun dimensions(raw: Any?): Map<Int, String> {
        val result = mutableMapOf<Int, String>()

        when (raw) {
            is JSONObject -> raw.keys().forEach { key ->
                val value = raw.opt(key)
                val id = key.toIntOrNull()
                if (id != null && value != null && value != JSONObject.NULL) {
                    result[id] = value.toString()
                }
            }
            is Map<*, *> -> raw.forEach { (key, value) ->
                val id = key?.toString()?.toIntOrNull()
                if (id != null && value != null && value != JSONObject.NULL) {
                    result[id] = value.toString()
                }
            }
        }

        return result
    }

    private fun baseTrackMe(parameters: Map<String, Any>): TrackMe {
        val trackMe = TrackMe()
        dimensions(parameters["dimensions"]).forEach { (id, value) ->
            CustomDimension.setDimension(trackMe, id, value)
        }

        return trackMe
    }

    /**
     * Parameters:
     *   - url: string - Matomo base URL (matomo.php is appended when missing)
     *   - site_id: int - Matomo site ID
     *   - dispatch_interval: (optional) int - seconds between automatic dispatches
     */
    class Configure(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val url = string(parameters, "url")
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "url is required")
            val siteId = number(parameters, "site_id")?.toInt()
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "site_id is required")

            val tracker = MatomoManager.configure(activity, url, siteId)

            number(parameters, "dispatch_interval")?.let {
                tracker.setDispatchInterval((it.toDouble() * 1000).toLong())
            }

            return BridgeResponse.success(MatomoManager.status())
        }
    }

    /**
     * Parameters:
     *   - path: string - screen path, e.g. "/quiz/12"
     *   - title: (optional) string - human readable screen title
     *   - dimensions: (optional) object - {index: value} for this hit only
     */
    class TrackScreen(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()
            val path = string(parameters, "path")
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "path is required")

            val screen = TrackHelper.track(baseTrackMe(parameters)).screen(path)
            string(parameters, "title")?.let { screen.title(it) }
            screen.with(tracker)

            return BridgeResponse.success(mapOf("tracked" to true))
        }
    }

    /**
     * Parameters:
     *   - category: string
     *   - action: string
     *   - name: (optional) string
     *   - value: (optional) number
     *   - dimensions: (optional) object - {index: value} for this hit only
     */
    class TrackEvent(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()
            val category = string(parameters, "category")
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "category is required")
            val action = string(parameters, "action")
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "action is required")

            val event = TrackHelper.track(baseTrackMe(parameters)).event(category, action)
            string(parameters, "name")?.let { event.name(it) }
            number(parameters, "value")?.let { event.value(it.toFloat()) }
            event.with(tracker)

            return BridgeResponse.success(mapOf("tracked" to true))
        }
    }

    /**
     * Parameters:
     *   - keyword: string
     *   - category: (optional) string
     *   - count: (optional) int - number of results
     */
    class TrackSearch(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()
            val keyword = string(parameters, "keyword")
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "keyword is required")

            val search = TrackHelper.track(baseTrackMe(parameters)).search(keyword)
            string(parameters, "category")?.let { search.category(it) }
            number(parameters, "count")?.let { search.count(it.toInt()) }
            search.with(tracker)

            return BridgeResponse.success(mapOf("tracked" to true))
        }
    }

    /**
     * Parameters:
     *   - goal_id: int
     *   - revenue: (optional) number
     */
    class TrackGoal(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()
            val goalId = number(parameters, "goal_id")?.toInt()
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "goal_id is required")

            val goal = TrackHelper.track().goal(goalId)
            number(parameters, "revenue")?.let { goal.revenue(it.toFloat()) }
            goal.with(tracker)

            return BridgeResponse.success(mapOf("tracked" to true))
        }
    }

    /**
     * Parameters:
     *   - user_id: (optional) string - omit or null to clear
     */
    class SetUserId(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()

            tracker.setUserId(string(parameters, "user_id"))

            return BridgeResponse.success(MatomoManager.status())
        }
    }

    /**
     * Sets a dimension on every following hit.
     * Parameters:
     *   - id: int - dimension index
     *   - value: (optional) string - omit or null to remove
     */
    class SetDimension(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()
            val id = number(parameters, "id")?.toInt()
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "id is required")

            CustomDimension.setDimension(tracker.defaultTrackMe, id, string(parameters, "value"))

            return BridgeResponse.success(mapOf("id" to id))
        }
    }

    /**
     * Parameters:
     *   - opt_out: bool
     */
    class SetOptOut(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()

            tracker.isOptOut = parameters["opt_out"] as? Boolean ?: true

            return BridgeResponse.success(MatomoManager.status())
        }
    }

    class Dispatch(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()

            tracker.dispatch()

            return BridgeResponse.success(mapOf("dispatched" to true))
        }
    }

    class StartNewSession(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val tracker = MatomoManager.tracker(activity) ?: return notConfigured()

            tracker.startNewSession()

            return BridgeResponse.success(mapOf("started" to true))
        }
    }

    class GetStatus(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Resolve the build-time config so the status reflects it before the first hit
            MatomoManager.tracker(activity)

            return BridgeResponse.success(MatomoManager.status())
        }
    }
}
