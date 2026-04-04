# Craft Bunny Purge

A [Craft CMS](https://craftcms.com) module that purges [Bunny CDN](https://bunny.net) URLs when assets are replaced. Works with any CDN that has a purge API.

No dependency on Blitz or any other caching plugin.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+

## Installation

Install via Composer:

```bash
composer require jorisnoo/craft-bunny-purge
```

Register the module in `config/app.php`:

```php
return [
    'modules' => [
        'bunny-purge' => \Noo\CraftBunnyPurge\BunnyPurge::class,
    ],
    'bootstrap' => ['bunny-purge'],
];
```

## Configuration

Create `config/bunny-purge.php`:

```php
<?php

use craft\helpers\App;

return [
    'apiKey' => App::env('BUNNY_API_KEY'),
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

When an asset in a monitored volume is replaced, the module pushes a queue job that sends a purge request to the configured API endpoint. The purge happens asynchronously so it doesn't block the control panel.

## License

[MIT](LICENSE.md)
