# Changelog

## [1.1.1] - 2026-11-19

### Fixed

- Use Bunny Core's documented single-URL query request by default, pacing subsequent jobs and retaining unattempted URLs on retry. Custom gateways keep their batched JSON requests. Configure the final purge endpoint directly rather than an HTTP redirect.

## [1.1.0] - 2026-11-19

### Added

- Purge generated Craft transforms when invalidated, including focal-point changes. Monitor the transform filesystem too if the volume uses a separate one.
- Purge the old asset and transform folder prefixes after a control-panel folder rename.

### Fixed

- Bulk asset operations now deduplicate URLs in one queue job per request or worker job. Delete purges wait for a successful deletion, and a queue outage no longer interrupts the asset save transaction.
- Restored the `volumes` option dropped in 1.0.1. It matches volume handles; migrate to `filesystems` using the corresponding filesystem handles.
- Purge requests are capped at 50 URLs and never mix exact and wildcard URLs; wildcard batches are capped at 15 and paced to Bunny's wildcard rate limit.
- A rate-limited (429) purge is retried after `Retry-After` / `retry_after_seconds`, floored to the rate limit's refill window, instead of being dropped. 5xx responses, timeouts and connection errors are retried too.
- Purge failures are no longer swallowed: a rejected request, a missing API key or running out of retries fails the queue job visibly. With no API key, the module logs a warning and queues nothing.
- Purge requests time out after 10 seconds (5 seconds to connect).
- A `null` `apiUrl` or `authType` (for example from an unset env var) falls back to the default instead of throwing a `TypeError` at bootstrap; an unknown `authType` logs a warning.
- `composer validate` passes again (allow Craft's Composer plugins).

## [1.0.1](https://github.com/jorisnoo/craft-cdn-asset-purge/releases/tag/1.0.1) (2026-05-15)

### ⚠ BREAKING CHANGES

- rename bunny-purge to cdn-asset-purge ([17e0b17](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/17e0b177f1a520e307e6866c320e2b6fca6bced4))

### Features

- purge asset URLs on rename, move, and deletion ([a5b4b6b](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/a5b4b6b1fe50f9ef2805dcf9f88321cef2b95211))
- Uses the asset URL in the current site context; the earlier multi-site implementation was removed before release.

### Code Refactoring

- rename volumes config to filesystems ([0a508ac](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/0a508acfad594d023e676eecb9874fa0c36803d8))
- **purge:** simplify asset URL purging to use direct URLs ([0be09ef](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/0be09efab7040641bad4aa2a591e2ccad2e34339))
- optimize multi-site asset URL generation ([7e78976](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/7e7897651f7849453b2fa597bb1d7d993230b622))

### Chores

- add craft and cms keywords, remove author role ([3f4d89b](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/3f4d89b42849316db4d9b46c00a2104e593a5707))
- rename bunny-purge to cdn-asset-purge ([17e0b17](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/17e0b177f1a520e307e6866c320e2b6fca6bced4))
- register module alias on init ([18675c7](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/18675c7d3e0f5bb0e9455a19e25f272a4a20cd10))
## [1.0.0](https://github.com/jorisnoo/craft-cdn-asset-purge/releases/tag/1.0.0) (2026-05-12)

### ⚠ BREAKING CHANGES

- rename bunny-purge to cdn-asset-purge ([17e0b17](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/17e0b177f1a520e307e6866c320e2b6fca6bced4))

### Features

- purge asset URLs on rename, move, and deletion ([a5b4b6b](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/a5b4b6b1fe50f9ef2805dcf9f88321cef2b95211))
- Uses the asset URL in the current site context; the earlier multi-site implementation was removed before release.

### Code Refactoring

- **purge:** simplify asset URL purging to use direct URLs ([0be09ef](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/0be09efab7040641bad4aa2a591e2ccad2e34339))
- optimize multi-site asset URL generation ([7e78976](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/7e7897651f7849453b2fa597bb1d7d993230b622))

### Chores

- rename bunny-purge to cdn-asset-purge ([17e0b17](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/17e0b177f1a520e307e6866c320e2b6fca6bced4))
- register module alias on init ([18675c7](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/18675c7d3e0f5bb0e9455a19e25f272a4a20cd10))
### Changed
- Renamed the package from `jorisnoo/craft-bunny-purge` to `jorisnoo/craft-cdn-asset-purge` to reflect that it is CDN-agnostic and only handles asset URLs.

### Breaking changes
- Composer package: `jorisnoo/craft-bunny-purge` → `jorisnoo/craft-cdn-asset-purge`
- Namespace: `Noo\CraftBunnyPurge` → `Noo\CraftCdnAssetPurge`
- Module ID: `bunny-purge` → `cdn-asset-purge`
- Config file: `config/bunny-purge.php` → `config/cdn-asset-purge.php`
- Class names: `BunnyPurge` → `CdnAssetPurge`, `BunnyPurgeService` → `CdnAssetPurgeService`

### Migration

Update `config/app.php`:

```diff
-'bunny-purge' => \Noo\CraftBunnyPurge\BunnyPurge::class,
+'cdn-asset-purge' => \Noo\CraftCdnAssetPurge\CdnAssetPurge::class,
```

Rename `config/bunny-purge.php` to `config/cdn-asset-purge.php`.
