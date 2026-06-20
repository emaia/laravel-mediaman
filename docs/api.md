# API reference

[← Back to README](../README.md)

Public surface of the package, organized by class/trait. Each entry links back to the topic doc with examples.

- [Media](#media) — `Emaia\MediaMan\Models\Media`
- [MediaCollection](#mediacollection) — `Emaia\MediaMan\Models\MediaCollection`
- [MediaUploader](#mediauploader) — `Emaia\MediaMan\MediaUploader`
- [HasMedia trait](#hasmedia-trait) — `Emaia\MediaMan\Traits\HasMedia`
- [MediaChannel](#mediachannel) — `Emaia\MediaMan\MediaChannel`
- [ConversionRegistry](#conversionregistry) — `Emaia\MediaMan\ConversionRegistry`
- [ImageManipulator](#imagemanipulator) — `Emaia\MediaMan\ImageManipulator`
- [ResponsiveImageGenerator](#responsiveimagegenerator) — `Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator`
- [Generators](#generators) — `Emaia\MediaMan\Generators\*`
- [Placeholders](#placeholders) — `Emaia\MediaMan\Placeholders\*`
- [Downloaders](#downloaders) — `Emaia\MediaMan\Downloaders\*`
- [Support](#support) — `Emaia\MediaMan\Support\*`
- [Enums](#enums) — `Emaia\MediaMan\Enums\*`
- [Events](#events)
- [Exceptions](#exceptions)
- [Facades](#facades)

---

## Media

`Emaia\MediaMan\Models\Media` — see [Uploads](uploads.md), [Responsive images](responsive-images.md).

### Static / scope methods

| Signature                         | Description                |
|-----------------------------------|----------------------------|
| `Media::find($id)`                | Standard Eloquent.         |
| `Media::findByName(string $name)` | Lookup by `name`.          |
| `Media::destroy($id\|array)`      | Delete and clean up files. |

### URL & path

| Signature                                                                                    | Description                                                                                                                                                                   |
|----------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `getUrl(string $conversion = ''): string`                                                    | Public URL (with `url.prefix` and `url.version_query` if configured).                                                                                                         |
| `getUrlWithFallback(string $conversion = ''): string`                                        | Conversion URL or the original if missing.                                                                                                                                    |
| `getConversionUrl(string $conversion): ?string`                                              | Conversion URL or `null` if missing.                                                                                                                                          |
| `getPlaceholder(): ?string`                                                                  | LQIP placeholder data URI (SVG wrapper with original `viewBox` and an embedded tiny blurred JPEG) generated at upload time, or `null` if none was generated.                  |
| `getPlaceholderColor(): ?string`                                                             | Hex CSS color (`#rrggbb`) sampled at upload as the average of the source image. ~10 bytes, perfect for CSS skeleton backgrounds. `null` on non-image media.                   |
| `getUrlOrPlaceholder(string $conversion = ''): string`                                       | Single-URL helper for non-srcset contexts (email, JSON, OG tags, CSS): conversion URL when the file exists, else the LQIP placeholder data URI, else the original URL.        |
| `getTemporaryUrl(?DateTimeInterface $expiration = null, ?string $conversion = null): string` | Signed URL on cloud disks. Throws `TemporaryUrlNotSupported` otherwise.                                                                                                       |
| `getPath(string $conversion = ''): string`                                                   | Path on disk (with extension detection).                                                                                                                                      |
| `getOriginalPath(string $conversion = ''): string`                                           | Path on disk using `file_name` as-is.                                                                                                                                         |
| `getFullPath(string $conversion = ''): string`                                               | Absolute path on disk.                                                                                                                                                        |
| `getDirectory(): string`                                                                     | Base directory of this media.                                                                                                                                                 |
| `filesystem(): Filesystem`                                                                    | Filesystem instance for this media's disk.                                                                                                                                     |

### Conversions & responsive

| Signature                                                                | Description                               |
|--------------------------------------------------------------------------|-------------------------------------------|
| `hasConversion(string $conversion): bool`                                | Conversion file exists.                   |
| `generateResponsiveImages(array $options = []): void`                    | Generate responsive variants.             |
| `hasResponsiveImages(): bool`                                            | Any responsive variants exist.            |
| `getResponsiveImages(): Collection`                                      | Collection of variant descriptors.        |
| `getResponsiveUrl(?int $width = null, ?string $format = null): string`   | URL of the best (or specified) variant.   |
| `getResponsiveImageForWidth(int $width, ?string $format = null): ?array` | Variant descriptor for a width.           |
| `getResponsiveImagesByFormat(string $format): Collection`                | Variants in one format.                   |
| `getResponsiveImagesByFormatGrouped(): array`                            | Variants keyed by format.                 |
| `getAvailableResponsiveFormats(): array`                                 | Formats present (e.g. `['avif','webp']`). |
| `getBestResponsiveFormat(): ?string`                                     | `avif` > `webp` > `jpg` > `png`.          |
| `getSrcset(?string $format = null): string`                              | srcset string.                            |
| `getPictureHtml(array $attributes = [], ?string $sizes = null): string`  | `<picture>` element.                      |
| `getSimpleImgHtml(array $attributes = []): string`                       | Plain `<img>` element.                    |
| `getImageWidth(): ?int`                                                  | Original width in px.                     |
| `getImageHeight(): ?int`                                                 | Original height in px.                    |
| `clearResponsiveImages(): void`                                          | Remove variant files and metadata.        |
| `clearConversionFormatCache(): void`                                     | Reset internal conversion-format map.     |
| `hasResponsiveFormat(string $format): bool`                              | Whether responsive variants exist in a given format. |

### HTTP responses & mail

| Signature                                                        | Description                           |
|------------------------------------------------------------------|---------------------------------------|
| `toResponse(?string $conversion = null): StreamedResponse`       | Download response.                    |
| `toInlineResponse(?string $conversion = null): StreamedResponse` | Inline response.                      |
| `getStream(?string $conversion = null)`                          | Read stream resource. Caller closes.  |
| `mailAttachment(?string $conversion = null): Attachment`         | Build a `Illuminate\Mail\Attachment`. |
| `toMailAttachment(): Attachment`                                 | Attachable contract — original file.  |

### Custom properties

| Signature                                                | Description            |
|----------------------------------------------------------|------------------------|
| `hasCustomProperty(string $key): bool`                   |                        |
| `getCustomProperty(string $key, $default = null): mixed` | Supports dot notation. |
| `setCustomProperty(string $key, $value): self`           | Save afterwards.       |
| `forgetCustomProperty(string $key): self`                | Save afterwards.       |

### Cross-model operations

| Signature                                                     | Description                                              |
|---------------------------------------------------------------|----------------------------------------------------------|
| `copy(object $target, string $channel = 'default'): Media`    | Clone record + files; attach copy to target.             |
| `attachTo(object $target, string $channel = 'default'): self` | Re-attach existing Media to another model (no file ops). |

### Attribute helpers

| Signature                      | Description                |
|--------------------------------|----------------------------|
| `isOfType(string $type): bool` | e.g. `'image'`, `'video'`. |
| `replaceFileExtension(string $fileName, string $newExtension): string` | Swap extension in a filename. |
| `getExtensionFromMimeType(string $mimeType): string` | Resolve extension from MIME type. |

### Relationships

| Signature                                                      | Description                                                                                       |
|----------------------------------------------------------------|---------------------------------------------------------------------------------------------------|
| `collections(): BelongsToMany`                                 | Many-to-many with `MediaCollection`.                                                              |
| `attachCollections($collections): ?int`                        | Mirror of `MediaCollection::attachMedia`. Accepts Media or collection inputs by id/name/instance. |
| `detachCollections($collections): ?int`                        | Mirror of `MediaCollection::detachMedia`. Pass empty/null/bool to detach from all.                |
| `syncCollections($collections, bool $detaching = true): array` | Mirror of `MediaCollection::syncMedia`. Returns `['attached','detached','updated']`.              |

### Constants

| Name                        | Value                | Use                                       |
|-----------------------------|----------------------|-------------------------------------------|
| `DEFAULT_CHANNEL`           | `'default'`          | Default value for channel parameters.     |
| `CONVERSIONS_DIR`           | `'conversions'`      | Subdirectory holding conversions.         |
| `RESPONSIVE_DIR`            | `'responsive'`       | Subdirectory holding responsive variants. |
| `PROPERTY_IMAGE_META`       | `'image_meta'`       | Key in `custom_properties` for width, height, dominant color. |
| `PROPERTY_RESPONSIVE_IMAGES` | `'responsive_images'` | Key in `custom_properties` for responsive variant descriptors. |

---

## MediaCollection

`Emaia\MediaMan\Models\MediaCollection` — see [Collections](collections.md).

### Static

| Signature                                                                   | Description                                 |
|-----------------------------------------------------------------------------|---------------------------------------------|
| `MediaCollection::create(array $attrs)`                                     | Standard Eloquent.                          |
| `MediaCollection::findByName(string\|array $names, array $columns = ['*'])` | One model for string, Collection for array. |

### Fluent configuration

| Signature                              | Description                                                                   |
|----------------------------------------|-------------------------------------------------------------------------------|
| `singleFile(): self`                   | Shortcut for `onlyKeepLatest(1)`.                                             |
| `onlyKeepLatest(int $count): self`     | Cap items; auto-prune oldest on overflow.                                     |
| `acceptsMimeTypes(array $types): self` | MIME whitelist; supports wildcards (`image/*`). Empty/null = accept anything. |

> Setters mutate in memory only — call `->save()` to persist.

### Validation & prune

| Signature                           | Description                                             |
|-------------------------------------|---------------------------------------------------------|
| `validateMedia(Media $media): void` | Throws `MediaNotAcceptedByCollection` on MIME mismatch. |
| `enforceMaxItems(): void`           | Detach oldest media down to `max_items`.                |

### Pivot operations

| Signature                                           | Description                                            |
|-----------------------------------------------------|--------------------------------------------------------|
| `media(): BelongsToMany`                            | Media in this collection.                              |
| `attachMedia($media): ?int`                         | Accepts Media, id, name, or iterable of those.         |
| `detachMedia($media): ?int`                         | Same input shapes. Pass empty/null/bool to detach all. |
| `syncMedia($media, bool $detaching = true): ?array` | Replaces the set.                                      |

### Attributes

| Field                                 | Description                     |
|---------------------------------------|---------------------------------|
| `name` (string)                       |                                 |
| `max_items` (int, nullable)           | Auto-prune cap.                 |
| `allowed_mime_types` (json, nullable) | MIME whitelist.                 |
| `fallback_url` (string, nullable)     | Reserved (not currently wired). |
| `fallback_path` (string, nullable)    | Reserved (not currently wired). |

---

## MediaUploader

`Emaia\MediaMan\MediaUploader` — see [Uploads](uploads.md).

### Entry points

| Signature                                                                               | Description                                                                                                                                                                           |
|-----------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `MediaUploader::source(UploadedFile $file): self`                                       | Standard form-upload entry.                                                                                                                                                           |
| `MediaUploader::fromRequest(string $key = 'file', ?Request $request = null): self`      | Convenience for pulling a single file off the current request. Resolves the request from the container when not passed. Throws `InvalidArgumentException` when missing or multi-file. |
| `MediaUploader::fromDisk(string $path, string $disk): self`                             | Import from any Laravel filesystem disk.                                                                                                                                              |
| `MediaUploader::fromBase64(string $data, string $filename, ?string $name = null): self` | Raw base64 or data URI.                                                                                                                                                               |
| `MediaUploader::fromUrl(string $url): self`                                             | SSRF-guarded remote download. Requires ext-curl.                                                                                                                                      |

### Fluent setters

| Signature                                                  | Description                                   |
|------------------------------------------------------------|-----------------------------------------------|
| `useName(string)` / `setName`                              | Display name.                                 |
| `useFileName(string)` / `setFileName`                      | On-disk filename (sanitized via `FileNamer`). |
| `useCollection(string)` / `toCollection` / `setCollection` | Bundle into collection (created on demand).   |
| `useDisk(string)` / `toDisk` / `setDisk`                   | Target disk.                                  |
| `withCustomProperties(array)` / `useCustomProperties`      | Extra metadata stored as JSON.                |
| `allowMimeTypes(array)`                                    | Per-upload MIME whitelist (overrides global). |
| `maxFileSize(int $bytes)`                                  | Per-upload size cap; `0` disables.            |
| `generateResponsive(array $options = [])`                  | Trigger responsive generation.                |
| `withBreakpoints(array)`                                   | Responsive widths.                            |
| `withFormats(array)`                                       | Responsive output formats.                    |
| `withQuality(int)`                                         | Responsive quality.                           |

### Terminal

| Signature         | Description                                                             |
|-------------------|-------------------------------------------------------------------------|
| `upload(): Media` | Persist record, write file, attach to collection, emit `MediaUploaded`. |

---

## HasMedia trait

`Emaia\MediaMan\Traits\HasMedia` — see [Models](models.md).

### Relationship

| Signature              | Description                             |
|------------------------|-----------------------------------------|
| `media(): MorphToMany` | Polymorphic many-to-many with ordering. |

### Existence / retrieval

| Signature                                             | Description                                                         |
|-------------------------------------------------------|---------------------------------------------------------------------|
| `hasMedia(string $channel = 'default'): bool`         |                                                                     |
| `getMedia(?string $channel = 'default'): Collection`  | Ordered by `order_column` (NULLS LAST). `null` channel returns all. |
| `getFirstMedia(?string $channel = 'default'): ?Media` |                                                                     |
| `getLastMedia(?string $channel = 'default'): ?Media`  |                                                                     |
| `getMediaChannel(string $name): ?MediaChannel`        | Returns the configured channel object.                              |

### URL & path helpers

| Signature                                                                                     | Description                                    |
|-----------------------------------------------------------------------------------------------|------------------------------------------------|
| `getFirstMediaUrl(?string $channel = 'default', string $conversion = ''): string`             | Falls back to channel fallback URL when empty. |
| `getFirstMediaUrlWithFallback(?string $channel = 'default', string $conversion = ''): string` |                                                |
| `getFirstMediaConversionUrl(?string $channel = 'default', string $conversion = ''): ?string`  |                                                |
| `hasMediaConversion(?string $channel = 'default', string $conversion = ''): bool`             |                                                |
| `getFirstMediaPath(?string $channel = 'default', string $conversion = ''): string`            |                                                |
| `getLastMediaUrl(?string $channel = 'default', string $conversion = ''): string`              |                                                |
| `getLastMediaUrlWithFallback(?string $channel = 'default', string $conversion = ''): string`  |                                                |
| `getLastMediaConversionUrl(?string $channel = 'default', string $conversion = ''): ?string`   |                                                |
| `hasLastMediaConversion(?string $channel = 'default', string $conversion = ''): bool`         |                                                |
| `getLastMediaPath(?string $channel = 'default', string $conversion = ''): string`             |                                                |

### Mutations

| Signature                                                                                                                          | Description                                                                                     |
|------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------|
| `attachMedia($media, string $channel = 'default', array $conversions = [], ?int $order = null): ?int`                              | Count attached or null.                                                                         |
| `syncMedia($media, string $channel = 'default', array $conversions = [], bool $detaching = true, ?int $startOrder = null): ?array` | `['attached','detached','updated']`.                                                            |
| `detachMedia($media = null): ?int`                                                                                                 | Detach specific media (or all when `null`).                                                     |
| `clearMediaChannel(string $channel = 'default'): void`                                                                             | Detach all in a channel.                                                                        |
| `setMediaOrder(array $ids, string $channel = 'default'): void`                                                                     | Reorder positions in a transaction. Throws `InvalidArgumentException` if any id isn't attached. |
| `forgetMediaCache(?string $channel = null): self`                                                                                  | Invalidate the in-memory cache for a channel, or every channel when `null`. Fluent.             |

### Channel registration

| Signature                                     | Description                                                |
|-----------------------------------------------|------------------------------------------------------------|
| `registerMediaChannels(): void`               | Override on your model to declare channels. Called lazily. |
| `addMediaChannel(string $name): MediaChannel` | Register a channel. Public — can be called ad-hoc too.     |

---

## MediaChannel

`Emaia\MediaMan\MediaChannel` — declared via `HasMedia::addMediaChannel()`.

| Signature                                                         | Description                                                     |
|-------------------------------------------------------------------|-----------------------------------------------------------------|
| `performConversions(string ...$conversions): self`                | Conversions to run when media is attached.                      |
| `hasConversions(): bool`                                          |                                                                 |
| `getConversions(): array`                                         |                                                                 |
| `useFallbackUrl(string $url, ?string $conversion = null): self`   | Default URL when channel is empty (or per-conversion override). |
| `useFallbackPath(string $path, ?string $conversion = null): self` | Default absolute path when channel is empty.                    |
| `getFallbackUrl(?string $conversion = null): string`              |                                                                 |
| `getFallbackPath(?string $conversion = null): string`             |                                                                 |

---

## ConversionRegistry

`Emaia\MediaMan\ConversionRegistry` — global singleton for named image conversions. Register once, reuse across any model or channel. Also available via the `Conversion` facade.

| Signature                                    | Description                                      |
|----------------------------------------------|--------------------------------------------------|
| `register(string $name, callable $conversion): void` | Register a named conversion closure.              |
| `exists(string $name): bool`                 | Whether a conversion name is registered.         |
| `get(string $name): callable`                | Retrieve the conversion closure for a name.      |
| `getFormat(string $name): ?string`           | Detected output format (e.g. `'jpg'`, `'webp'`). |
| `all(): array`                               | All registered conversions keyed by name.         |

Format is auto-detected at registration time via reflection on the closure's return type. See [Conversions](conversions.md).

---

## ImageManipulator

`Emaia\MediaMan\ImageManipulator` — executes conversion closures via Intervention Image. Used by `PerformConversions` jobs and the `mediaman:generate-conversions` command.

| Signature                                                                   | Description                                                                 |
|-----------------------------------------------------------------------------|-----------------------------------------------------------------------------|
| `manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): void` | Run listed conversion closures against a media item. Skips existing files unless `$onlyIfMissing` is `false`. |

---

## ResponsiveImageGenerator

`Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator` — generates and clears responsive image variants for a media item. Also consumable directly to wire custom width calculators.

| Signature                                                                | Description                                           |
|--------------------------------------------------------------------------|-------------------------------------------------------|
| `generateResponsiveImages(Media $media, array $options = []): void`      | Generate responsive variants for a media item.        |
| `clearResponsiveImages(Media $media): void`                              | Remove all responsive variant files from disk.        |
| `setWidthCalculator(WidthCalculator $calculator): self`                  | Swap the width calculation strategy for this instance. |

See [Responsive images](responsive-images.md).

---

## Generators

Customize where files live and how URLs/filenames are produced — see [Configuration → Pluggable generators](configuration.md#pluggable-generators).

### `PathGenerator`

```php
interface PathGenerator
{
    public function getDirectory(Media $media): string;
    public function getPathForConversion(Media $media, string $conversion): string;
    public function getPathForResponsive(Media $media): string;
}
```

Default: `DefaultPathGenerator` — `{id}-{md5(id.app_key)}` layout.

### `UrlGenerator`

```php
interface UrlGenerator
{
    public function getUrl(Media $media, ?string $conversion = null): string;
    public function getTemporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string;
}
```

Default: `DefaultUrlGenerator` — applies `url.prefix` and `url.version_query` config. Strips scheme+host from absolute storage URLs before prefixing (S3+CDN setups). Temporary signed URLs are **not** prefixed or version-tagged.

### `FileNamer`

```php
interface FileNamer
{
    public function getBaseName(string $originalName): string;
    public function getConversionFileName(string $originalName, string $conversion, string $extension): string;
    public function getResponsiveFileName(string $originalName, int $width, string $format): string;
}
```

Default: `DefaultFileNamer` — sanitizes user input, keeps the original basename for conversions (extension swap only), uses `{basename}_{width}w.{format}` for responsive variants.

Bind any generator in a service provider:

```php
use Emaia\MediaMan\Generators\PathGenerator;

$this->app->bind(PathGenerator::class, MyTenantPathGenerator::class);
```

### `WidthCalculator`

Pluggable width calculation for responsive images. Two implementations ship out of the box; bind or configure via `mediaman.responsive_images.width_calculator`.

```php
interface WidthCalculator
{
    public function calculateWidthsFromFile(string $imagePath): Collection;
    public function calculateWidthsFromBinary(string $binary): Collection;
    public function calculateWidths(int $fileSize, int $width, int $height): Collection;
}
```

| Implementation                          | Config value       | Description                                                                 |
|-----------------------------------------|--------------------|-----------------------------------------------------------------------------|
| `BreakpointWidthCalculator` (default)   | `'breakpoint'`     | Derives widths from the configured `breakpoints` array. `setBreakpoints(array)` / `getBreakpoints(): array` for customisation. |
| `FileSizeOptimizedWidthCalculator`      | `'filesize'`       | Chooses widths so each responsive variant stays within a target byte budget. |

Swap via service container or config:

```php
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;

$this->app->bind(WidthCalculator::class, FileSizeOptimizedWidthCalculator::class);
```

See [Responsive images → Width calculators](responsive-images.md).

---

## Placeholders

### `PlaceholderGenerator`

```php
interface PlaceholderGenerator
{
    public function generate(string $sourcePath, int $width, int $height): ?string;
}
```

Three implementations ship out of the box; all three return the same `data:image/svg+xml,…` URI shape so the rendering pipeline stays generator-agnostic. See [Responsive images → Choosing a placeholder generator](responsive-images.md#choosing-a-placeholder-generator) for the trade-off table.

- **`BlurredSvgPlaceholder` (default)** — embeds a tiny blurred JPEG inside an SVG with the original `viewBox`. Photographic quality, ~3 KB. Knobs: `mediaman.placeholder.{width, blur, quality}`.
- **`GeometricBlurPlaceholder`** — resizes the source to N×N, emits one `<rect>` per cell, wraps the whole thing in `<filter><feGaussianBlur/></filter>`. Stylized blocks, ~2 KB at grid=4 (and bigger at higher grid sizes), pure ASCII so it works under strict CSP. Knobs: `mediaman.placeholder.{grid_size, blur_std_deviation}`.
- **`DominantColorPlaceholder`** — a single `<rect>` filled with the area-weighted average color. ~150 B, flat. No knobs.

All three return `null` on failure so the upload pipeline keeps going.

Swap via `mediaman.placeholder.generator` config or rebind the interface:

```php
use Emaia\MediaMan\Placeholders\PlaceholderGenerator;

$this->app->bind(PlaceholderGenerator::class, BlurHashPlaceholder::class);
```

---

## Downloaders

`Emaia\MediaMan\Downloaders\Downloader` — backs `MediaUploader::fromUrl()`.

```php
interface Downloader
{
    /**
     * @param array{host: string, port: int, ips: string[]}|null $resolved
     * @return array{path: string, mime: string, size: int}
     */
    public function download(string $url, string $destinationPath, ?array $resolved = null): array;
}
```

Default: `HttpDownloader` — Laravel HTTP client + Guzzle, with `CURLOPT_RESOLVE` IP pinning when `$resolved` is provided.

---

## Support

### `Emaia\MediaMan\Support\UrlGuard`

| Signature                                                                       | Description                                                        |
|---------------------------------------------------------------------------------|--------------------------------------------------------------------|
| `UrlGuard::check(string $url): void`                                            | Throws `UrlNotAllowed` for blocked URLs.                           |
| `UrlGuard::resolve(string $url): array{host: string, port: int, ips: string[]}` | Validates and returns resolved IPs for DNS-rebinding-safe fetches. |

See [Security → SSRF protection](security.md#ssrf-protection-for-remote-urls).

---

## Enums

`Emaia\MediaMan\Enums\MediaFormat` and `Emaia\MediaMan\Enums\MediaType`.

### `MediaFormat` (backed string enum)

Determines the output format of a conversion. Detected automatically via reflection at registration time.

**Cases:** `WEBP`, `AVIF`, `JPG`, `JPEG`, `PNG`, `GIF`, `BMP`, `TIFF`, `HEIC`, `HEIF`, `SVG`.

| Signature                                                     | Description                                               |
|---------------------------------------------------------------|-----------------------------------------------------------|
| `MediaFormat::mimeType(): string`                             | MIME type for the format (e.g. `'image/webp'`).          |
| `MediaFormat::extensionFromMimeType(string $mimeType): string` | Resolve the file extension from a MIME string.           |
| `MediaFormat::tryFromValue(string $value): ?self`             | Resolve from MIME string or format name.                  |
| `MediaFormat::responsiveFormats(): array`                     | Formats suitable for responsive variants (`WEBP`,`AVIF`,`JPG`,`PNG`). |
| `MediaFormat::preferredOrder(): array`                        | Modern-format priority ordered (`AVIF`,`WEBP`,`JPG`,`PNG`). |
| `MediaFormat::detectableFormats(): array`                     | Formats recognised by PHP's `getimagesize()`.              |

### `MediaType` (backed string enum)

Broad bucket classification used by `Media::isOfType()` and `$media->type` accessor.

**Cases:** `IMAGE`, `VIDEO`, `AUDIO`, `DOCUMENT`, `OTHER`.

---

## Events

| Class                                             | When                                              |
|---------------------------------------------------|---------------------------------------------------|
| `Emaia\MediaMan\Events\MediaUploaded`             | Right after `MediaUploader::upload()`.            |
| `Emaia\MediaMan\Events\MediaDeleted`              | Right after `Media::delete()`.                    |
| `Emaia\MediaMan\Events\ConversionCompleted`       | At the end of the conversion queued job.          |
| `Emaia\MediaMan\Events\ResponsiveImagesGenerated` | At the end of the responsive variants queued job. |

See [Events](events.md).

---

## Exceptions

All exceptions live under `Emaia\MediaMan\Exceptions`.

| Class                          | Source                                                            |
|--------------------------------|-------------------------------------------------------------------|
| `DisallowedExtension`          | Upload of a blocked extension.                                    |
| `FileSizeExceeded`             | File too large (per-upload or pre-decode).                        |
| `MimeTypeNotAllowed`           | MIME not in the configured whitelist.                             |
| `InvalidBase64Data`            | Malformed base64 or data URI in `fromBase64()`.                   |
| `UrlNotAllowed`                | URL rejected by `UrlGuard` (scheme, host, or resolved IP).        |
| `MediaNotAcceptedByCollection` | Collection-level MIME rejection.                                  |
| `TemporaryUrlNotSupported`     | Disk doesn't support `temporaryUrl()`.                            |
| `InvalidCopyTarget`            | Target of `Media::copy()` or `attachTo()` doesn't use `HasMedia`. |
| `InvalidConversion`            | Conversion not registered.                                        |

---

## Facades

| Facade                              | Backs                                                                           |
|-------------------------------------|---------------------------------------------------------------------------------|
| `Emaia\MediaMan\Facades\Conversion` | `Emaia\MediaMan\ConversionRegistry` — `Conversion::register('name', $closure)`. |
