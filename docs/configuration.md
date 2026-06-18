# Configuration

[← Back to README](../README.md)

MediaMan works out of the box. Everything in this page is optional — defaults match the most common Laravel app setup.

The config file is organized in four blocks: essentials, validation/security defaults, per-feature settings, and customization. The same grouping appears here.

**Essentials**
- [Storage disk](#storage-disk)
- [Image driver](#image-driver)
- [Queue](#queue)
- [Default collection](#default-collection)

**Validation & security defaults**
- [Allowed MIME types and file size](#allowed-mime-types-and-file-size)
- [Disallowed extensions](#disallowed-extensions)

**Per-feature configuration**
- [URL sources (for `fromUrl()`)](#url-sources-for-fromurl)
- [Base64 uploads](#base64-uploads)
- [Temporary URLs](#temporary-urls)
- [URL generation](#url-generation)
- [Placeholder](#placeholder)
- [Responsive images](#responsive-images)

**Customization**
- [Custom models](#custom-models)
- [Table names](#table-names)
- [Pluggable generators](#pluggable-generators)
- [Disk accessibility checks](#disk-accessibility-checks)

---

## Storage disk

`config('mediaman.disk')` falls back to `config('filesystems.default')` when null — set it explicitly to dedicate a separate disk to MediaMan, or leave it null to inherit Laravel's default. To wire up a separate disk:

```php
// config/filesystems.php
'disks' => [
    // …
    'media' => [
        'driver'     => 'local',
        'root'       => storage_path('app/media'),
        'url'        => env('APP_URL').'/media',
        'visibility' => 'public',
    ],
],

'links' => [
    // …
    public_path('media') => storage_path('app/media'),
],
```

```php
// config/mediaman.php
'disk' => 'media',
```

```bash
php artisan storage:link
```

MediaMan supports all of Laravel's storage drivers (Local, S3, SFTP, FTP, Dropbox, etc.).

## Image driver

MediaMan uses [intervention/image](https://github.com/Intervention/image) under the hood. The driver is **auto-detected** at boot in this order: `vips` → `imagick` → `gd`. Set the config (or env) explicitly to force one:

```php
'driver' => env('MEDIAMAN_DRIVER'), // null = auto-detect, 'vips', 'imagick', or 'gd'
```

| Driver    | PHP extension | Composer package                       | Notes                                                                         |
|-----------|---------------|----------------------------------------|-------------------------------------------------------------------------------|
| `vips`    | ext-vips      | `intervention/image-driver-vips` (suggest) | Highest throughput via libvips. Install the package and load the extension.   |
| `imagick` | ext-imagick   | bundled with intervention/image        | Higher quality than gd, full color-space support.                             |
| `gd`      | ext-gd        | bundled with intervention/image        | Lightest, bundled in most PHP installations — the safe universal fallback.    |

Auto-detection picks `vips` only when **all three** gates pass: `ext-vips` is loaded, `Intervention\Image\Drivers\Vips\Driver` resolves (i.e. the Composer package is installed), and a runtime probe (`new VipsDriver`) succeeds — the driver itself checks that libvips is reachable. Any failure falls through to imagick, then gd, silently. An explicit `MEDIAMAN_DRIVER=vips` with a broken libvips bubbles the original `MissingDependencyException` instead of falling back; we don't silence what the user asked for directly. An invalid driver name throws `InvalidArgumentException` at runtime.

```bash
composer require intervention/image-driver-vips
# then either set MEDIAMAN_DRIVER=vips or leave auto-detection on
```

## Queue

Image conversions and responsive image generation are dispatched as queued jobs:

```php
'queue' => 'media', // null = default queue
```

Make sure a worker is running:

```bash
php artisan queue:work
```

## Default collection

Uploads that don't specify a collection (via `useCollection()`) are bundled into a default one:

```php
'collection' => 'Default',
```

A collection with this name is seeded on first migration and used as the fallback target.

---

## Allowed MIME types and file size

```php
'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'], // empty = allow all
'max_file_size'      => 10 * 1024 * 1024,                                // 10 MB; 0 = unlimited
```

`allowed_mime_types` supports wildcards (`image/*`).

## Disallowed extensions

Block executable/server-side file extensions on upload (see [Security](security.md#disallowed-file-extensions)):

```php
'block_disallowed_extensions' => true,
'disallowed_extensions' => [
    'php', 'phtml', 'phar', 'shtml', 'htaccess',
    'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
],
```

---

## URL sources (for `fromUrl()`)

Settings for downloading remote URLs through `MediaUploader::fromUrl()`:

```php
'url_sources' => [
    'allow_private_hosts' => false,             // opt out of SSRF protection
    'timeout_seconds'     => 30,
    'max_size_bytes'      => 100 * 1024 * 1024, // 100 MB
    'verify_ssl'          => true,
    'user_agent'          => 'MediaMan/2.x',
],
```

See [Security](security.md#ssrf-protection-for-remote-urls) for the full block list of private/reserved IPs.

## Base64 uploads

Pre-decode size check for `fromBase64()`:

```php
'base64' => [
    'max_size_bytes' => 50 * 1024 * 1024, // 50 MB
],
```

## Temporary URLs

Default expiration for `Media::getTemporaryUrl()` when no explicit expiration is passed:

```php
'temporary_url' => [
    'default_lifetime_minutes' => 5,
],
```

## URL generation

Apply a CDN prefix and/or cache-busting query string to all generated URLs (see [API → DefaultUrlGenerator](api.md#urlgenerator)):

```php
'url' => [
    'version_query' => false, // append ?v={updated_at} for cache busting
    'prefix'        => null,  // e.g. 'https://cdn.example.com'
],
```

For absolute storage URLs (S3-style), the prefix correctly strips scheme+host before reapplying. Temporary signed URLs are **not** prefixed or version-tagged.

## Placeholder

Low-quality image placeholder (LQIP) — a tiny blurred JPEG generated synchronously on image upload, stored as a base64 data URI in `custom_properties.placeholder`. See [Media → Placeholder](media.md#placeholder-for-pending-conversions) for usage.

**Opt-in by default.** Set `enabled=true` (or `MEDIAMAN_PLACEHOLDER_ENABLED=true`) to turn it on:

```php
'placeholder' => [
    'enabled'   => env('MEDIAMAN_PLACEHOLDER_ENABLED', false),
    'generator' => Emaia\MediaMan\Placeholders\BlurredSvgPlaceholder::class,

    'blurred_svg' => [
        'width'   => 32,  // tiny JPEG width in px
        'blur'    => 20,
        'quality' => 40,  // JPEG quality (1-100)
    ],

    'geometric_blur' => [
        'grid_size'          => 4,   // N×N sampled grid
        'blur_std_deviation' => 20,  // feGaussianBlur intensity
    ],
],
```

When on, adds ~50 ms per image upload and ~3 KB to `custom_properties`. Generation only fires for `image/*` MIME types; failures (corrupt image, unsupported format) fall back to `null` without breaking the upload.

When off, `Media::getPlaceholder()` returns `null`, `getUrlOrPlaceholder()` behaves like `getUrl()`, and `getPictureHtml()`/`getSimpleImgHtml()` skip the srcset injection silently. Image meta (`width`, `height`, `dominant_color`) is still persisted at upload time, so the `<img>` keeps its `width`/`height` for zero CLS and `Media::getPlaceholderColor()` keeps returning a usable CSS color.

`generator` accepts any class implementing `Emaia\MediaMan\Placeholders\PlaceholderGenerator`. Three implementations ship out of the box: `BlurredSvgPlaceholder` (default — photographic, ~3 KB), `GeometricBlurPlaceholder` (stylized blocks, ~2 KB at grid=4, pure ASCII so CSP-friendly), and `DominantColorPlaceholder` (flat ~150 B). See [Responsive images → Choosing a placeholder generator](responsive-images.md#choosing-a-placeholder-generator).

**Per-generator tuning is scoped to its own sub-block.** `mediaman.placeholder.blurred_svg.{width, blur, quality}` only applies when the active `generator` is `BlurredSvgPlaceholder`; `mediaman.placeholder.geometric_blur.{grid_size, blur_std_deviation}` only applies to `GeometricBlurPlaceholder`. Swapping generators ignores knobs that don't belong to them — no silent reuse, no namespace collisions.

## Responsive images

Responsive images are **opt-in** — no variants are generated unless you call `generateResponsive()` on an upload or set `auto_generate=true` here.

```php
'responsive_images' => [
    'enabled'          => env('MEDIAMAN_RESPONSIVE_ENABLED', true),
    'auto_generate'    => env('MEDIAMAN_RESPONSIVE_AUTO_GENERATE', false),
    'queue'            => env('MEDIAMAN_RESPONSIVE_QUEUE', true),
    'breakpoints'      => [320, 640, 1024, 1366, 1920],
    'formats'          => ['webp'],
    'quality'          => 85,
    'width_calculator' => 'breakpoint', // or 'file_size_optimized'
    'min_width'        => 320,
    'max_width'        => 2560,

    'file_size_optimized' => [
        'reduction_factor'    => 0.7,
        'min_width'           => 20,
        'min_file_size_bytes' => 10240,
    ],

    'predefined_conversions' => [
        'responsive_custom_widths' => [400, 800, 1200],
        'responsive_hq_quality'    => 95,
    ],
],
```

| Option                                            | Default                        | Description                                                                                       |
|---------------------------------------------------|--------------------------------|---------------------------------------------------------------------------------------------------|
| `enabled`                                         | `true`                         | Kill-switch. When `false`, explicit `generateResponsive()` no-ops.                                |
| `auto_generate`                                   | `false`                        | Automatically generate on every image upload.                                                     |
| `queue`                                           | `true`                         | Queue generation jobs instead of processing inline.                                               |
| `breakpoints`                                     | `[320, 640, 1024, 1366, 1920]` | Widths (in px) to generate.                                                                       |
| `formats`                                         | `['webp']`                     | Output formats.                                                                                   |
| `quality`                                         | `85`                           | JPEG/WebP quality (1–100).                                                                        |
| `width_calculator`                                | `'breakpoint'`                 | `breakpoint` uses fixed widths; `file_size_optimized` selects widths based on file-size reduction |
| `min_width`                                       | `320`                          | Images narrower than this won't generate a variant.                                               |
| `max_width`                                       | `2560`                         | Widths above this are capped.                                                                     |
| `file_size_optimized.reduction_factor`            | `0.7`                          | File-size reduction multiplier per iteration (0–1).                                               |
| `file_size_optimized.min_width`                   | `20`                           | Stop iterating when calculated width falls below this (px).                                       |
| `file_size_optimized.min_file_size_bytes`         | `10240`                        | Stop when predicted file size falls below this (bytes).                                           |
| `predefined_conversions.responsive_custom_widths` | `[400, 800, 1200]`             | Widths for the `responsive-custom` conversion.                                                    |
| `predefined_conversions.responsive_hq_quality`    | `95`                           | Quality for the `responsive-hq` conversion.                                                       |

See [Responsive Images](responsive-images.md) for usage.

---

## Custom models

Swap the default `Media` and `MediaCollection` models with your own. Useful for extra fields, UUID primary keys, soft deletes, etc.

```php
namespace App\Models;

use Emaia\MediaMan\Models\Media as BaseMedia;

class Media extends BaseMedia
{
    // custom behavior
}
```

```php
namespace App\Models;

use Emaia\MediaMan\Models\MediaCollection as BaseMediaCollection;

class MediaCollection extends BaseMediaCollection
{
    // custom behavior
}
```

```php
'models' => [
    'media'      => App\Models\Media::class,
    'collection' => App\Models\MediaCollection::class,
],
```

### UUID primary keys

```php
use Emaia\MediaMan\Models\Media as BaseMedia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Media extends BaseMedia
{
    use HasUuids;
}
```

You'll also need a custom migration with `uuid` columns instead of `bigIncrements`. Publish and adjust.

## Table names

Each table can be renamed if it conflicts with something in your app:

```php
'tables' => [
    'media'            => 'mediaman_media',
    'collections'      => 'mediaman_collections',
    'collection_media' => 'mediaman_collection_media',
    'mediables'        => 'mediaman_mediables',
],
```

## Pluggable generators

Swap path, URL, and filename generation:

```php
'generators' => [
    'path'       => \Emaia\MediaMan\Generators\DefaultPathGenerator::class,
    'url'        => \Emaia\MediaMan\Generators\DefaultUrlGenerator::class,
    'file_namer' => \Emaia\MediaMan\Generators\DefaultFileNamer::class,
],
```

Or bind a custom implementation in any service provider:

```php
use Emaia\MediaMan\Generators\PathGenerator;

$this->app->bind(PathGenerator::class, MyTenantPathGenerator::class);
```

See [API → Generators](api.md#generators) for the interfaces.

## Disk accessibility checks

When you change a Media's `disk` or `file_name`, MediaMan moves/renames the underlying file. You can ask the package to verify the source and target disks are reachable before attempting the operation:

```php
'check_disk_accessibility' => env('MEDIAMAN_CHECK_DISK_ACCESSIBILITY', false),
```

**Pros:** spots a misconfigured or unreachable disk before a partial move happens.
**Cons:** adds a round-trip per mutation (small but measurable on cloud disks).

Leave off by default; flip on if you've been bitten by silent disk failures.
