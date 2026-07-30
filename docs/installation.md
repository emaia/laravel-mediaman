# Installation

[← Back to README](../README.md)

- [Requirements](#requirements)
- [Install via Composer](#install-via-composer)
- [Publish assets and migrate](#publish-assets-and-migrate)
- [Upgrading from an earlier version](#upgrading-from-an-earlier-version)
- [Next steps](#next-steps)

## Requirements

| Laravel | Package | PHP  |
|---------|---------|------|
| v12–v13 | 2.x–3.x | 8.3+ |

`fromUrl()` uploads require the **ext-curl** PHP extension.

## Install via Composer

```bash
composer require emaia/laravel-mediaman
```

Laravel auto-discovers the package. If auto-discovery is disabled, add the service provider to `config/app.php`:

```php
'providers' => [
    Emaia\MediaMan\MediaManServiceProvider::class,
],
```

## Publish assets and migrate

The fastest path is the one-shot `mediaman:publish` command — it publishes both the config and the migration in one go:

```bash
php artisan mediaman:publish
php artisan storage:link
php artisan migrate
php artisan mediaman:doctor
```

New uploads use Laravel's `public` disk by default, so `storage:link` makes `getUrl()` work without additional filesystem configuration. If the application needs private storage, S3, a CDN, or a dedicated media disk, configure `MEDIAMAN_DISK` before the first upload; the selected disk is persisted on every media record and changing the default later does not move existing files.

If you need to publish them separately (e.g. only re-publishing the config after a package update), the individual commands are still available:

```bash
php artisan mediaman:publish-config
php artisan mediaman:publish-migration
```

## Upgrading from an earlier version

See [UPGRADING.md](../UPGRADING.md) for v2.x → v3.0 instructions and the v3.0.2 public-disk default note, including the catch-up sections for older v2 installs (v2.0 – v2.12 schema additions, v2.13 – v2.17 console/queue/responsive flag changes).

## Next steps

- Run `php artisan mediaman:doctor` to verify the install — schema, disk, image driver, codecs, queue and security defaults all probed in one shot. See [Commands → Doctor](commands.md#doctor-health-check).
- [Configuration](configuration.md) — disk, queue, image driver, custom models, responsive images
- [Conversions](conversions.md) — register global image transformations applied per channel
- [Models & Channels](models.md) — `HasMedia` trait, channels, ordering, fallbacks
- [Responsive images](responsive-images.md) — multi-format variants and `<picture>` / `srcset` HTML
- [Uploads](uploads.md) — `MediaUploader` and its source variants
