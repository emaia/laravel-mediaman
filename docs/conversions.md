# Conversions

[← Back to README](../README.md)

- [Register conversions globally](#register-conversions-globally)
- [Run conversions on a channel](#run-conversions-on-a-channel)
- [Retrieve a converted URL](#retrieve-a-converted-url)
- [Format detection](#format-detection)
- [Customize storage layout](#customize-storage-layout)

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

This means `$media->getUrl('thumb')` produces the correct extension whether the conversion outputs the original format or transcodes to WebP/AVIF/etc.

## Customize storage layout

The default `Emaia\MediaMan\Resolvers\DefaultMediaResolver` stores conversions under `{media_dir}/conversions/{conversion-name}/{file_name}`. Swap it for custom layouts (per-tenant, hash-based, etc.) — see [Pluggable MediaResolver](configuration.md#pluggable-mediaresolver) and [API → MediaResolver](api.md#mediaresolver).

Custom filenames (e.g., `photo-thumb.jpg` instead of `photo.jpg`) are controlled by overriding `MediaResolver::conversionFileName()`.
