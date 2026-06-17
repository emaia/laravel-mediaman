# Responsive images

[← Back to README](../README.md)

- [Enabling](#enabling)
- [Generating for existing media](#generating-for-existing-media)
- [Inspecting variants](#inspecting-variants)
- [Getting a URL](#getting-a-url)
- [srcset and `<picture>` HTML](#srcset-and-picture-html)
- [Clearing variants](#clearing-variants)
- [Width calculators](#width-calculators)

MediaMan generates multiple size and format variants of your images and exposes ready-to-use `srcset`/`<picture>` HTML so browsers can pick the most appropriate version. Variants live alongside the original file; metadata is persisted in `custom_properties`.

## Enabling

Responsive images are **opt-in by default**. No variants are generated unless you call `generateResponsive()` on an upload or set `auto_generate=true` globally.

There are two switches in `config('mediaman.responsive_images')`:

| Key             | Default | Effect                                                                  |
|-----------------|---------|-------------------------------------------------------------------------|
| `enabled`       | `true`  | Kill-switch. When `false`, even explicit `generateResponsive()` no-ops. |
| `auto_generate` | `false` | When `true`, runs automatically on every image upload.                  |

To auto-generate variants for every uploaded image:

```env
MEDIAMAN_RESPONSIVE_AUTO_GENERATE=true
```

To opt in per upload:

```php
$media = MediaUploader::source($request->file('file'))
    ->generateResponsive()
    ->upload();
```

Customise generation inline:

```php
$media = MediaUploader::source($request->file('file'))
    ->generateResponsive()
    ->withBreakpoints([480, 768, 1280])
    ->withFormats(['webp', 'avif'])
    ->withQuality(90)
    ->upload();
```

Or pass everything as an array:

```php
$media = MediaUploader::source($request->file('file'))
    ->generateResponsive([
        'widths'  => [480, 768, 1280],
        'formats' => ['webp', 'avif'],
        'quality' => 90,
    ])
    ->upload();
```

Config defaults live under `config('mediaman.responsive_images')` — see [Configuration → Responsive images](configuration.md#responsive-images).

## Generating for existing media

```php
$media = Media::find(1);
$media->generateResponsiveImages();

$media->generateResponsiveImages([
    'widths'  => [480, 768, 1280],
    'formats' => ['webp', 'avif'],
    'quality' => 90,
]);
```

Generation is queued by default (controlled by `responsive_images.queue`). Set `MEDIAMAN_RESPONSIVE_QUEUE=false` to generate synchronously.

## Inspecting variants

```php
$media->hasResponsiveImages();                      // bool
$media->getResponsiveImages();                      // Collection of image objects
$media->getAvailableResponsiveFormats();            // ['webp', 'avif', ...]
$media->hasResponsiveFormat('webp');                // bool
$media->getResponsiveImagesByFormat('webp');        // Collection
$media->getResponsiveImagesByFormatGrouped();       // array keyed by format
$media->getBestResponsiveFormat();                  // 'avif' > 'webp' > 'jpg' > 'png'
$media->getImageWidth();                            // int (original width in px)
$media->getImageHeight();                           // int (original height in px)
```

## Getting a URL

```php
$media->getResponsiveUrl();                         // best format, largest variant
$media->getResponsiveUrl(768);                      // best format, target width (nearest >= width)
$media->getResponsiveUrl(768, 'webp');              // explicit format + width
$media->getResponsiveImageForWidth(768, 'webp');    // the image object
```

## `srcset` and `<picture>` HTML

```php
$media->getSrcset();          // best format
$media->getSrcset('webp');    // specific format
```

`getPictureHtml()` produces a `<picture>` element with `<source>` tags per available format (modern formats first) and an `<img>` fallback. Without variants it falls back to a plain `<img>`.

```php
echo $media->getPictureHtml();

echo $media->getPictureHtml(['class' => 'hero-image', 'loading' => 'lazy']);

echo $media->getPictureHtml(['class' => 'hero-image'], '(max-width: 640px) 100vw, 50vw');

echo $media->getPictureHtml([], 'auto');  // sizes computed from breakpoints
```

Example output:

```html
<picture>
    <source type="image/avif" srcset="…/image-320.avif 320w, …/image-640.avif 640w, …" sizes="…">
    <source type="image/webp" srcset="…/image-320.webp 320w, …/image-640.webp 640w, …" sizes="…">
    <img src="…/image.jpg" alt="My image" srcset="…/image-320.jpg 320w, …" sizes="…">
</picture>
```

Simple `<img>` (no `<picture>`):

```php
echo $media->getSimpleImgHtml(['class' => 'hero-image', 'loading' => 'lazy']);
// <img src="…/image.jpg" alt="My image" class="hero-image" loading="lazy">
```

## Clearing variants

```php
$media->clearResponsiveImages();
```

Removes all variant files from storage and clears the metadata from `custom_properties`.

## Width calculators

Two strategies ship out of the box:

- **`breakpoint`** (default) — uses fixed widths from `responsive_images.breakpoints`
- **`file_size_optimized`** — iteratively reduces width until the predicted file size falls below a threshold

Pick via `responsive_images.width_calculator`. See [Configuration → Responsive images](configuration.md#responsive-images) for the full set of knobs.
