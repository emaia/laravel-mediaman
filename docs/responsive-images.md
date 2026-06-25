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
- [Responsive disk](#responsive-disk)

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

### Per-format quality

`quality` accepts a scalar (same value for every lossy format) or an array keyed by format. The array form must declare every lossy format in `formats`; PNG/GIF entries are accepted but ignored because their encoders don't take a quality parameter.

```php
$media = MediaUploader::source($request->file('file'))
    ->generateResponsive()
    ->withFormats(['avif', 'webp', 'jpg'])
    ->withQuality([
        'avif' => 50,  // AVIF can go aggressive — modern encoder, high efficiency
        'webp' => 85,
        'jpg'  => 80,
    ])
    ->upload();
```

Same shape works at the config level:

```php
'formats' => ['avif', 'webp', 'jpg'],
'quality' => ['avif' => 50, 'webp' => 85, 'jpg' => 80],
```

Missing entries fail loud at generation time (`InvalidArgumentException`) so a typo can't silently fall back to a default.

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
    <source type="image/webp" srcset="…/320.webp 320w, …/640.webp 640w, data:image/svg+xml,…%3Csvg…%3E 32w" sizes="…">
    <img
        src="…/image.jpg"
        srcset="…/320.jpg 320w, …/640.jpg 640w, data:image/svg+xml,…%3Csvg…%3E 32w"
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

Independent of the placeholder feature, `getPictureHtml()` and `getSimpleImgHtml()` now always set `width` and `height` on the `<img>` from the dimensions persisted at upload (`custom_properties.image_meta`) — so CLS is fixed even when LQIP is off.

`decoding="async"` is also set by default — the browser decodes the bitmap off the main thread, smoothing scroll/animation while images come in. Override it per call with `['decoding' => 'sync']` (or `'auto'`) if needed. We deliberately do **not** default `loading="lazy"`: applying it to above-the-fold images defers their fetch and hurts LCP. Opt in per call when you know the image is below the fold:

```php
echo $media->getPictureHtml(['loading' => 'lazy']);
```

### Composing with the dominant color

`Media::getPlaceholderColor()` returns a hex CSS color sampled from the upload (~10 bytes). Stack it underneath the picture for an instant first paint — the color renders before any data URI decodes, the SVG LQIP swaps in next, then the responsive image lands:

```blade
<div style="background-color: {{ $media->getPlaceholderColor() }}">
    {!! $media->getPictureHtml() !!}
</div>
```

The combination — solid color → SVG blur → responsive image — gives three progressive paints with one persisted value each. Works inside email and other contexts where `<picture>` doesn't apply too: drop the picture call and the color alone is a decent skeleton.

### Choosing a placeholder generator

The default `BlurredSvgPlaceholder` works for most apps, but the package ships two lightweight alternatives. Swap via `mediaman.placeholder.generator`:

| Generator | Payload (typical) | Visual character | Best for |
|---|---|---|---|
| `BlurredSvgPlaceholder` (default) | ~3 KB | Photographic blur (real thumbnail of the source, smoothed) | Editorial / blog / portfolio — quality of the preview matters |
| `GeometricBlurPlaceholder` | ~2 KB (grid=4), ~6–8 KB (grid=8) | Stylized geometric blocks — you can see where the bright and dark regions live, but it never looks "photographic" | Performance-focused apps that still want a visible hint of composition; CSP-strict environments (no embedded binary) |
| `DominantColorPlaceholder` | ~150 B | Flat solid color (area-weighted average) | Galleries / grids with many thumbnails; bandwidth-sensitive contexts; fallback for when the LQIP isn't worth its own payload |

All three return the same `data:image/svg+xml,…` URI shape, so the rendering pipeline (`getPictureHtml` / `getSimpleImgHtml` / `getUrlOrPlaceholder`) is generator-agnostic.

```php
// config/mediaman.php
'placeholder' => [
    'enabled' => true,
    'generator' => Emaia\MediaMan\Placeholders\GeometricBlurPlaceholder::class,
    'grid_size' => 4,            // N×N color grid (GeometricBlur)
    'blur_std_deviation' => 20,  // feGaussianBlur intensity (GeometricBlur)
    // width / blur / quality apply to BlurredSvgPlaceholder (the default)
],
```

Need a custom strategy (BlurHash, ThumbHash, dominant color from a palette, etc.)? Implement `Emaia\MediaMan\Placeholders\PlaceholderGenerator` and bind it. See [API → Placeholders](api.md#placeholders).

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

## Responsive disk

Responsive variants are typically served on every page view (the `<picture>` element fetches multiple per render), so keeping them on a hot local disk while the original sits on durable cloud storage is a common pattern:

```php
// config/mediaman.php
'responsive_images' => [
    'disk' => 'public',   // null (default) → variants live on $media->disk
    // ...
],
```

When set, every responsive variant is written to and served from this disk regardless of where the originating media lives. Switching the value does **not** migrate existing files — run `mediaman:clean --disk=old-disk` to find leftovers, or regenerate the variants on the new disk.

`mediaman:doctor`, `mediaman:clean`, and `mediaman:rotate-paths` all probe/scan/rotate the responsive disk alongside the main and conversion disks.
