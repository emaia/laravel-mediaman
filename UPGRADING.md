# Upgrading

This file lists the breaking changes per release and the migration steps. Apps already on the immediately preceding minor only need the section for the target version; apps jumping multiple minors need every intervening section.

For non-breaking additions, see [CHANGELOG.md](CHANGELOG.md).

---

## From v2.18 to v3.0

v3.0 consolidates the API surface and trims debt accumulated since v2. The changes below are the only required code edits for an app already running v2.18.

### 1. `PathGenerator` / `UrlGenerator` / `FileNamer` consolidated into `MediaResolver`

The three pluggable interfaces (one per concern) collapsed into a single `MediaResolver` because most customizations touched all three together. Method names also drop the `get` prefix to match Laravel idiom.

**Config rename.** Replace the `mediaman.generators.*` block:

```diff
- 'generators' => [
-     'path'       => CustomPathGenerator::class,
-     'url'        => CustomUrlGenerator::class,
-     'file_namer' => CustomFileNamer::class,
- ],
+ 'resolver' => CustomMediaResolver::class,
```

**Custom implementations.** Combine the three classes into one extending `DefaultMediaResolver`, override only the methods you actually customized:

```php
use Emaia\MediaMan\Resolvers\DefaultMediaResolver;
use Emaia\MediaMan\Models\Media;

class CustomMediaResolver extends DefaultMediaResolver
{
    public function directory(Media $media): string         // was getDirectory()
    {
        return 'tenants/'.tenant()->id.'/'.parent::directory($media);
    }

    public function url(Media $media, ?string $conversion = null): string  // was getUrl()
    {
        // your custom URL logic
    }
}
```

Method renames: `getDirectory → directory`, `getUrl → url`, `getBaseName → baseName`, `getTemporaryUrl → temporaryUrl`, `getConversionFileName → conversionFileName`, `getResponsiveFileName → responsiveFileName`.

If your custom resolver produces a directory shape that **does not match** `DefaultMediaResolver`'s `{id}-{md5}` pattern, also override `isManagedDirectory()` so `mediaman:clean` recognizes your shape as eligible for orphan cleanup (see [Conversions → Conversion disk](docs/conversions.md) for the contract).

### 2. `HasMedia::attachMedia` / `syncMedia` exception type

When a requested media id doesn't exist, attach/sync now throws `InvalidArgumentException` **before** any DB write happens. Previously the same condition produced a `QueryException` (FK violation) when foreign keys were enforced, and **silent orphan pivot rows** when they weren't.

```diff
  try {
      $product->attachMedia($mediaIds, 'gallery');
- } catch (QueryException $e) {
+ } catch (InvalidArgumentException $e) {
      // handle missing media id
  }
```

Apps that wrapped attach in `catch (Throwable)` don't need code changes — the new exception is caught too.

### 3. `PerformConversions` dispatch timing

The job is now dispatched **after** the attach pipeline commits, for both the legacy and rule-aware attach paths. Previously the legacy path dispatched up-front (which orphaned queued jobs if the attach itself later failed), and the rule paths swallowed queue-connection failures in a `catch (Throwable)`.

No code edits required for typical setups. Edge cases that may notice:

- Apps with custom queue connection error handlers may now see exceptions where they previously saw a silent `null` return from `syncMedia()`.
- Listeners on `MediaUploaded` that depend on conversions already having been dispatched run unchanged — dispatch order from caller's perspective is the same.

### 4. `mediaman.url.version_query` → `mediaman.url.versioning`

The boolean cache-busting key became a string enum.

```diff
  'url' => [
-     'version_query' => true,
+     'versioning' => 'timestamp',
      'prefix' => 'https://cdn.example.com',
  ],
```

Supported values: `false` (default) and `'timestamp'` (appends `?v={updated_at}`). The legacy `version_query` key is **no longer read** — apps that set it must rename explicitly.

Subclasses overriding the protected method rename it too:

```diff
- protected function applyVersionQuery(string $url, Media $media): string
+ protected function applyVersioning(string $url, Media $media): string
```

### 5. `MediaResolver::isManagedDirectory()` interface addition

