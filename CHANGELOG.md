# Changelog

## Unreleased

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
