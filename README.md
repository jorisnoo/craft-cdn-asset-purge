# Craft CDN Asset Purge

A [Craft CMS](https://craftcms.com) module that purges stale asset and image transform URLs from [Bunny CDN](https://bunny.net) or a compatible purge endpoint.

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
| `filesystems` | Filesystem handles to monitor; include the volume's transform filesystem if it is separate | `[]` |
| `volumes` | Deprecated 1.0.0 option; matches volume handles, including their transform filesystems | `[]` |

Release 1.0.1 stopped recognizing `volumes`. Volume matching works again, but when migrating to `filesystems`, use the actual **filesystem handles**, not necessarily the old volume handles. If both lists are empty, no purges are queued.

The default endpoint uses Bunny's documented [Purge URL](https://bunny.net/docs/api-reference/core/purge/purge-url): an HTTPS `POST` with one `url` query parameter and an `AccessKey` header. There is no need to change your default configuration.

### Using a custom CDN

A custom gateway must accept `POST` with a JSON body `{"urls": [...]}` and an `AccessKey` or `Authorization: Bearer` header. Folder renames require wildcard URL support. Configure the final endpoint directly; requests do not follow redirects.

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

On asset replacement, rename, move or deletion, the module collects the original URL. It also collects generated URLs when Craft's built-in transformer deletes a rendition (including focal-point changes). Only public filesystems are eligible. A successful folder rename in the control panel purges the old asset and transform folder prefixes with `/*`.

URLs are deduplicated and queued together at the end of the web or console request, or after each job in a long-running queue worker. Delete URLs are not queued on a vetoed delete. The queue push runs outside the asset save; a queue outage cannot roll back the editor's completed save. If the queue is unavailable at flush time, fix the queue and purge the affected URLs manually.

The module uses the current site's URL; it does not enumerate other domains, aliases or custom URLs returned by third-party transform providers. A programmatic folder rename through Craft's service bypasses the control-panel action and has no Craft event: purge the old folder prefixes explicitly. Direct changes to storage outside Craft are not tracked.

### Rate limits, retries and failures

Requests are shaped for Bunny's purge rate limits, which use a separate token bucket for exact URLs (burst ~120, refill 5/s) and wildcard URLs (containing `*` or ending in `/`; burst ~20, refill 0.5/s):

- One event group may contain many URLs. Each queue job sends one HTTP request, exact URLs first, and queues any remainder. A request never mixes exact and wildcard URLs.
- For Bunny Core, the next job is delayed one second after an exact URL or two seconds after a wildcard URL. Compatible custom gateways receive JSON batches of up to 50 exact or 15 wildcard URLs, delayed 10 and 30 seconds respectively between batches.
- On `429 Too Many Requests` the batch is queued again after the `Retry-After` header (or the body's `retry_after_seconds`). The wait is capped at 5 minutes and never shorter than the refill window above, because Bunny's advertised wait can be too short. If no wait is given, it waits 60 seconds.
- On a `5xx` response, a timeout or a connection error, the batch is queued again with a growing delay.
- After 10 failed tries, or on any other error response (for example `401`), the job fails and shows as failed in the Queue Manager, where you can retry it. Failures are no longer only logged.
- Requests time out after 10 seconds (5 seconds to connect), so a hanging endpoint cannot block the queue.

`CdnAssetPurgeService::purgeUrls()` follows the same rules when you call it directly: it sends the first batch right away, queues the rest, and throws `PurgeFailedException` for a failure that retrying cannot fix.

## License

[MIT](LICENSE.md)

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
