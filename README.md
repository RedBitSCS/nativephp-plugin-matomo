# Matomo Plugin for NativePHP Mobile

Matomo analytics for NativePHP Mobile apps. It uses the official native SDKs
([matomo-sdk-android](https://github.com/matomo-org/matomo-sdk-android) 4.4 and
[matomo-sdk-ios](https://github.com/matomo-org/matomo-sdk-ios) 7.8), so you get:

- an offline queue (hits are stored on the device and sent when the network is back)
- batched dispatching
- persistent visitor IDs and sessions
- an opt-out that survives app restarts
- device, OS, app version and locale reported automatically

Nothing is tracked automatically. Your app decides what to send and when, so you
can wait for consent first.

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
- No permission prompts. Update the App Store privacy labels: usage data / product interaction.

## Usage

### PHP (Blade / Livewire / controllers)

```php
use Redbit\Matomo\Facades\Matomo;

// Screens
Matomo::trackScreen('/quiz/12', 'Quiz');

// Events (category, action, name, value)
Matomo::trackEvent('Quiz', 'Completed', 'Weekly quiz', 8);

// Search and goals
Matomo::trackSearch('messi', 'players', 14);
Matomo::trackGoal(1, 4.99);

// Visitor context
Matomo::setUserId((string) $user->id);   // null to clear
Matomo::setDimension(1, 'premium');       // sent with every following hit, null removes it

// Dimensions for a single hit
Matomo::trackScreen('/profile', 'Profile', [2 => 'own-profile']);

// Consent
Matomo::setOptOut();        // stop tracking (persisted)
Matomo::optIn();
Matomo::isOptedOut();       // bool|null (null when not running natively)

// Misc
Matomo::dispatch();         // send queued hits now
Matomo::startNewSession();  // e.g. after logout
Matomo::getStatus();        // ['configured', 'url', 'siteId', 'optedOut', 'userId']
```

Outside a native build (in the browser or in tests), every call is a no-op and
returns `false` or `null`.

### Automatic screen views

`TrackScreenView` tracks every successful full-page `GET` as a screen view. It
uses the route name as the title.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [\Redbit\Matomo\Http\Middleware\TrackScreenView::class]);
})
```

### JavaScript

```javascript
import { Matomo } from '../../vendor/redbit/plugin-matomo/resources/js/Matomo.js';

await Matomo.trackScreen('/quiz/12', 'Quiz');
await Matomo.trackEvent('Quiz', 'Answered', 'question-3', 1, { 2: 'weekly' });
await Matomo.setOptOut(true);
const status = await Matomo.getStatus();
```

The JS tracking calls never throw. They resolve to `false` when the call fails
or when the code is not running inside the app.

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

`dimensions` is an object keyed by dimension index, for example `{"1": "premium"}`.
Create the dimensions in Matomo first (Administration → Custom Dimensions).

## Events

None. The plugin only sends data and never dispatches events back to the app.

## Privacy

- Track nothing until the user has given consent, or call `Matomo::setOptOut()`
  on first launch and `Matomo::optIn()` once they agree.
- In Matomo, turn on IP anonymisation and data retention limits.
- Update the App Store "App Privacy" and Google Play "Data safety" forms.

## License

MIT
