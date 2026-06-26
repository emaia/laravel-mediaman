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
- [SVG uploads](#svg-uploads)

**Per-feature configuration**
- [URL sources (for `fromUrl()`)](#url-sources-for-fromurl)
- [Base64 uploads](#base64-uploads)
- [Temporary URLs](#temporary-urls)
- [URL generation](#url-generation)
- [Placeholder](#placeholder)
- [Conversions disk](#conversions-disk)
- [Responsive images](#responsive-images)

**Customization**
- [Custom models](#custom-models)
- [Table names](#table-names)
- [Pluggable MediaResolver](#pluggable-mediaresolver)
- [Disk accessibility checks](#disk-accessibility-checks)

**Reference**
- [Configuration reference (all keys)](#configuration-reference-all-keys)

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

| Driver    | PHP extension | Composer package                           | Notes                                                                       |
|-----------|---------------|--------------------------------------------|-----------------------------------------------------------------------------|
| `vips`    | ext-vips      | `intervention/image-driver-vips` (suggest) | Highest throughput via libvips. Install the package and load the extension. |
| `imagick` | ext-imagick   | bundled with intervention/image            | Higher quality than gd, full color-space support.                           |
| `gd`      | ext-gd        | bundled with intervention/image            | Lightest, bundled in most PHP installations — the safe universal fallback.  |

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
'min_file_size'      => env('MEDIAMAN_MIN_FILE_SIZE', 1),                // bytes; 0 = allow empty uploads
```

`allowed_mime_types` supports wildcards (`image/*`). Out-of-bounds uploads (above `max_file_size` or below `min_file_size`) throw `FileSizeExceeded`. PHP-level upload failures (`upload_max_filesize` exceeded at the framework boundary, `partial`, `no_tmp_dir`) throw `UploadFailed` separately — see [Security → Minimum upload size](security.md#minimum-upload-size).

## Disallowed extensions

Block executable/server-side file extensions on upload (see [Security](security.md#disallowed-file-extensions)):

```php
'block_disallowed_extensions' => true,
'disallowed_extensions' => [
    // Server-side execution (Apache/Nginx interpret these when configured)
    'php', 'phtml', 'phar', 'shtml', 'htaccess',
    'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
    // Defense in depth: interpreter scripts + Windows-side executables
    'sh', 'bash', 'zsh', 'py', 'rb',
    'exe', 'com', 'msi', 'scr', 'bat', 'cmd', 'vbs', 'ps1',
],
```

## SVG uploads

```php
'svg' => [
    'enabled'   => env('MEDIAMAN_SVG_ENABLED', false),   // disabled by default
    'sanitizer' => null,                                 // FQCN of an Emaia\MediaMan\Security\SvgSanitizer
],
```

Disabled by default — SVG carries XSS risk via embedded `<script>` / `<foreignObject>`. When enabled, every upload routes through the configured `SvgSanitizer` before landing on disk. See [Security → SVG uploads](security.md#svg-uploads) for adapter recommendations and failure modes.

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

Apply a CDN prefix and/or cache-busting strategy to all generated URLs (see [API → MediaResolver](api.md#mediaresolver)):

```php
'url' => [
    'versioning' => false,  // false | 'timestamp' (appends ?v={updated_at})
    'prefix'     => null,   // e.g. 'https://cdn.example.com'
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

## Conversions disk

Default disk for conversion variants. When set, every `Conversion::register()` writes its output here unless the registration overrides it with its own `disk:` argument.

```php
'conversions' => [
    'disk' => env('MEDIAMAN_CONVERSIONS_DISK'),
],
```

Resolution order, most specific wins: per-conversion `disk:` argument → this config default → media's own disk. Typical use case: keep originals on a durable cloud disk (S3, GCS) while serving the hot, read-heavy variants from a faster local disk — without repeating `disk: 'X'` on every registration.

See [Conversions → Conversion disk](conversions.md#conversion-disk) for the full resolution rules and per-registration overrides.

## Responsive images

Responsive images are **opt-in** — no variants are generated unless you call `generateResponsive()` on an upload or set `auto_generate=true` here.

```php
'responsive_images' => [
    'enabled'          => env('MEDIAMAN_RESPONSIVE_ENABLED', true),
    'auto_generate'    => env('MEDIAMAN_RESPONSIVE_AUTO_GENERATE', false),
    'queue'            => env('MEDIAMAN_RESPONSIVE_QUEUE', true),
    'disk'             => env('MEDIAMAN_RESPONSIVE_DISK'),
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

],
```

| Option                                    | Default                        | Description                                                                                                                                                                                                                     |
|-------------------------------------------|--------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `enabled`                                 | `true`                         | Kill-switch. When `false`, explicit `generateResponsive()` no-ops.                                                                                                                                                              |
| `auto_generate`                           | `false`                        | Automatically generate on every image upload.                                                                                                                                                                                   |
| `queue`                                   | `true`                         | Queue generation jobs instead of processing inline.                                                                                                                                                                             |
| `disk`                                    | `null` (media's own disk)      | Disk for generated responsive variants. Same hot/cold tiering as `conversions.disk`. See [Responsive images → Responsive disk](responsive-images.md#responsive-disk).                                                           |
| `breakpoints`                             | `[320, 640, 1024, 1366, 1920]` | Widths (in px) to generate.                                                                                                                                                                                                     |
| `formats`                                 | `['webp']`                     | Output formats. Supported: `webp`, `avif`, `jpg`, `png`, `gif`, `heic`. Order determines `<source>` precedence in the rendered `<picture>`. Driver/codec support varies — run `php artisan mediaman:doctor` to surface gaps.    |
| `quality`                                 | `85`                           | Lossy encoder quality (1–100). Scalar applies to every lossy format, or pass an array keyed by format (`['avif' => 50, 'webp' => 85]`) — see [Responsive images → Per-format quality](responsive-images.md#per-format-quality). |
| `width_calculator`                        | `'breakpoint'`                 | `breakpoint` uses fixed widths; `file_size_optimized` selects widths based on file-size reduction                                                                                                                               |
| `min_width`                               | `320`                          | Images narrower than this won't generate a variant.                                                                                                                                                                             |
| `max_width`                               | `2560`                         | Widths above this are capped.                                                                                                                                                                                                   |
| `file_size_optimized.reduction_factor`    | `0.7`                          | File-size reduction multiplier per iteration (0–1).                                                                                                                                                                             |
| `file_size_optimized.min_width`           | `20`                           | Stop iterating when calculated width falls below this (px).                                                                                                                                                                     |
| `file_size_optimized.min_file_size_bytes` | `10240`                        | Stop when predicted file size falls below this (bytes).                                                                                                                                                                         |

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

You'll also need a custom migration with `uuid` columns instead of `bigIncrements`. Publish and adjust. The media key flows through both pivots, so change them too:

```php
// media table
$table->uuid('id')->primary();          // was: bigIncrements('id')

// mediaman_collection_media + mediaman_mediables
$table->foreignUuid('media_id');         // was: unsignedBigInteger('media_id')
```

The obfuscated storage path works unchanged — `DefaultMediaResolver::directory()` builds the directory from `$media->getKey()`, and `HasUuids` generates the key before the row is saved (and before the file is stored).

### Soft deletes

```php
use Emaia\MediaMan\Models\Media as BaseMedia;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends BaseMedia
{
    use SoftDeletes;
}
```

Add the `deleted_at` column to the media table in your custom migration (`$table->softDeletes();`).

File deletion is soft-delete aware: a soft delete (`$media->delete()`) keeps the files on disk so `restore()` stays functional, and **does not** fire the `MediaDeleted` event. The on-disk directory is removed only on a force delete (`$media->forceDelete()`) or on a model without the `SoftDeletes` trait.

#### Effect on `getMedia()` and channel relations

> **Soft-deleted media drop out of `getMedia()` automatically.** The `SoftDeletes` global scope filters the underlying relation query, so a soft-deleted record becomes invisible to `$model->getMedia('channel')`, `getFirstMedia()`, `getMediaUrl()`, and the rest of the `HasMedia` trait — even though the pivot row still references it. Use `withTrashed()` on a manual query (`Media::withTrashed()->whereIn('id', $ids)->get()`) when you need to surface trashed records explicitly. Restoring (`$media->restore()`) brings the record back into all channel queries automatically.

`HasUuids` and `SoftDeletes` compose — add both traits to the same custom model when you want UUID keys and soft deletes together.

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

## Pluggable MediaResolver

Path, URL, and filename generation live behind a single interface — `MediaResolver` — instead of the v2 trio (`PathGenerator` + `UrlGenerator` + `FileNamer`). One bind, one config key, one class to extend when you need to customize:

```php
'resolver' => \Emaia\MediaMan\Resolvers\DefaultMediaResolver::class,
```

Most customizations only touch one or two methods (tenant prefix on the directory, CDN URL strategy, custom conversion filename). Extend `DefaultMediaResolver` and override what you need; the rest stays default:

```php
namespace App\MediaMan;

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\DefaultMediaResolver;

class TenantMediaResolver extends DefaultMediaResolver
{
    public function directory(Media $media): string
    {
        return 'tenants/'.tenant()->id.'/'.parent::directory($media);
    }
}
```

```php
'resolver' => \App\MediaMan\TenantMediaResolver::class,
```

Or bind ad-hoc in any service provider:

```php
use Emaia\MediaMan\Resolvers\MediaResolver;

$this->app->singleton(MediaResolver::class, TenantMediaResolver::class);
```

See [API → MediaResolver](api.md#mediaresolver) for the interface.

## Disk accessibility checks

When you change a Media's `disk` or `file_name`, MediaMan moves/renames the underlying file. You can ask the package to verify the source and target disks are reachable before attempting the operation:

```php
'check_disk_accessibility' => env('MEDIAMAN_CHECK_DISK_ACCESSIBILITY', false),
```

**Pros:** spots a misconfigured or unreachable disk before a partial move happens.
**Cons:** adds a round-trip per mutation (small but measurable on cloud disks).

Leave off by default; flip on if you've been bitten by silent disk failures.

---

## Configuration reference (all keys)

Every key from `config/mediaman.php` in a single table. The prose sections above explain context, trade-offs, and worked examples — this table is the Ctrl-F surface. Long defaults (`disallowed_extensions`, etc.) are abbreviated; see the dedicated section for the full value.

| Key                                                         | Type                                  | Default                                        | Env var                             | Section                                                               |
|-------------------------------------------------------------|---------------------------------------|------------------------------------------------|-------------------------------------|-----------------------------------------------------------------------|
| `disk`                                                      | `string\|null`                        | `null` (Laravel default)                       | `MEDIAMAN_DISK`                     | [Storage disk](#storage-disk)                                         |
| `driver`                                                    | `string\|null`                        | `null` (auto: vips → imagick → gd)             | `MEDIAMAN_DRIVER`                   | [Image driver](#image-driver)                                         |
| `queue`                                                     | `string\|null`                        | `null` (Laravel default)                       | `MEDIAMAN_QUEUE`                    | [Queue](#queue)                                                       |
| `collection`                                                | `string`                              | `'Default'`                                    | —                                   | [Default collection](#default-collection)                             |
| `allowed_mime_types`                                        | `array`                               | `[]` (allow all)                               | —                                   | [Allowed MIME types and file size](#allowed-mime-types-and-file-size) |
| `max_file_size`                                             | `int`                                 | `0` (unlimited)                                | `MEDIAMAN_MAX_FILE_SIZE`            | [Allowed MIME types and file size](#allowed-mime-types-and-file-size) |
| `min_file_size`                                             | `int`                                 | `1` (block empty)                              | `MEDIAMAN_MIN_FILE_SIZE`            | [Allowed MIME types and file size](#allowed-mime-types-and-file-size) |
| `block_disallowed_extensions`                               | `bool`                                | `true`                                         | —                                   | [Disallowed extensions](#disallowed-extensions)                       |
| `disallowed_extensions`                                     | `string[]`                            | `['php', 'phtml', …]` (17 entries)             | —                                   | [Disallowed extensions](#disallowed-extensions)                       |
| `svg.enabled`                                               | `bool`                                | `false`                                        | `MEDIAMAN_SVG_ENABLED`              | [SVG uploads](#svg-uploads)                                           |
| `svg.sanitizer`                                             | `class-string\|null`                  | `null`                                         | —                                   | [SVG uploads](#svg-uploads)                                           |
| `url_sources.allow_private_hosts`                           | `bool`                                | `false`                                        | `MEDIAMAN_ALLOW_PRIVATE_HOSTS`      | [URL sources](#url-sources-for-fromurl)                               |
| `url_sources.timeout_seconds`                               | `int`                                 | `30`                                           | —                                   | [URL sources](#url-sources-for-fromurl)                               |
| `url_sources.max_size_bytes`                                | `int`                                 | `104857600` (100 MB)                           | —                                   | [URL sources](#url-sources-for-fromurl)                               |
| `url_sources.verify_ssl`                                    | `bool`                                | `true`                                         | `MEDIAMAN_VERIFY_SSL`               | [URL sources](#url-sources-for-fromurl)                               |
| `url_sources.user_agent`                                    | `string`                              | `'MediaMan/2.x'`                               | —                                   | [URL sources](#url-sources-for-fromurl)                               |
| `base64.max_size_bytes`                                     | `int`                                 | `52428800` (50 MB)                             | —                                   | [Base64 uploads](#base64-uploads)                                     |
| `temporary_url.default_lifetime_minutes`                    | `int`                                 | `5`                                            | —                                   | [Temporary URLs](#temporary-urls)                                     |
| `url.versioning`                                            | `false\|'timestamp'`                  | `false`                                        | `MEDIAMAN_URL_VERSIONING`           | [URL generation](#url-generation)                                     |
| `url.prefix`                                                | `string\|null`                        | `null`                                         | `MEDIAMAN_URL_PREFIX`               | [URL generation](#url-generation)                                     |
| `placeholder.enabled`                                       | `bool`                                | `false`                                        | `MEDIAMAN_PLACEHOLDER_ENABLED`      | [Placeholder](#placeholder)                                           |
| `placeholder.generator`                                     | `class-string`                        | `BlurredSvgPlaceholder::class`                 | —                                   | [Placeholder](#placeholder)                                           |
| `placeholder.blurred_svg.width`                             | `int`                                 | `32`                                           | —                                   | [Placeholder](#placeholder)                                           |
| `placeholder.blurred_svg.blur`                              | `int`                                 | `20`                                           | —                                   | [Placeholder](#placeholder)                                           |
| `placeholder.blurred_svg.quality`                           | `int`                                 | `40`                                           | —                                   | [Placeholder](#placeholder)                                           |
| `placeholder.geometric_blur.grid_size`                      | `int`                                 | `4`                                            | —                                   | [Placeholder](#placeholder)                                           |
| `placeholder.geometric_blur.blur_std_deviation`             | `int`                                 | `20`                                           | —                                   | [Placeholder](#placeholder)                                           |
| `conversions.disk`                                          | `string\|null`                        | `null` (media's own disk)                      | `MEDIAMAN_CONVERSIONS_DISK`         | [Conversions disk](#conversions-disk)                                 |
| `responsive_images.enabled`                                 | `bool`                                | `true`                                         | `MEDIAMAN_RESPONSIVE_ENABLED`       | [Responsive images](#responsive-images)                               |
| `responsive_images.auto_generate`                           | `bool`                                | `false`                                        | `MEDIAMAN_RESPONSIVE_AUTO_GENERATE` | [Responsive images](#responsive-images)                               |
| `responsive_images.queue`                                   | `bool`                                | `true`                                         | `MEDIAMAN_RESPONSIVE_QUEUE`         | [Responsive images](#responsive-images)                               |
| `responsive_images.disk`                                    | `string\|null`                        | `null` (media's own disk)                      | `MEDIAMAN_RESPONSIVE_DISK`          | [Responsive images](#responsive-images)                               |
| `responsive_images.formats`                                 | `string[]`                            | `['webp']`                                     | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.quality`                                 | `int\|array<string, int>`             | `85`                                           | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.width_calculator`                        | `'breakpoint'\|'file_size_optimized'` | `'breakpoint'`                                 | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.breakpoints`                             | `int[]`                               | `[320, 640, 1024, 1366, 1920]`                 | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.min_width`                               | `int`                                 | `320`                                          | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.max_width`                               | `int`                                 | `2560`                                         | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.file_size_optimized.reduction_factor`    | `float`                               | `0.7`                                          | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.file_size_optimized.min_width`           | `int`                                 | `20`                                           | —                                   | [Responsive images](#responsive-images)                               |
| `responsive_images.file_size_optimized.min_file_size_bytes` | `int`                                 | `10240`                                        | —                                   | [Responsive images](#responsive-images)                               |
| `models.media`                                              | `class-string<Media>`                 | `Emaia\MediaMan\Models\Media::class`           | —                                   | [Custom models](#custom-models)                                       |
| `models.collection`                                         | `class-string<MediaCollection>`       | `Emaia\MediaMan\Models\MediaCollection::class` | —                                   | [Custom models](#custom-models)                                       |
| `tables.media`                                              | `string`                              | `'mediaman_media'`                             | —                                   | [Table names](#table-names)                                           |
| `tables.collections`                                        | `string`                              | `'mediaman_collections'`                       | —                                   | [Table names](#table-names)                                           |
| `tables.collection_media`                                   | `string`                              | `'mediaman_collection_media'`                  | —                                   | [Table names](#table-names)                                           |
| `tables.mediables`                                          | `string`                              | `'mediaman_mediables'`                         | —                                   | [Table names](#table-names)                                           |
| `resolver`                                                  | `class-string<MediaResolver>`         | `DefaultMediaResolver::class`                  | —                                   | [Pluggable MediaResolver](#pluggable-mediaresolver)                   |
| `check_disk_accessibility`                                  | `bool`                                | `false`                                        | `MEDIAMAN_CHECK_DISK_ACCESSIBILITY` | [Disk accessibility checks](#disk-accessibility-checks)               |
