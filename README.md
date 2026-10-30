# Matomo Plugin for NativePHP Mobile

Matomo analytics for NativePHP Mobile apps. It uses the official native SDKs
([matomo-sdk-android](https://github.com/matomo-org/matomo-sdk-android) 4.4 and
[matomo-sdk-ios](https://github.com/matomo-org/matomo-sdk-ios) 7.8), so you get:

- an offline queue (hits are stored on the device and sent when the network is back)
- batched dispatching
- persistent visitor IDs and sessions
- an opt-out that survives app restarts
- device, OS, app version and locale reported automatically

Nothing is tracked automatically. Hits are only sent when your app calls the
plugin, so you decide what is tracked and when, for example only after consent.

You can call the plugin from PHP (facade), from JavaScript, or through the bridge
functions directly.

## Installation

The package isn't on Packagist. Add the GitHub repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/RedBitSCS/nativephp-plugin-matomo" }
]
```

Then install and register it:

```bash
composer require redbit/plugin-matomo:^0.1
php artisan vendor:publish --tag=nativephp-plugins-provider   # first time only
php artisan native:plugin:register redbit/plugin-matomo
php artisan native:plugin:list
```

The repository is private. Composer needs access to GitHub on every machine and
CI job that installs it: either a git credential that can read the repository
(add `"no-api": true` to the repository entry so Composer clones with git), or a
token via `composer config --global github-oauth.github.com <token>`.

To work on the plugin and an app at the same time, use a path repository with a symlink:

```json
"repositories": [
    { "type": "path", "url": "../nativephp-plugin-matomo", "options": { "symlink": true } }
]
```

## Configuration

Add these to the app's `.env`. They are **required**, and the native build fails if they are missing:

```dotenv
MATOMO_URL=https://stats.example.com
MATOMO_SITE_ID=3
```

`matomo.php` is appended to the URL automatically. These values are baked into the
build: Android gets `res/values/matomo_config.xml`, iOS gets the `MatomoURL` and
`MatomoSiteId` keys in Info.plist. Change them and you need to rebuild.

To point the app at another site at runtime (for example a staging site), call
`Matomo::configure()`. The override lasts until the app restarts.

## Requirements

### Android
- Permissions: `INTERNET`, `ACCESS_NETWORK_STATE`
- Dependency: `com.github.matomo-org:matomo-sdk-android:4.4` (from JitPack)

### iOS
- Swift package: `matomo-sdk-ios` 7.8.0 (product `MatomoTracker`)
- No permission prompts.

## Usage

### PHP (Blade / Livewire / controllers)

```php
use Redbit\Matomo\Facades\Matomo;

// Screen views: a path, plus an optional human-readable title
Matomo::trackScreen('/articles/42', 'Article');

// Events: category, action, optional name and numeric value
Matomo::trackEvent('Video', 'Play', 'intro-video');
Matomo::trackEvent('Checkout', 'Completed', 'card', 49.95);

// Search and goals
Matomo::trackSearch('blue shoes', 'products', 14);  // keyword, category, result count
Matomo::trackGoal(1, 4.99);                          // goal ID, optional revenue

// Visitor context
Matomo::setUserId((string) $user->id);   // null to clear
Matomo::setDimension(1, 'pro');          // sent with every following hit, null removes it

// Dimensions for a single hit: dimension index => value
Matomo::trackScreen('/settings', 'Settings', [2 => 'dark-mode']);

// Opt-out (stored by the SDK, survives restarts)
Matomo::setOptOut();        // stop tracking
Matomo::optIn();
Matomo::isOptedOut();       // bool|null (null when not running natively)

// Misc
Matomo::dispatch();         // send queued hits now
Matomo::startNewSession();  // e.g. after logout
Matomo::getStatus();        // ['configured', 'url', 'siteId', 'optedOut', 'userId']
```

`trackScreen()`, `trackEvent()`, `trackSearch()` and `trackGoal()` return `true`
when the hit was queued. Outside a native build (in the browser or in tests),
every call is a no-op and returns `false` or `null`.

### Automatic screen views

`TrackScreenView` tracks every successful full-page `GET` that returns HTML as a
screen view. It skips AJAX requests and NativePHP's own `_native/*` routes, and
uses the route name as the title.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [\Redbit\Matomo\Http\Middleware\TrackScreenView::class]);
})
```

To change which requests count as a screen view, extend the middleware and
override `shouldTrack()`. For example, to skip HTML partials loaded with `fetch()`,
which do not send `X-Requested-With`:

```php
use Illuminate\Http\Request;
use Redbit\Matomo\Http\Middleware\TrackScreenView;
use Symfony\Component\HttpFoundation\Response;

class TrackScreenViews extends TrackScreenView
{
    protected function shouldTrack(Request $request, Response $response): bool
    {
        // fetch() sends Accept: */*; real navigations ask for text/html.
        return str_contains((string) $request->header('Accept'), 'text/html')
            && parent::shouldTrack($request, $response);
    }
}
```

### JavaScript

```javascript
import { Matomo } from '../../vendor/redbit/plugin-matomo/resources/js/Matomo.js';

