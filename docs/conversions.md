# Conversions

[← Back to README](../README.md)

- [Register conversions globally](#register-conversions-globally)
- [Run conversions on a channel](#run-conversions-on-a-channel)
- [Retrieve a converted URL](#retrieve-a-converted-url)
- [Format detection](#format-detection)
- [Customize storage layout](#customize-storage-layout)
- [Conversion disk](#conversion-disk)

A conversion is a transformation (resize, crop, format swap, watermark, …) applied to an image when it's attached to a channel. MediaMan uses [intervention/image](https://github.com/Intervention/image) under the hood — anything that library supports is fair game.

## Register conversions globally

Conversions are global, so the same `thumb` definition works across any model and channel. Register them in a service provider:

```php
namespace App\Providers;

use Emaia\MediaMan\Facades\Conversion;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Image;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Conversion::register('thumb', function (Image $image) {
            return $image->cover(64, 64);
        });

        Conversion::register('large', function (Image $image) {
            return $image->scaleDown(1600, null);
        });
    }
}
```

Refer to the [Intervention/Image v4 docs](https://image.intervention.io/v4) for the full transformation API.

## Run conversions on a channel

Wire conversions into a model channel via the `HasMedia` trait:

```php
use Emaia\MediaMan\Traits\HasMedia;

class Post extends Model
{
    use HasMedia;

    public function registerMediaChannels(): void
    {
        $this->addMediaChannel('gallery')
            ->performConversions('thumb', 'large');
    }
}
```

Now when media is attached to the `gallery` channel, both conversions run (queued — see [Configuration → Queue](configuration.md#queue)).

## Retrieve a converted URL

```php
// Helper: channel + conversion in one call
$post->getFirstMediaUrl('gallery', 'thumb');

// Or via a Media instance
$photos = $post->getMedia('gallery');
echo $photos[0]->getUrl('thumb');
```

> The `media_uri` and `media_url` attributes always point to the **original** file — they don't reflect conversions.

## Format detection

When a conversion changes format (e.g., converting JPG to WebP), MediaMan figures out the correct file extension automatically. You can mix detection strategies via the `MediaFormat` enum and `ConversionRegistry`:

1. **Pre-computed at registration time** (reflection-based)
2. **From the conversion name** — e.g., `thumb_webp` is detected as WebP
3. **Fallback by file existence** — checks each known image format
4. **From the source MIME** — predicts the canonical extension used by automatic encoding before a queued conversion exists (`image/jpeg` → `.jpg`, including `.jfif` uploads)

This means `$media->getUrl('thumb')` produces the correct extension whether the conversion outputs the original format or transcodes to WebP/AVIF/etc.

When a conversion deliberately fixes its output format, encode it explicitly so the URL is deterministic before the queued file exists:

```php
use Intervention\Image\Format;

Conversion::register('thumb', fn (Image $image) => $image
    ->cover(64, 64)
    ->encodeUsingFormat(Format::JPEG));
```

## Customize storage layout

The default `Emaia\MediaMan\Resolvers\DefaultMediaResolver` stores conversions under `{media_dir}/conversions/{conversion-name}/{file_name}`. Swap it for custom layouts (per-tenant, hash-based, etc.) — see [Pluggable MediaResolver](configuration.md#pluggable-mediaresolver) and [API → MediaResolver](api.md#mediaresolver).

Custom filenames (e.g., `photo-thumb.jpg` instead of `photo.jpg`) are controlled by overriding `MediaResolver::conversionFileName()`.

## Immutable-safe generation paths

Conversions keep their stable legacy paths by default. Opt into generation paths when the conversion subtree is served with long-lived immutable cache headers:

```dotenv
MEDIAMAN_CONVERSION_VERSIONING=generation
MEDIAMAN_CONVERSION_VERSION_RETENTION_DAYS=7
MEDIAMAN_CONVERSION_GENERATION_TIMEOUT_MINUTES=1440
```

Each conversion receives an independent ULID and is published only after its file exists:

```text
{media_dir}/conversions/thumb/01ARZ3NDEKTSV4RRFFQ69G5FAV/photo.webp
```

Forced regeneration writes a new directory and atomically switches persisted active metadata. The previous file remains available for cached pages until `mediaman:prune-conversion-generations --force` removes it after retention. Active generations and fresh in-progress work are never pruned.

Reads remain pinned to the published path and disk even if a registration is removed or its write disk changes. New generations use the current registration/configuration disk. Disable versioning and regenerate successfully before expecting legacy stable paths again.

Match the canonical ULID segment before sending immutable cache headers. Do not mark the complete `/conversions/*` subtree immutable because legacy conversion URLs remain stable:

```caddyfile
@versioned_conversions path_regexp conversions ^/media/[^/]+/conversions/[^/]+/[0-9A-HJKMNP-TV-Z]{26}/[^/]+$
header @versioned_conversions Cache-Control "public, max-age=31536000, immutable"
```

```nginx
location ~ ^/media/[^/]+/conversions/[^/]+/[0-9A-HJKMNP-TV-Z]{26}/[^/]+$ {
    add_header Cache-Control "public, max-age=31536000, immutable" always;
}
```

## Conversion disk

Conversions can opt into a different filesystem disk than the original — useful for hot/cold storage tiering (originals on S3, hot variants served from local). Resolution chain, most specific wins:

1. **Per-registration override** — `disk:` arg on `Conversion::register()`
2. **Config default** — `config('mediaman.conversions.disk')` covers every conversion that didn't declare its own
3. **Media's primary disk** — `$media->disk` (current behavior when nothing else is set)

```php
// All conversions land on 'public' unless overridden
// config/mediaman.php
'conversions' => ['disk' => 'public'],

// AppServiceProvider
Conversion::register('thumb', fn ($img) => $img->cover(64, 64));                    // → 'public'
Conversion::register('cover', fn ($img) => $img->cover(800, 600));                  // → 'public'
Conversion::register('archive', fn ($img) => $img->scaleDown(4096), disk: 's3-glacier'); // override
```

Or skip the config and use per-registration disks only — both styles work, the override always wins.

URLs, temporary URLs, HTTP responses, mail attachments, `mediaman:doctor`, and lifecycle commands respect the resolved active disk automatically.

> Changing the disk does not migrate files. Versioned active metadata remains readable on its persisted disk and future generations use the new disk. Use `mediaman:prune-conversion-generations --disk=old-disk` for retained generation cleanup; `mediaman:clean` intentionally does not treat nested files in valid media directories as orphans.
