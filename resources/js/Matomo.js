/**
 * Matomo Plugin for NativePHP Mobile
 *
 * Tracking calls are fire-and-forget: they resolve to false instead of throwing,
 * so analytics can never break the app (e.g. when running in a normal browser).
 *
 * @example
 * import { Matomo } from '../../vendor/redbit/plugin-matomo/resources/js/Matomo.js';
 *
 * await Matomo.trackScreen('/articles/42', 'Article');
 * await Matomo.trackEvent('Video', 'Play', 'intro-video');
 * await Matomo.setOptOut(true);
 */

const baseUrl = '/_native/api/call';

async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    const nativeResponse = result.data;

    if (nativeResponse?.status === 'error') {
        throw new Error(nativeResponse.message || 'Native call failed');
    }

    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

async function quietCall(method, params = {}) {
    try {
        return await bridgeCall(method, params);
    } catch {
        return null;
    }
}

function tracked(response) {
    return Boolean(response?.tracked);
}

/** Dimension index => value, sent as a JSON object; empty values are dropped. */
function dimensions(input = {}) {
    return Object.fromEntries(
        Object.entries(input)
            .filter(([, value]) => value !== null && value !== undefined && value !== '')
            .map(([id, value]) => [id, String(value)])
    );
}

async function configure(url, siteId, dispatchInterval = null) {
    const params = { url, site_id: siteId };

    if (dispatchInterval !== null) {
        params.dispatch_interval = dispatchInterval;
    }

    return bridgeCall('Matomo.Configure', params);
}

async function trackScreen(path, title = null, dims = {}) {
    return tracked(await quietCall('Matomo.TrackScreen', {
        path: '/' + String(path).replace(/^\/+/, ''),
        title,
        dimensions: dimensions(dims)
    }));
}

async function trackEvent(category, action, name = null, value = null, dims = {}) {
    return tracked(await quietCall('Matomo.TrackEvent', {
        category,
        action,
        name,
        value,
        dimensions: dimensions(dims)
    }));
}

async function trackSearch(keyword, category = null, count = null) {
    return tracked(await quietCall('Matomo.TrackSearch', { keyword, category, count }));
}

async function trackGoal(goalId, revenue = null) {
    return tracked(await quietCall('Matomo.TrackGoal', { goal_id: goalId, revenue }));
}

async function setUserId(userId) {
    return (await quietCall('Matomo.SetUserId', { user_id: userId })) !== null;
}

async function setDimension(id, value) {
    return (await quietCall('Matomo.SetDimension', { id, value })) !== null;
}

async function setOptOut(optOut = true) {
    return (await quietCall('Matomo.SetOptOut', { opt_out: optOut })) !== null;
}

async function optIn() {
    return setOptOut(false);
}

async function dispatch() {
    return (await quietCall('Matomo.Dispatch')) !== null;
}

async function startNewSession() {
    return (await quietCall('Matomo.StartNewSession')) !== null;
}

async function getStatus() {
    return quietCall('Matomo.GetStatus');
}

export const Matomo = {
    configure,
    trackScreen,
    trackEvent,
    trackSearch,
    trackGoal,
    setUserId,
    setDimension,
    setOptOut,
    optIn,
    dispatch,
    startNewSession,
    getStatus
};

export default Matomo;
