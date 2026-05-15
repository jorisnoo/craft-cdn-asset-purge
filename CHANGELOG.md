# Changelog

## Unreleased

## [1.0.1](https://github.com/jorisnoo/craft-cdn-asset-purge/releases/tag/v1.0.1) (2026-05-15)

### ⚠ BREAKING CHANGES

- rename bunny-purge to cdn-asset-purge ([17e0b17](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/17e0b177f1a520e307e6866c320e2b6fca6bced4))

### Features

- purge asset URLs on rename, move, and deletion ([a5b4b6b](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/a5b4b6b1fe50f9ef2805dcf9f88321cef2b95211))
- purge asset URLs across multiple sites ([99a533f](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/99a533fd784560be535876fcddc0bc7c9e7e8f46))

### Code Refactoring

- rename volumes config to filesystems ([0a508ac](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/0a508acfad594d023e676eecb9874fa0c36803d8))
- **purge:** simplify asset URL purging to use direct URLs ([0be09ef](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/0be09efab7040641bad4aa2a591e2ccad2e34339))
- optimize multi-site asset URL generation ([7e78976](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/7e7897651f7849453b2fa597bb1d7d993230b622))

### Chores

- add craft and cms keywords, remove author role ([3f4d89b](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/3f4d89b42849316db4d9b46c00a2104e593a5707))
- rename bunny-purge to cdn-asset-purge ([17e0b17](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/17e0b177f1a520e307e6866c320e2b6fca6bced4))
- register module alias on init ([18675c7](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/18675c7d3e0f5bb0e9455a19e25f272a4a20cd10))
## [1.0.0](https://github.com/jorisnoo/craft-cdn-asset-purge/releases/tag/v1.0.0) (2026-05-12)

### ⚠ BREAKING CHANGES

- rename bunny-purge to cdn-asset-purge ([17e0b17](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/17e0b177f1a520e307e6866c320e2b6fca6bced4))

### Features

- purge asset URLs on rename, move, and deletion ([a5b4b6b](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/a5b4b6b1fe50f9ef2805dcf9f88321cef2b95211))
- purge asset URLs across multiple sites ([99a533f](https://github.com/jorisnoo/craft-cdn-asset-purge/commit/99a533fd784560be535876fcddc0bc7c9e7e8f46))

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
