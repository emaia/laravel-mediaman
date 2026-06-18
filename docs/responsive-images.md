# Responsive images

[← Back to README](../README.md)

- [Enabling](#enabling)
- [Generating for existing media](#generating-for-existing-media)
- [Inspecting variants](#inspecting-variants)
- [Getting a URL](#getting-a-url)
- [srcset and `<picture>` HTML](#srcset-and-picture-html)
- [Placeholder integration](#placeholder-integration)
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

`getPictureHtml()` always produces a `<picture>` element: one `<source>` per responsive format (modern formats first) plus an `<img>` fallback pointing at the original file. When no responsive variants exist the wrapper stays — `<picture><img></picture>` — so callers can rely on a single markup shape regardless of pipeline state.

```php
echo $media->getPictureHtml();

echo $media->getPictureHtml(['class' => 'hero-image', 'loading' => 'lazy']);

echo $media->getPictureHtml(['class' => 'hero-image'], '(max-width: 640px) 100vw, 50vw');

echo $media->getPictureHtml([], 'auto');  // sizes computed from breakpoints
```

Example output with two responsive formats and a JPEG original:

```html
<picture>
    <source type="image/avif" srcset="…/image-320.avif 320w, …/image-640.avif 640w, …" sizes="…">
    <source type="image/webp" srcset="…/image-320.webp 320w, …/image-640.webp 640w, …" sizes="…">
    <img src="…/image.jpg" alt="My image" srcset="…/image.jpg 1920w">
</picture>
```

Browsers that understand AVIF pick from the first `<source>`; older ones fall through to WebP; the `<img>` covers anything that supports neither. The `<img srcset>` exposes the original's native width as a single candidate so the fallback path is still width-aware.

Need a bare `<img>` without the wrapper (email templates, very constrained markup)? Use `getSimpleImgHtml()`:

```php
echo $media->getSimpleImgHtml(['class' => 'hero-image', 'loading' => 'lazy']);
// <img src="…/image.jpg" alt="My image" class="hero-image" loading="lazy">
```

## Placeholder integration

When the LQIP placeholder (see [Media → Placeholder](media.md#placeholder-for-pending-conversions)) was generated at upload time (the feature is opt-in), `getPictureHtml()` and `getSimpleImgHtml()` append it as the **smallest entry of every `srcset`** — including each `<source>` inside `<picture>`:

```html
<picture>
    <source type="image/webp" srcset="…/320.webp 320w, …/640.webp 640w, data:image/svg+xml;base64,… 32w" sizes="…">
    <img
        src="…/image.jpg"
        srcset="…/320.jpg 320w, …/640.jpg 640w, data:image/svg+xml;base64,… 32w"
        width="1280" height="720" alt="…"
    >
</picture>
```

The placeholder is a tiny blurred JPEG wrapped in an SVG with the original `viewBox`. That gives three properties at once:

- **Zero CLS** — the SVG `viewBox` pins the aspect ratio before any pixel data arrives, so the browser reserves the correct slot from the first paint.
- **Renders inside `<picture>`** — every `<source>` carries the placeholder in its srcset, so the format the browser picks already includes it.
- **CSP-friendly** — no inline `style` injection; the data URI lives in `srcset`, which only requires `img-src 'self' data:`.

The injection is silent when no placeholder exists (non-image upload, placeholder disabled in config, or generation failure). Opt out per call with `['placeholder' => false]`:

```php
echo $media->getPictureHtml();                          // includes the SVG entry (default)
echo $media->getPictureHtml(['placeholder' => false]);  // skip
echo $media->getSimpleImgHtml(['style' => 'border-radius:8px']); // your style is preserved untouched
```

Independent of the placeholder feature, `getPictureHtml()` and `getSimpleImgHtml()` now always set `width` and `height` on the `<img>` from the dimensions persisted at upload (`custom_properties.dimensions`) — so CLS is fixed even when LQIP is off.

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
