# Installation

[← Back to README](../README.md)

- [Requirements](#requirements)
- [Install via Composer](#install-via-composer)
- [Publish assets and migrate](#publish-assets-and-migrate)
- [Upgrading existing installations](#upgrading-existing-installations)
- [Next steps](#next-steps)

## Requirements

| Laravel | Package | PHP  |
|---------|---------|------|
| v12–v13 | 1.x–2.x | 8.3+ |

`fromUrl()` uploads (introduced in 2.5.0) also require the **ext-curl** PHP extension. It's declared in `composer.json` and Composer will refuse to install on systems without it.

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
```

If you need to publish them separately (e.g. only re-publishing the config after a package update), the individual commands are still available:

```bash
php artisan mediaman:publish-config
php artisan mediaman:publish-migration
```

## Upgrading existing installations

Releases that add new columns to `mediaman_collections` or `mediaman_mediables` ship the schema inside the `create_mediaman_tables` stub for **fresh installs**. Existing installations must add new columns manually via a custom migration before upgrading.

### v2.7.0 → adds to `mediaman_collections`

```php
Schema::table('mediaman_collections', function (Blueprint $table) {
    $table->integer('max_items')->nullable()->after('name');
    $table->json('allowed_mime_types')->nullable()->after('max_items');
    $table->string('fallback_url')->nullable()->after('allowed_mime_types');
    $table->string('fallback_path')->nullable()->after('fallback_url');
});
```

### v2.8.0 → adds to `mediaman_mediables`

```php
Schema::table('mediaman_mediables', function (Blueprint $table) {
    $table->integer('order_column')->nullable()->index();
});
```

### v2.13.0 → LQIP placeholder payload changed (no schema change)

The LQIP placeholder stored in `custom_properties.placeholder` changed from a tiny JPEG data URI to an SVG wrapper data URI (zero CLS, works inside `<picture>`). The schema is unchanged, but media uploaded with v2.11 / v2.12 still hold the old JPEG payload. Re-upload affected media to refresh the placeholder; non-refreshed records keep rendering the JPEG inline as a degraded fallback.

## Next steps

- [Configuration](configuration.md) — disk, queue, image driver, custom models, responsive images
- [Uploads](uploads.md) — `MediaUploader` and its source variants
- [Models & associations](models.md) — `HasMedia` trait, channels, ordering, fallbacks
