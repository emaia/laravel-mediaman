# Media

[← Back to README](../README.md)

- [Retrieve](#retrieve)
- [Attributes](#attributes)
- [URL and path methods](#url-and-path-methods)
- [HTTP responses](#http-responses)
- [Placeholder for pending conversions](#placeholder-for-pending-conversions)
- [Mail attachments](#mail-attachments)
- [Custom properties](#custom-properties)
- [Update](#update)
- [Copy and re-attach](#copy-and-re-attach)
- [Delete](#delete)
- [Cross-references](#cross-references)

`Emaia\MediaMan\Models\Media` is the central entity. Each row represents a file managed by the package — its identity, where it lives, what's attached to it, and any custom metadata you've stored alongside.

To create a Media row, see [Uploads](uploads.md). This page covers everything you do with one **after** it exists.

## Retrieve

Any Eloquent operation works, plus a `findByName()` helper:

```php
$media = Media::find(1);
$media = Media::findByName('media-name');
$media = Media::with('collections')->find(1);
```

## Attributes

```php
'id'                => int
'name'              => string
'file_name'         => string
'extension'         => string
'type'              => string
'mime_type'         => string
'size'              => int    // bytes
'friendly_size'     => string // "1.2 MB"
'media_uri'         => string // for asset()
'media_url'         => string // direct URL
'disk'              => string
'custom_properties' => array
'created_at'        => string
'updated_at'        => string
'collections'       => Collection<MediaCollection>
```

## URL and path methods

```php
$media->isOfType('image');                  // bool
$media->getUrl('thumb');                    // URL of conversion
$media->getUrlWithFallback('thumb');        // conversion URL or original
$media->getConversionUrl('thumb');          // null if missing
$media->hasConversion('thumb');             // bool
$media->getPath('thumb');                   // path on disk
$media->getOriginalPath('thumb');           // path using original file_name
$media->getFullPath('thumb');               // absolute path on disk
$media->getDirectory();                     // base directory
```

URL generation honors `config('mediaman.url.prefix')` and `config('mediaman.url.versioning')` — see [Configuration → URL generation](configuration.md#url-generation).

## HTTP responses

```php
return $media->toResponse();          // download (StreamedResponse)
return $media->toInlineResponse();    // inline (browser displays in-tab)
$stream = $media->getStream();        // raw stream resource — caller closes
```

All accept an optional conversion name (`$media->toResponse('thumb')`).

### Temporary URLs (S3 / cloud disks)

```php
use Emaia\MediaMan\Exceptions\TemporaryUrlNotSupported;

try {
    $url = $media->getTemporaryUrl(now()->addHour(), 'thumb');
} catch (TemporaryUrlNotSupported $e) {
    // local disk — fall back to a controller route
}
```

Default expiration via `temporary_url.default_lifetime_minutes` (5 min). Signed temporary URLs do **not** apply the `url.prefix` or `url.versioning` config.

## Placeholder for pending conversions

When the LQIP feature is enabled, MediaMan generates a tiny blurred JPEG, embeds it in an SVG with the original `viewBox`, and stores the percent-encoded data URI (~3 KB) in `custom_properties.placeholder`. The SVG wrapper pins the aspect ratio for zero CLS and lives inside `srcset` rather than as inline CSS. Useful in two scenarios:

- **Right after upload**, before the queued conversion job has finished, the conversion file doesn't exist yet on disk. Calling `getUrl('thumb')` would return a path to a missing file.
- **Lazy-loaded galleries**, where you want a fast-rendering preview while the real image downloads.

The feature is **opt-in** — enable it via [`placeholder` config](configuration.md#placeholder) or `MEDIAMAN_PLACEHOLDER_ENABLED=true`. Generated only for image uploads (`mime_type` starting with `image/`).

```php
$media->getPlaceholder();                 // 'data:image/svg+xml,...' or null
```

`getPictureHtml()` and `getSimpleImgHtml()` already do this for you — the placeholder is appended as the smallest entry of every `srcset` so the browser pins the aspect ratio (via the SVG `viewBox`) and surfaces the blurred preview while the real image is fetched. Opt out per call with `['placeholder' => false]`:

```php
echo $media->getPictureHtml();                          // includes background blur
echo $media->getPictureHtml(['placeholder' => false]);  // skip the blur
```

See [Responsive images → Placeholder integration](responsive-images.md#placeholder-integration).

### Dominant color helper

```php
$media->getPlaceholderColor(); // '#a1b2c3' — or null on non-image media
```

Hex CSS color sampled at upload as the average of the source image (~10 bytes, persisted in `custom_properties.image_meta`). Works as a CSS `background-color` skeleton wherever the LQIP data URI is too heavy: email, SSR, JSON APIs, container backgrounds. Stack it underneath `getPictureHtml()` for an instant first paint:

```blade
<div style="background-color: {{ $media->getPlaceholderColor() }}">
    {!! $media->getPictureHtml() !!}
</div>
```

### Single-URL helper for non-srcset contexts

`<picture>`/`srcset` don't apply to email HTML, JSON API payloads, OG/Twitter meta tags, or CSS `background-image`. For those, `getUrlOrPlaceholder()` returns one URL — the conversion if it exists, otherwise the LQIP data URI, otherwise the original:

```php
$media->getUrlOrPlaceholder('thumb');
// 1. "/storage/.../thumb.webp"            (conversion is on disk)
// 2. "data:image/svg+xml,%3Csvg…%3E"      (conversion still queued, LQIP enabled)
// 3. "/storage/.../original.jpg"          (no conversion, no LQIP)
```

```blade
{{-- Email template --}}
<img src="{{ $media->getUrlOrPlaceholder('thumb') }}" alt="{{ $media->name }}">

{{-- OG tag --}}
<meta property="og:image" content="{{ $media->getUrlOrPlaceholder('social') }}">

{{-- CSS background --}}
<div style="background-image: url('{{ $media->getUrlOrPlaceholder('hero') }}')"></div>
```

For rendering an actual `<img>` / `<picture>`, prefer `getPictureHtml()` / `getSimpleImgHtml()` — they carry the placeholder inside `srcset` and progressively swap to the real image without a caller-side fallback.

## Mail attachments

Media implements `Illuminate\Contracts\Mail\Attachable`:

```php
public function build()
{
    return $this->view('emails.welcome')
        ->attach(Media::find(1));                // original
        // or
        ->attach($media->mailAttachment('thumb')); // specific conversion
}
```

## Custom properties

Store arbitrary metadata alongside a media item:

```php
// read
$media->hasCustomProperty('caption');
$media->getCustomProperty('caption');
$media->getCustomProperty('missing', 'default');

// write
$media->setCustomProperty('caption', 'A picture');
$media->save();

// nested (dot notation)
$media->setCustomProperty('meta.author', 'Alice');
$media->getCustomProperty('meta.author');

// remove
$media->forgetCustomProperty('caption');
$media->save();
```

## Update

```php
$media->name = 'New display name';
$media->file_name = 'new_filename.jpg';   // also renames the file on disk
$media->disk = 's3';                       // also moves the file across disks
$media->custom_properties = [];            // clears
$media->save();
```

> **Heads up:** there's a `check_disk_accessibility` config that, when enabled, validates disk read/write before mutations. Tradeoff is some operational latency. See [Configuration → Disk accessibility checks](configuration.md#disk-accessibility-checks).

## Copy and re-attach

`Media::copy()` clones a Media record, copies the physical file (plus conversions and responsive variants), and attaches the copy to the target model. The original stays untouched.

```php
$copy = $media->copy($otherPost, 'featured');
```

If any file copy fails, the new Media DB record is rolled back. The target must use the `HasMedia` trait — otherwise `InvalidCopyTarget` is thrown. Cross-disk copies stream files (no full load into memory).

`Media::attachTo()` re-attaches the same Media to another model **without** touching disk:

```php
$media->attachTo($otherPost, 'gallery'); // chainable, returns $media
```

## Delete

```php
$media = Media::first();
$media->delete();

Media::destroy(1);
Media::destroy([1, 2, 3]);
```

When a Media instance is deleted, its file is removed from disk and all associations with App Models & MediaCollection are removed too.

> **Heads up:** do not delete via raw queries (`Media::where(...)->delete()`) — that bypasses the `deleted` event and the file stays on disk.

## Cross-references

- [Uploads](uploads.md) — how to create a Media record
- [Models & associations](models.md) — attach Media to models via `HasMedia`
- [Collections](collections.md) — group Media into reusable bundles
- [Conversions](conversions.md) — register image transformations applied to Media
- [Responsive images](responsive-images.md) — variants generated from Media