await Matomo.trackScreen('/articles/42', 'Article');
await Matomo.trackEvent('Video', 'Play', 'intro-video', null, { 2: 'autoplay' });
await Matomo.setOptOut(true);
const status = await Matomo.getStatus();
```

The JS tracking calls never throw. They resolve to `false` when the call fails
or when the code is not running inside the app. `Matomo.configure()` is the
exception: it throws on errors, so you can tell when a configuration fails.

## Consent

The native SDKs track by default: a new install is **not** opted out. The plugin
only sends what your app asks for, so the simplest way to handle consent is to
route every call through a single check in your app:

```php
use Redbit\Matomo\Facades\Matomo;

class Analytics
{
    public static function allowed(): bool
    {
        return session('analytics_consent') === true; // your own consent store
    }

    public static function event(string $category, string $action, ?string $name = null, int|float|null $value = null): void
    {
        if (self::allowed()) {
            Matomo::trackEvent($category, $action, $name, $value);
        }
    }

    public static function setConsent(bool $granted): void
    {
        session(['analytics_consent' => $granted]);

        $granted ? Matomo::optIn() : Matomo::setOptOut();
    }
}
```

Guidelines:

- **Gate everything on consent.** Gate the screen-view middleware (via
  `shouldTrack()`) as well as manual events, and in JavaScript expose the
  decision to the page (for example a `data-` attribute on `<body>`).
- **Also set the opt-out.** When consent is refused or withdrawn, call
  `setOptOut()` as well, so nothing still in the queue is sent.
- **Keep consent optional.** Under the GDPR, consent for analytics must be freely
  given, so don't make it a condition for creating an account or using the app.
  Make it easy to change later, for example from a settings screen.
- **User IDs are personal data.** `setUserId()` links hits to a person; only set it
  with consent, and clear it (`setUserId(null)`) on logout.
- **Minors** below the digital age of consent in their country (13 to 16 in the
  EU) need parental consent. Leaving them untracked is often simplest.

## Bridge functions

| Function | Parameters |
|---|---|
| `Matomo.Configure` | `url`, `site_id`, `dispatch_interval?` (seconds) |
| `Matomo.TrackScreen` | `path`, `title?`, `dimensions?` |
| `Matomo.TrackEvent` | `category`, `action`, `name?`, `value?`, `dimensions?` |
| `Matomo.TrackSearch` | `keyword`, `category?`, `count?` |
| `Matomo.TrackGoal` | `goal_id`, `revenue?` |
| `Matomo.SetUserId` | `user_id?` |
| `Matomo.SetDimension` | `id`, `value?` |
| `Matomo.SetOptOut` | `opt_out` |
| `Matomo.Dispatch` | — |
| `Matomo.StartNewSession` | — |
| `Matomo.GetStatus` | — |

`dimensions` is an object keyed by dimension index, for example `{"1": "pro"}`.

When the tracker has no URL or site ID, every function returns the error
`NOT_CONFIGURED`. Missing required parameters return `INVALID_PARAMETERS`.

## Events

None. The plugin only sends data and never dispatches events back to the app.

## Troubleshooting

- **No hits in Matomo.** Hits are sent in batches (by default every 30 seconds on
  iOS and every 2 minutes on Android) and only when the device is online. Call `Matomo::dispatch()` to send them
  immediately while testing, and check `Matomo::getStatus()`: `configured` must be
  `true` and `optedOut` must be `false`.
- **Changed `MATOMO_URL` or `MATOMO_SITE_ID`, but nothing changed.** These values
  are baked in at build time; rebuild the app.
- **A custom dimension doesn't appear.** Create it in Matomo first (Administration →
  Websites → Custom Dimensions) and use the index Matomo assigns to it.
- **The same screen view appears twice.** Check that you aren't tracking it both in
  the middleware and manually.

## Privacy checklist

- In Matomo, turn on IP anonymisation and set a data retention period.
- Mention Matomo in your privacy policy: what you track and how users can opt out.
- Update the App Store "App Privacy" form (usage data / product interaction) and
  the Google Play "Data safety" form.

## License

MIT
