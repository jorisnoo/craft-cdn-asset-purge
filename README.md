# Craft CDN Asset Purge

A [Craft CMS](https://craftcms.com) module that purges CDN URLs when assets are replaced, renamed, moved, or deleted. Works with [Bunny CDN](https://bunny.net) or any other CDN that has a purge API.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

Install via Composer:

```bash
composer require jorisnoo/craft-cdn-asset-purge
```

Register the module in `config/app.php`:

```php
return [
    'modules' => [
        'cdn-asset-purge' => \Noo\CraftCdnAssetPurge\CdnAssetPurge::class,
    ],
    'bootstrap' => ['cdn-asset-purge'],
];
```

## Configuration

Create `config/cdn-asset-purge.php`:

```php
<?php

use craft\helpers\App;

return [
    'apiKey' => App::env('CDN_API_KEY'),
    'volumes' => ['images'],
];
```

| Setting | Description | Default |
|---------|-------------|---------|
| `apiUrl` | The CDN purge API endpoint | `https://api.bunny.net/purge` |
| `apiKey` | Your CDN API key | — |
| `authType` | Auth header style: `access_key` or `bearer` | `access_key` |
| `volumes` | Asset volume handles to monitor | `[]` |

### Using a custom CDN

Override the API URL and auth type to point at any purge endpoint:

```php
return [
    'apiUrl' => 'https://cdn.example.com/api/cache/purge',
    'apiKey' => App::env('CDN_API_KEY'),
    'authType' => 'bearer',
    'volumes' => ['images'],
];
```

## How it works

When an asset in a monitored volume is replaced, renamed, moved, or deleted, the module pushes a queue job that sends a purge request to the configured API endpoint. The purge happens asynchronously so it doesn't block the control panel.

## License

[MIT](LICENSE.md)
