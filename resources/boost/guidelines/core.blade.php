## redbit/plugin-matomo

Matomo analytics for NativePHP Mobile, backed by the native Matomo Android and iOS SDKs (offline queue, persistent visitor, opt-out).
Requires `MATOMO_URL` and `MATOMO_SITE_ID` in `.env` (baked in at build time). Nothing is tracked automatically.

### PHP Usage

@verbatim
<code-snippet name="Tracking with the Matomo facade" lang="php">
use Redbit\Matomo\Facades\Matomo;

Matomo::trackScreen('/quiz/12', 'Quiz');
Matomo::trackEvent('Quiz', 'Completed', 'Weekly quiz', 8);
Matomo::trackSearch('messi', 'players', 14);
Matomo::trackGoal(1, 4.99);

Matomo::setUserId((string) $user->id);   // null clears
Matomo::setDimension(1, 'premium');       // persistent; null removes
Matomo::trackScreen('/profile', 'Profile', [2 => 'own']); // per-hit dimensions

Matomo::setOptOut();   // persisted by the SDK
Matomo::optIn();
</code-snippet>
@endverbatim

### Automatic screen views

Append `\Redbit\Matomo\Http\Middleware\TrackScreenView::class` to the web middleware group to track every successful full-page GET (route name = title).

### JavaScript Usage

@verbatim
<code-snippet name="Tracking from JavaScript" lang="javascript">
import { Matomo } from '../../vendor/redbit/plugin-matomo/resources/js/Matomo.js';

await Matomo.trackEvent('Quiz', 'Answered', 'question-3', 1, { 2: 'weekly' });
await Matomo.setOptOut(true);
</code-snippet>
@endverbatim

### Notes

- All calls are no-ops outside a native build; PHP returns false/null, JS tracking calls never throw.
- Custom dimensions must exist in Matomo (Administration → Custom Dimensions) before their index is used.
- Respect consent: do not track before the user agrees, or opt out on first launch.