Custom resolvers that extend `DefaultMediaResolver` inherit the default implementation (matches the `{id}-{md5}` shape). Custom resolvers that implement `MediaResolver` from scratch must add the method:

```php
public function isManagedDirectory(string $segment): bool
{
    return /* true if `$segment` could have been produced by your `directory()`, false otherwise */;
}
```

Returning `false` always opts the resolver out of `mediaman:clean` orphan cleanup — safer than `true` if you're not sure your pattern catches every shape.

### 6. `ImageManipulator::manipulate()` signature: `void` → `array`

Per-conversion isolation introduced a return shape that callers can inspect — a failure on one conversion no longer cancels the rest of the batch.

Subclasses overriding the method must update the signature:

```diff
- public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): void
+ public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): array
  {
      // ...
+     return ['completed' => $completed, 'failed' => $failed];
  }
```

Return shape: `['completed' => string[], 'failed' => array<{conversion: string, exception: Throwable}>]`.

Callers that ignore the return value (most apps) need no change — the method still runs the conversions as before.

### 7. `ConversionFailed` event + `ConversionCompleted` semantic change

Apps that monitored `failed_jobs` as a proxy for "some conversion in a batch failed" must migrate to a `ConversionFailed` listener. `failed_jobs` now only catches the catastrophic all-failed case (the job re-throws to trigger Laravel's retry; only after retries exhaust does it land in `failed_jobs`).

```php
use Emaia\MediaMan\Events\ConversionFailed;

Event::listen(function (ConversionFailed $event) {
    // Reached here = the queue gave up. Act with confidence.
    AuditLog::create([
        'media_id'   => $event->media->id,
        'conversion' => $event->conversion,
        'error'      => $event->exception->getMessage(),
    ]);
});
```

`ConversionFailed` is a **final** signal — it fires only when the queue won't retry the conversion again (partial-batch failures fire immediately, all-failed defers to `failed()` after retries exhaust). Listeners can act decisively without racing the queue's own retry logic. See [Events → Conversion failures](docs/events.md#conversion-failures) for the firing-semantics table, sync queue caveat, and `reschedule()` helper.

`ConversionCompleted` now carries only the **successful** conversions (previously it carried the requested list verbatim). The event is omitted entirely when no conversion completes.

```diff
  Event::listen(function (ConversionCompleted $event) {
-     // $event->conversions was the requested list — could include silently-failed names
+     // $event->conversions is now strictly the conversions that actually wrote to disk
      foreach ($event->conversions as $name) {
          // ...
      }
  });
```

### 8. `media_url` / `media_uri` accessors route through `getUrl()`

The two appended attributes used to call `$filesystem->url($path)` directly, bypassing the configured `MediaResolver`. They now route through `getUrl()`, so they pick up `url.prefix` and `url.versioning` consistently with everything else (`$media->getUrl()`, `getPictureHtml()`, mail attachments).

```php
// On a media with url.prefix = 'https://cdn.example.com' and url.versioning = 'timestamp':
$media->media_uri;   // 'https://cdn.example.com/4-abc.../photo.jpg?v=1735574400'
$media->media_url;   // asset() applied — same when getUrl() is already absolute
```

**BC:** API responses and JSON payloads that surface either attribute (via `$media->toArray()`, Eloquent Resources, model serialization) now include the prefix and the version query when those features are configured. Consumers that previously got the raw disk URL must accept the prefixed form, or stop relying on the appended attribute and read `$media->getOriginalPath()` or `$media->disk` directly if they need the unprefixed value.

No migration step required when `url.prefix` and `url.versioning` are unset (defaults) — the accessor values are byte-for-byte the same as before.

### 9. Storage write failures throw instead of being swallowed

`MediaUploader::upload()` is now atomic: row + file write + collection attach run in a single `DB::transaction`. The previously-silent case where `Filesystem::putFileAs()` returned `false` (S3 permission denies, full disk, etc.) now raises `Emaia\MediaMan\Exceptions\MediaFileWriteFailed` and the transaction rolls back.

**BC:** apps that uploaded against a misconfigured disk used to end up with an orphan media row pointing at a file that was never written — silent corruption. Same code path now throws:

```php
try {
    $media = MediaUploader::source($request->file('file'))->upload();
} catch (\Emaia\MediaMan\Exceptions\MediaFileWriteFailed $e) {
    // Storage layer rejected the write. $e->path and $e->disk are public readonly.
    return back()->withError("Could not write to {$e->disk}.");
}
```

Inline responsive-image generation failures (`responsive_images.queue = false`) used to propagate; they now log via `Log::warning` and the upload returns the durable media. Apps that wrapped the upload in `try/catch` to handle responsive failures should switch to listening on the upload outcome (`MediaUploaded` event + check `$media->hasResponsiveImages()` later) or use the queued path so failures surface via `failed_jobs`.

### 10. `min_file_size` — 0-byte uploads rejected by default

A new `min_file_size` config rejects zero-byte uploads as `FileSizeExceeded`. Default `1` blocks empties — previously they created ghost media records pointing at empty files where downstream generators (placeholder, conversions, responsive) failed silently with nothing useful to recover.

```diff
  'allowed_mime_types' => [...],
  'max_file_size' => 10 * 1024 * 1024,
+ 'min_file_size' => 0,  // opt back into empty-file uploads (placeholder records, late binding, etc.)
```

### 11. SVG uploads disabled by default

`mediaman.svg.enabled` now defaults to `false`. SVG uploads throw `Emaia\MediaMan\Exceptions\SvgNotAllowed`. Apps that accept SVG must opt in **and** provide a sanitizer (no default — the choice is yours):

```diff
+ 'svg' => [
+     'enabled'   => true,
+     'sanitizer' => App\Security\EnshrinedSvgSanitizer::class,  // your impl of Emaia\MediaMan\Security\SvgSanitizer
+ ],
```

When `enabled = true` without a sanitizer configured, uploads still throw `SvgNotAllowed` (failsafe — silent passthrough of unsanitized SVG is not an option). See [Security → SVG uploads](docs/security.md#svg-uploads) for the recommended `enshrined/svg-sanitize` adapter.

Detection also tightened: SVG is caught via MIME OR `.svg` extension OR content marker (`<svg>` in the first 1KB) — covers the outdated-`finfo`-database bypass where SVGs starting with `<?xml ?>` get sniffed as `text/xml` while the webserver still serves them as `image/svg+xml`.

### 12. `disallowed_extensions` blocklist expanded

Defaults grew from 12 server-side entries to 24 — added shell-script + Windows-executable extensions (`sh`, `bash`, `zsh`, `py`, `rb`, `exe`, `com`, `msi`, `scr`, `bat`, `cmd`, `vbs`, `ps1`) for defense-in-depth. Not a server-execution risk on Unix Apache/Nginx but closes the surface against host-config drift.

Apps that **intentionally** accept any of the new entries must remove them from their published `disallowed_extensions`:

```diff
  'disallowed_extensions' => [
      'php', 'phtml', 'phar', ...
-     'py',  // remove if your app stores user-uploaded Python scripts
  ],
```

### 13. `MediaFormat::extensionFromMimeType()` return type widened to `?string`

Previously returned `'jpg'` as a silent fallback for unknown MIMEs, which let `ImageManipulator` write garbage bytes with a wrong-but-plausible extension. Now returns `null` — callers must handle it:

```diff
  $ext = MediaFormat::extensionFromMimeType($mime);
- $path = $base.'.'.$ext;  // silently 'jpg' on unknown MIME
+ if ($ext === null) {
+     throw new RuntimeException("Unsupported MIME for path derivation: $mime");
+ }
+ $path = $base.'.'.$ext;
```

`ImageManipulator` already handles the null internally (throws, caught by per-conversion isolation → `ConversionFailed`). Only app code that called the helper directly needs an edit.

### 14. `HttpDownloader` progress callback throws `FileSizeExceeded`

The size-overrun check during streamed downloads now throws `FileSizeExceeded` instead of `RuntimeException`. Matches the HEAD pre-check and the post-download check — single exception type for any size-related abort:

```diff
  try {
      $media = MediaUploader::fromUrl($url)->upload();
- } catch (RuntimeException $e) {
+ } catch (FileSizeExceeded $e) {
      // handle "remote file too large mid-download"
  }
```

### 15. `responsive_images.min_width` / `max_width` now actually clamp

The two keys were documented as global clamps but never read by the generator. Now filtered in `ResponsiveImageGenerator::generateResponsiveImages()` before encoding, regardless of which calculator produced the widths. Defaults (`320` / `2560`) match the previously-documented intent — most apps won't notice.

Apps with breakpoints outside the default range will see fewer variants generated. Either widen the clamps or set to `0` to disable on that side:

```diff
  'responsive_images' => [
+     'min_width'   => 200,
+     'max_width'   => 3840,
      'breakpoints' => [200, 320, 640, 1024, 1920, 3840],
  ],
```

### 16. `width_calculator` typos throw at boot

Unknown values now throw `InvalidArgumentException` instead of silently defaulting to `breakpoint`. Catches `file_size_optmized` and similar typos. No migration needed if your config is correct.

### 17. `fromUrl` records the sniffed MIME (not the remote Content-Type)

`MediaUploader::fromUrl()` previously derived the filename extension and the `UploadedFile`'s recorded MIME from the remote `Content-Type` header. A malicious server could lie about Content-Type to bypass the extension-based blocklist (return `image/jpeg` for actual PHP bytes — file lands as `download.jpeg`, blocklist misses it). Now derived from a `finfo` sniff of the downloaded bytes.

```php
// Before: $media->mime_type === 'image/jpeg' (remote claimed)
// After:  $media->mime_type === 'application/x-php' (sniffed from actual bytes)
```

`Log::warning` fires when the remote header disagrees with the sniff (carries `url`, `remote_mime`, `sniffed_mime`) — auditable trail for legitimate mismatches. Upload proceeds with the sniffed value (no throw).

### 18. `ImageManipulator` preserves source format on implicit encode

When a conversion closure returns `Image` (not `EncodedImage`), the output now uses the source MIME via `encodeUsingMediaType()`. Previously the driver picked a default — notable case: vips + AVIF source + `cover()` closure wrote `.heic` files because libheif chose HEVC as the default HEIF container codec.

```php
Conversion::register('square', fn (Image $i) => $i->cover(300, 300));
// vips + AVIF source:
// before: variant landed as .heic (wrong extension, broken downstream)
// after:  variant lands as .avif (source format preserved)
```

If the source MIME isn't encodable by the current driver (e.g. TIFF on a GD-only environment), the conversion fails via the existing per-conversion isolation (`ConversionFailed` event) — same handling as any other per-conversion failure.

### 19. PHP upload errors throw `UploadFailed` (was masked as `FileSizeExceeded`)

A file exceeding `upload_max_filesize` arrives at the framework as a 0-byte `UploadedFile` with `getError()` set to `UPLOAD_ERR_INI_SIZE`. Previously this masked as `FileSizeExceeded::belowMinimum(0, 1)` — technically true (0 < 1) but completely wrong about the cause. Same masking happened for any PHP-level error (`UPLOAD_ERR_PARTIAL`, `UPLOAD_ERR_NO_TMP_DIR`, etc.).

A new `validateUploadIntegrity()` runs first in the pipeline and throws `Emaia\MediaMan\Exceptions\UploadFailed` with the actual cause. The exception exposes `$e->phpUploadErrorCode` (one of `UPLOAD_ERR_*`) so apps can drive different UX per category:

```diff
  try {
      $media = MediaUploader::source($request->file('file'))->upload();
+ } catch (UploadFailed $e) {
+     // PHP-level error — apps may want different UX per code
+     return match ($e->phpUploadErrorCode) {
+         UPLOAD_ERR_INI_SIZE   => back()->withError('File too big (server limit).'),
+         UPLOAD_ERR_NO_TMP_DIR => /* ops alert — infrastructure broken */,
+         default               => back()->withError('Upload failed, try again.'),
+     };
  } catch (FileSizeExceeded $e) {
-     // could be "file too big" or "PHP upload broke" — no way to tell
+     // MediaMan size-policy rejection only (min_file_size / max_file_size)
  }
```

Apps that catch `FileSizeExceeded` for both cases must add the `UploadFailed` catch (or switch on `$e->phpUploadErrorCode === UPLOAD_ERR_INI_SIZE` to get the same "file too big" UX).

### 20. Non-raster media silently skipped by conversion + responsive pipelines

`PerformConversions::handle()`, `ResponsiveImageGenerator::generateResponsiveImages()`, and the four `mediaman:*` batch commands (`generate-conversions`, `clear-conversions`, `generate-responsive-images`, `clear-responsive`) now gate on `Media::isRasterImage()` and skip non-raster media (SVG, PSD, ICO, vector formats outside `MediaFormat::detectableFormats()`). Previously the queue dispatched anyway, Intervention threw, and the failure noised up `failed_jobs` after exhausting retries.

The gate also covers pre-existing SVG media uploaded before BC #11 (SVG disabled by default) — a legacy SVG attached to a channel with conversions no longer churns the queue.

Apps that monitored `failed_jobs` or listened to `ConversionFailed` to detect "this format isn't supported" must switch to:

```diff
  // Filter upfront via the new query scope
- Media::whereHas('mediable', ...)->get()
+ Media::raster()->whereHas('mediable', ...)->get();

  // Or check per-instance before dispatching custom logic
+ if ($media->isRasterImage()) {
+     // pipeline-eligible
+ }
```

---

## v2.0 – v2.12 catch-up (skip if already on v2.13+)

If you're upgrading from a pre-v2.7 install directly to v3.0, apply these schema additions in a custom migration before running v3 migrations. Releases that add columns to `mediaman_collections` or `mediaman_mediables` ship the schema inside the `create_mediaman_tables` stub for **fresh installs** only; existing installations must add them manually.

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

---

## v2.13 – v2.17 catch-up (skip if already on v2.18)

If you're upgrading from v2.12 or earlier directly to v3.0, apply these additional changes that shipped between v2.13 and v2.17.

### LQIP placeholder payload changed (v2.13.0, no schema change)

The LQIP placeholder stored in `custom_properties.placeholder` changed from a tiny JPEG data URI to an SVG wrapper data URI (zero CLS, works inside `<picture>`). The schema is unchanged, but media uploaded with v2.11 / v2.12 still hold the old JPEG payload. Re-upload affected media to refresh the placeholder; non-refreshed records keep rendering the JPEG inline as a degraded fallback.

### Console command class renames (v2.17.0)

Console class names dropped the redundant `Mediaman` prefix. The CLI signatures (`mediaman:clean`, `mediaman:doctor`, etc.) are unchanged — this only matters for code that references the FQCN:

```diff
- use Emaia\MediaMan\Console\Commands\MediamanCleanCommand;
+ use Emaia\MediaMan\Console\Commands\CleanCommand;
```

Affected classes: `MediamanCleanCommand`, `MediamanDoctorCommand`, `MediamanPublishCommand`, `MediamanPublishConfigCommand`, `MediamanPublishMigrationCommand`, `MediamanRotatePathsCommand`.

### `mediaman:generate-responsive --queue` flag flip (v2.17.0)

`--queue` is now an explicit boolean flag — passing it queues, omitting it processes inline. The previous fallthrough to `mediaman.responsive_images.queue` config is removed.

```diff
- php artisan mediaman:generate-responsive             # used the config default (often `true`)
+ php artisan mediaman:generate-responsive --queue     # explicit queue
+ php artisan mediaman:generate-responsive             # explicit inline
```

Scripts that passed `--queue=false` to force inline must drop the value (the flag is boolean now).

### `mediaman:clear-responsive --confirm` → `--force` (v2.17.0)

The skip-confirmation flag renamed to match Laravel's convention.

```diff
- php artisan mediaman:clear-responsive --confirm
+ php artisan mediaman:clear-responsive --force
```

### Soft-delete-aware file deletion (v2.17.1)

The `Media` model's `deleted` observer now skips file removal on a **soft delete** and only deletes on `forceDelete()` (or on a model without `SoftDeletes`). This only affects apps using a custom `Media` subclass with the `SoftDeletes` trait — previously, calling `$media->delete()` on a soft-deleted record wiped the underlying file, leaving `restore()` useless.

No migration required if you didn't subclass with `SoftDeletes`. If you did and **want** the old behavior, override the observer in your subclass.
