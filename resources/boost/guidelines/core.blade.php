## redbit/plugin-matomo

Matomo analytics for NativePHP Mobile, backed by the native Matomo Android and iOS SDKs (offline queue, persistent visitor, opt-out).
Requires `MATOMO_URL` and `MATOMO_SITE_ID` in `.env` (baked in at build time; rebuild after changing). Nothing is tracked unless the app calls the plugin.

### PHP Usage

@verbatim
<code-snippet name="Tracking with the Matomo facade" lang="php">
use Redbit\Matomo\Facades\Matomo;

Matomo::trackScreen('/articles/42', 'Article');
Matomo::trackEvent('Video', 'Play', 'intro-video');          // category, action, name?, value?
Matomo::trackSearch('blue shoes', 'products', 14);           // keyword, category?, result count?
Matomo::trackGoal(1, 4.99);                                  // goal id, revenue?

Matomo::setUserId((string) $user->id);   // null clears
Matomo::setDimension(1, 'pro');          // persistent; null removes
Matomo::trackScreen('/settings', 'Settings', [2 => 'dark-mode']); // per-hit dimensions

Matomo::setOptOut();   // persisted by the SDK
Matomo::optIn();
</code-snippet>
@endverbatim

### Automatic screen views

Append `\Redbit\Matomo\Http\Middleware\TrackScreenView::class` to the web middleware group to track every successful full-page HTML GET (route name = title). Extend it and override `shouldTrack()` to add conditions such as consent, or to skip `fetch()` partials (which send `Accept: */*`).

### JavaScript Usage

@verbatim
<code-snippet name="Tracking from JavaScript" lang="javascript">
import { Matomo } from '../../vendor/redbit/plugin-matomo/resources/js/Matomo.js';

await Matomo.trackEvent('Video', 'Play', 'intro-video', null, { 2: 'autoplay' });
await Matomo.setOptOut(true);
</code-snippet>
@endverbatim

### Notes

- The native SDKs are opted in by default; gate every call (middleware, PHP and JS) on the app's own consent check and call `setOptOut()` when consent is refused or withdrawn.
- Analytics consent must be optional (not a condition for an account) and changeable later. `setUserId()` is personal data: only with consent, clear it on logout.
- All calls are no-ops outside a native build; PHP returns false/null, JS tracking calls never throw.
- Custom dimensions must exist in Matomo (Administration → Websites → Custom Dimensions) before their index is used.
