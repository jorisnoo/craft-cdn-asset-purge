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
    'filesystems' => ['images'],
];
```

| Setting | Description | Default |
|---------|-------------|---------|
| `apiUrl` | The CDN purge API endpoint | `https://api.bunny.net/purge` |
| `apiKey` | Your CDN API key | — |
| `authType` | Auth header style: `access_key` or `bearer` | `access_key` |
| `filesystems` | Filesystem handles to monitor | `[]` |

### Using a custom CDN

Override the API URL and auth type to point at any purge endpoint:

```php
return [
    'apiUrl' => 'https://cdn.example.com/api/cache/purge',
    'apiKey' => App::env('CDN_API_KEY'),
    'authType' => 'bearer',
    'filesystems' => ['images'],
];
```

A setting that resolves to `null` (for example `App::env()` of an unset variable) falls back to its default, and an unknown `authType` falls back to `access_key` with a logged warning. If `apiKey` is empty, the module logs a warning and does not queue any purges.

## How it works

When an asset in a monitored filesystem is replaced, renamed, moved, or deleted, the module pushes a queue job that sends a purge request (`POST` with a JSON body `{"urls": [...]}`) to the configured API endpoint. The purge happens asynchronously so it doesn't block the control panel.

### Rate limits, retries and failures

Requests are shaped for Bunny's purge rate limits, which use a separate token bucket for exact URLs (burst ~120, refill 5/s) and wildcard URLs (containing `*` or ending in `/`; burst ~20, refill 0.5/s):

- A request never mixes exact and wildcard URLs. Exact URLs go first, in batches of up to 50. Wildcard URLs go in batches of up to 15.
- Each job sends one batch. The remaining URLs are queued as a new job, delayed 10 seconds after an exact batch or 30 seconds after a wildcard batch.
- On `429 Too Many Requests` the batch is queued again after the `Retry-After` header (or the body's `retry_after_seconds`). The wait is capped at 5 minutes and never shorter than the refill window above, because Bunny's advertised wait can be too short. If no wait is given, it waits 60 seconds.
- On a `5xx` response, a timeout or a connection error, the batch is queued again with a growing delay.
- After 10 failed tries, or on any other error response (for example `401`), the job fails and shows as failed in the Queue Manager, where you can retry it. Failures are no longer only logged.
- Requests time out after 10 seconds (5 seconds to connect), so a hanging endpoint cannot block the queue.

`CdnAssetPurgeService::purgeUrls()` follows the same rules when you call it directly: it sends the first batch right away, queues the rest, and throws `PurgeFailedException` for a failure that retrying cannot fix.

## License

[MIT](LICENSE.md)

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
