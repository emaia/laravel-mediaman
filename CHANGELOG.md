# Changelog

All notable changes to `emaia/laravel-mediaman` will be documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- HEIC as a responsive output format. `MediaFormat::HEIC` joins `responsiveFormats()` and `preferredOrder()`, the responsive generator wires `Format::HEIC` into its encoder match, and `config('mediaman.responsive_images.formats')` now lists `heic` as a supported value. The format is opt-in (apps add `'heic'` to the `formats` array) and only encodes on drivers with libheif support — see the per-format isolation note below for what happens on drivers without it.
- Per-format encode isolation in the responsive generator. Each variant encode now runs inside its own `try`/`catch`, so a driver that cannot produce a given format (typical case: GD with `'avif'` or `'heic'` in the format list) **skips that variant with a `Log::warning` carrying `mediaId`/`format`/`width`/`error`** instead of aborting the whole responsive batch. Other widths and other formats continue uninterrupted. The benefit is transversal — apps mixing AVIF + WebP + JPG on a host with partial driver support now degrade gracefully per variant instead of losing the whole set on the first incompatible encode.
- `MediaChannel::acceptsFile()` — channel-level validation rules that run at attach time, not at upload. Rules stack with implicit AND, can be named for error reporting, and receive the `Media` instance plus optionally the owning model (detected once via reflection at registration). When every rule reads only media properties the trait stays on the legacy single-INSERT fast path; when any rule declares a `$model` parameter the trait switches to an aggregate path inside a `DB::transaction` with `lockForUpdate()` on the owning row, so concurrent batches and `count() < N` constraints behave correctly. Failed batches roll back atomically — nothing from the lot lands in the pivot, the in-memory channel cache is invalidated, and `PerformConversions` is not dispatched for rejected items. The Media record itself stays in the library so callers can re-attach it elsewhere or retry without re-uploading. See [Models → Channel validation rules](docs/models.md#channel-validation-rules).
- `MediaNotAcceptedByChannel` exception — carries `channel`, `rule` (`null` for anonymous rules), and `mediaId` (`int|string` to support UUID custom models) as public readonly props so controllers can `match ($e->rule)` directly instead of parsing the exception message.
- Per-conversion disk support via `Conversion::register('thumb', fn, disk: 'public')`. Variants for a registered conversion are stored on a different filesystem than the original — useful for hot/cold storage tiering (S3 originals, locally served conversions). Resolution chain (most specific wins): per-registration `disk:` arg → `mediaman.conversions.disk` config default → the media's own disk. URL, temporary URL, HTTP/inline response, mail attachment, `hasConversion`, format detection, and `Media::copy()` route through `Media::conversionFilesystem($conversion)` automatically. Delete observer cleans every disk a media's conversions live on (with same-disk dedup); `mediaman:clean` / `mediaman:doctor` / `mediaman:rotate-paths` cover the union of disks. Documented in [Conversions → Conversion disk](docs/conversions.md#conversion-disk). Fixes a latent bug along the way: `mediaman:clean` was filtering known-media directories by the disk currently being scanned, which flagged every conversion file on a conversion-only disk as orphan.
- Responsive image disk via `config('mediaman.responsive_images.disk')`. When set, responsive variants are written to and served from this disk regardless of where the originating media lives — symmetric with conversion disk and aimed at the same hot/cold storage pattern (variants are fetched on every page view by the `<picture>` element, so they're prime candidates for a hotter disk than the durable original). Falls back to the media's own disk when null (current behavior). Resolver is global rather than per-variant because responsive variants have no naming — they're defined by breakpoint and format. Documented in [Responsive Images → Responsive disk](docs/responsive-images.md#responsive-disk).
- `MediaUploader::fromString(string $content, string $fileName, ?string $name = null)` — upload from raw bytes already in memory. Useful for programmatic content (generated PDFs, headless screenshots, webhook payloads) that previously required a `tempnam()` dance at the call site. MIME type is sniffed from content via `finfo`, so the file name extension does not need to be accurate.
- `MediaUploader::fromStream($stream, string $fileName, ?string $name = null)` — upload from a readable PHP stream resource (`fopen`, PSR-7 `StreamInterface::detach()`, sftp/php wrappers, content piped from another process). The stream is buffered to a temp file; MIME type is sniffed from the buffered bytes. The caller remains responsible for closing the stream — `fromStream()` consumes the cursor but does not call `fclose()`.

### Fixed

- `mediaman:clean` no longer flags files outside MediaMan's directory pattern as orphans. Previously, scanning a disk shared with anything else (Laravel's own `storage/app/public/.gitignore`, README files at the disk root, directories from other apps that coexist on the same disk) treated every foreign file as an orphan candidate and, with `--force`, would delete it. Orphan recognition now delegates to the configured `MediaResolver` via the new `isManagedDirectory(string $segment): bool` method, so custom resolvers with non-default directory shapes (UUIDs, tenant prefixes, hash-based layouts) continue to recognize their own orphans. `DefaultMediaResolver` ships an implementation matching its `{id}-{md5}` shape. **BC:** custom `MediaResolver` implementations that did not extend `DefaultMediaResolver` must add the new method — return `true` for any directory shape the resolver could produce, or `false` always to opt out of orphan cleanup entirely.
- The responsive image encoder match no longer silently falls back to `Format::JPEG` for unrecognized format values. Previously, `formats: ['webp', 'heic']` on a driver without HEIC support — or any typo like `'jpg2'` — would silently emit JPEG bytes saved with the requested extension (`original-1024.heic` containing a JPEG payload, breaking the `<source type="image/heic">` it backed). The match now lists every supported format explicitly (`webp`, `avif`, `heic`, `jpg`/`jpeg`, `png`, `gif`) and throws `InvalidArgumentException` on anything else, where the per-format `try`/`catch` (above) catches it and skips that variant with a warning instead of writing garbage.
- Zero-byte encoder output is now treated as a failure. Some driver/format combinations succeed silently with an empty payload — most notably **imagick without the libheif delegate** when asked to encode HEIC, which returns an empty `EncodedImage` rather than throwing. The generator now checks the encoded length and raises `RuntimeException` when it is zero, which the per-format `try`/`catch` catches and surfaces as a `Skipping responsive format` warning. No more empty `.heic`/`.avif`/etc. files written to disk.

### Changed

- **`PathGenerator`, `UrlGenerator`, and `FileNamer` interfaces are consolidated into a single `MediaResolver`** under `Emaia\MediaMan\Resolvers`. Three binds, three config keys, and three classes worth of indirection collapse into one — most customizations touched all three together (changing the path implies changing the URL) so splitting them was indirection without flexibility. `DefaultMediaResolver` preserves v2 behavior bit-for-bit: same obfuscated `{id}-{md5(id.app_key)}` directory, same URL pipeline (prefix + version_query + S3 absolute-URL strip), same filename sanitization. Custom implementations now extend `DefaultMediaResolver` and override only the methods they touch. Method names also drop the `get` prefix (`getDirectory` → `directory`, `getUrl` → `url`, `getBaseName` → `baseName`, etc.) for parity with the Laravel idiomatic style. **BC:** apps that bound any of the old three interfaces — or set them via `mediaman.generators.{path,url,file_namer}` — must migrate to a `MediaResolver` implementation and update `mediaman.resolver`. See [Configuration → Pluggable MediaResolver](docs/configuration.md#pluggable-mediaresolver) and [API → MediaResolver](docs/api.md#mediaresolver).
- `HasMedia::attachMedia` / `syncMedia` now throw `InvalidArgumentException` when any requested media id does not exist, before any DB write happens. Previously the legacy and fast paths relied on a foreign key violation to surface this (`QueryException` when FKs were enforced, **silent orphan pivot rows** when they were not), and the aggregate path silently skipped missing ids. All three paths now share the same explicit pre-flight check and the same exception type — independent of the connection's FK configuration. **BC:** callers that catch `QueryException` for this case must switch to `InvalidArgumentException`.
- `PerformConversions` dispatch moves to after the attach pipeline has committed. Previously it dispatched up-front in the legacy path, which would orphan queued jobs if the attach itself later failed; and was caught by `syncMedia`'s `catch (Throwable)` in the rule paths, masking a successful attach as a silent `null` return when a queue connection error fired during dispatch. Now both paths dispatch after the transaction window for the requested set of ids (`attached + updated`), so a dispatch failure propagates as an exception rather than swallowing a committed attach.

## [2.18.0] — 2026-06-20

### Added

- `MediaPrunedFromCollection` event — dispatched by `MediaCollection::enforceMaxItems()` whenever the cap configured via `onlyKeepLatest()` / `singleFile()` causes older media to be detached. Carries `$event->collection` and `$event->detachedMediaIds` so listeners can record an audit entry, notify the owning user, or clean up downstream state. Auto-prune itself is unchanged — the Media records are still only detached, never deleted — the event just makes it observable. See [Collections → Auto-prune oldest](docs/collections.md#auto-prune-oldest).
- `HasMedia::forgetMediaCache(?string $channel = null): self` — public escape hatch to invalidate the in-memory media cache from outside the trait. The cache is cleared automatically by every mutation the trait owns (`attachMedia`, `syncMedia`, `detachMedia`, `setMediaOrder`, `clearMediaChannel`), but callers had no way to react when an external mutation — a queued job on the `sync` driver reusing the same model instance, a raw `DB::table()` insert, a sibling relation refresh — left the cache stale. Passing a channel clears that channel plus the all-channels snapshot (which would otherwise still include the channel's media); passing `null` clears every channel. Returns `$this` so it chains fluently. See [Models → Cache invalidation](docs/models.md#cache-invalidation).

## [2.17.2] — 2026-06-20

### Fixed

- `Casts\Json` no longer emits a PHP 8.3 `E_DEPRECATED` notice when `custom_properties` is `null`. The cast previously called `json_decode($value, true)` directly on the raw column value, and `json_decode(null, ...)` has been deprecated since PHP 8.1. Get and set now short-circuit on `null`, returning `null` in both directions so the column roundtrips cleanly. Apps reporting deprecations (Sentry, strict-mode logs, CI test suites) stop seeing noise on every fresh Media read.
- `Media::getCustomProperty($name, $default)` now accepts any default value type. The `$default` parameter was previously declared as `?string`, so any non-string default — including the array shapes the package itself persists under `image_meta` and `conversion_hashes` — raised a `TypeError` before reaching `Arr::get()`. The signature is now `getCustomProperty(string $name, mixed $default = null): mixed`, matching the actual storage contract.
- `HasMedia::syncMedia` no longer swallows domain and database exceptions. The `catch (Throwable)` block at the end of the method previously logged a warning and returned `null` for **every** failure, hiding `MediaNotAcceptedByCollection`, `QueryException` (deadlocks, constraint violations), and `InvalidArgumentException` behind a no-op result indistinguishable from "nothing to sync". Those three exception types now rethrow so callers can surface validation feedback, retry deadlocks, or fail fast on programmer error. Truly unexpected `Throwable`s keep the existing log-and-return-null behavior — the swallowing was the bug, not the logging. This unblocks the upcoming v3 `acceptsFile()` channel rules, whose `MediaNotAcceptedByChannel` would otherwise be silently absorbed here.
- `HasMedia::getMedia(null)` no longer poisons the `default` channel cache. The cache key was derived as `$channel ?? Media::DEFAULT_CHANNEL`, so `getMedia(null)` (which intentionally returns media across **all** channels) stored its unfiltered result under the `'default'` key. The next call to `getMedia('default')` then hit that cache and returned media from every channel instead of just the default one. The all-channels lookup is now keyed by an internal sentinel string that pivot data can never collide with, and `clearMediaCache($channel)` also invalidates that sentinel so detach/attach side effects do not leave a stale "all channels" snapshot behind.

## [2.17.1] — 2026-06-20

### Fixed

- File deletion is now **soft-delete aware**. The `Media` model's `deleted` observer fires on both soft and force deletes, so a custom `Media` subclass using Laravel's `SoftDeletes` previously had its on-disk directory wiped on a plain `$media->delete()` — leaving the soft-deleted record pointing at missing files and making `restore()` useless. The observer now skips file removal (and the `MediaDeleted` event) on a soft delete, and only deletes the directory on a force delete (`forceDelete()`) or on a model without soft deletes. The base `Media` model has no `SoftDeletes` trait, so its behavior is unchanged. See [Configuration → Custom models](docs/configuration.md#uuid-primary-keys).

## [2.17.0] — 2026-06-19

### Added

- `mediaman:generate-conversions` artisan command — generate (or regenerate) registered conversions for existing media. Required `--conversion=thumb,cover` lists one or more registered names (validated against the `ConversionRegistry`; unknown names short-circuit with a clear error). Optional `--media=1,3,5..10` filters by id (individual values and ranges), `--collection=avatars` filters by collection name. `--force` overwrites existing conversion files (default skips when the file is already on disk). `--queue` dispatches each item as a `PerformConversions` job instead of running synchronously. Confirmation prompt fires when the operation count (`media × conversions`) crosses 100. Output follows the doctor-style layout with summary counters (processed / skipped / failed). See [Commands → Generate conversions](docs/commands.md#generate-conversions).
- `mediaman:clear-conversions` artisan command — parity with `clear-responsive` for conversion files. Uses the same `--conversion` (required), `--media` (with range support), `--collection`, and `--force` flags. Deletes conversion directories from disk; no DB metadata to reset since conversions are filesystem-only. See [Commands → Clear conversions](docs/commands.md#clear-conversions).
- `mediaman:stats` artisan command — consolidated statistics command replacing the previous `mediaman:responsive-stats`. Without flags: media inventory (records, total size, image count), registered conversion names, and responsive coverage with config summary. `--responsive` shows the detailed responsive breakdown (total/with/without coverage, per-format configuration). `--conversions` shows each registered conversion with its detected output format. See [Commands → Stats](docs/commands.md#stats-consolidated).

### Fixed

- Performance regression in `MediaUploader::readImageMeta()` introduced in v2.13.0: every image upload unconditionally performed a full `ImageManager::decode()` + `resize(1,1)` to extract width, height, and dominant color. The `resize(1,1)` averages all pixels and is especially expensive for large images (banners, covers). Width and height are now extracted via PHP's native `getimagesize()` (header-only, sub-millisecond), and the expensive dominant-color decode runs only when `mediaman.placeholder.enabled` is `true`. Seeders and batch uploads return to v2.12.0 performance levels without losing any functionality.
- `UrlGuardTest` DNS-dependent tests now skip gracefully when `localhost.localdomain` does not resolve in the test environment (containers, CI).
- Pre-existing phpstan error in `Media::getTemporaryUrl()` resolved — removed redundant `method_exists()` guard in favor of direct `providesTemporaryUrls()` call.

### Changed

- `mediaman:generate-responsive` now uses the doctor-style output layout (section headers + `twoColumnDetail` rows). `--media` accepts ranges (`1..10`) and mixed lists (`1,3..5`), matching `generate-conversions`. `--queue` is now an explicit flag — passing it dispatches as queued jobs, omitting it processes inline. The previous fallthrough to `mediaman.responsive_images.queue` config is removed. Per-item log lines replaced by a summary (processed / failed). **BC note:** invocations that relied on the config default (typically `queue=true`) for queueing must now pass `--queue` explicitly; conversely, scripts that used `--queue=false` to force inline must drop the value (the flag is now boolean: present = queue, absent = inline). See [Commands → Generate responsive](docs/commands.md#generate-responsive).
- `mediaman:clear-responsive` now uses `--force` to skip the confirmation prompt instead of the previous `--confirm` flag (which sat inverted to Laravel's convention). Output migrated to doctor-style layout with summary counters. `--media` now accepts ranges (`1..10`) and mixed lists (`1,3..5`), matching `generate-conversions`/`generate-responsive`/`clear-conversions`. **BC note:** scripts passing `--confirm` must switch to `--force`.
- Console command class names dropped the redundant `Mediaman` prefix: `MediamanCleanCommand` → `CleanCommand`, `MediamanDoctorCommand` → `DoctorCommand`, `MediamanPublishCommand` → `PublishCommand`, `MediamanPublishConfigCommand` → `PublishConfigCommand`, `MediamanPublishMigrationCommand` → `PublishMigrationCommand`, `MediamanRotatePathsCommand` → `RotatePathsCommand`. The CLI signatures (`mediaman:clean`, `mediaman:doctor`, …) are unchanged. **BC note:** code that references these class names by FQCN (e.g. `app(MediamanCleanCommand::class)` or `extends MediamanCleanCommand`) must update.

### Removed

- The 5 predefined `responsive-*` conversions registered automatically by `ResponsiveConversions::register()` (`responsive`, `responsive-optimized`, `responsive-custom`, `responsive-webp`, `responsive-hq`), the `ResponsiveConversion` wrapper class, and the `mediaman.responsive_images.predefined_conversions` config block. These names were never documented anywhere in `docs/*` and were **non-functional** in practice: `ImageManipulator::manipulate()` does not branch on `ResponsiveConversion` instances, so calling any of them via `performConversions(...)` or `PerformConversions::dispatch(...)` silently produced no output and triggered no responsive variant generation. Use `MediaUploader::generateResponsive()->withBreakpoints()->withFormats()->withQuality()` at upload time or `$media->generateResponsiveImages($options)` on existing media — the documented, idiomatic, and actually-functional paths.
- `mediaman:responsive-stats` — replaced by the consolidated `mediaman:stats` command. Use `mediaman:stats --responsive` for the same detailed breakdown. **BC note:** scripts calling `responsive-stats` must switch to `stats --responsive`.

## [2.16.0] — 2026-06-18

### Added

- `mediaman:doctor` artisan command — read-only health check of the MediaMan pipeline (schema migrations, default disk write/read/delete probe, public symlink verification, effective image driver, queue connection + auto-generate consistency, registered conversions count, media inventory with total bytes and responsive coverage). Useful as a smoke test after deployment, after `APP_KEY` rotation, or while debugging "URL returns 404 but the record exists" issues. The symlink check matches `filesystems.links` entries against the disk's `root` and confirms each link path exists and points correctly — catches the classic post-install "I forgot to run `storage:link`" trap. Never mutates state. Exit code is `1` only on errors (schema missing, disk inaccessible, driver constructor fails, link path squatted by a real file); warnings (missing symlink, auto_generate without worker) keep exit code at `0`. See [Commands → Doctor (health check)](docs/commands.md#doctor-health-check).

## [2.15.0] — 2026-06-18

### Added

- Support for the `vips` driver from Intervention Image 4. Auto-detection now prefers `vips` → `imagick` → `gd` (previously `imagick` → `gd`); set `MEDIAMAN_DRIVER=vips` explicitly to force it. The driver lives in a separate Composer package — install `intervention/image-driver-vips` and make sure `ext-vips` is loaded. Listed under `suggest` in `composer.json` so consumers see it without it being a hard dependency. Auto-detect runs a runtime probe (`new VipsDriver`) in addition to the extension/package checks so a misconfigured libvips (driver throws `MissingDependencyException`) falls through to imagick/gd gracefully; an explicit `MEDIAMAN_DRIVER=vips` still bubbles the error.

## [2.14.0] — 2026-06-18

### Added

- **Two lightweight `PlaceholderGenerator` implementations** alongside the default `BlurredSvgPlaceholder`:
  - `DominantColorPlaceholder` — single area-weighted average color wrapped in a flat-fill SVG. ~150 bytes regardless of source size. Pure ASCII. Ideal for galleries, bandwidth-sensitive contexts, and CSS skeletons.
  - `GeometricBlurPlaceholder` — N×N color grid (default 4×4) sampled from the source, rendered as `<rect>`s under an `feGaussianBlur` filter. ~2 KB at grid=4 (regardless of source size); grid=8 (~6–8 KB) trades size for visual richness. Pure ASCII, CSP-friendly. Two new config knobs under their own sub-block: `mediaman.placeholder.geometric_blur.{grid_size, blur_std_deviation}`.
  - See [Responsive images → Choosing a placeholder generator](docs/responsive-images.md#choosing-a-placeholder-generator) for the trade-off table.

## [2.13.0] — 2026-06-18

### Added

- **Pluggable LQIP via `Emaia\MediaMan\Placeholders\PlaceholderGenerator`.** Swap via `mediaman.placeholder.generator` or rebind the interface (mirrors the v2.9 generators pattern). Default implementation `BlurredSvgPlaceholder` wraps a tiny blurred JPEG inside an SVG with the original `viewBox` and returns a percent-encoded `data:image/svg+xml,…` URI (~16% smaller than the equivalent base64 wrapper, readable in DevTools).
- Image meta (`width`, `height`, `dominant_color`) is now persisted in `custom_properties.image_meta` for every image upload in a single decode pass, independent of the placeholder feature. The struct was previously named `dimensions` and held only width/height.
- `Media::getPlaceholderColor(): ?string` — hex CSS color sampled at upload (average of the source). ~10 bytes, ideal as a `background-color` skeleton anywhere the LQIP data URI is too heavy: email, SSR, JSON APIs, container backgrounds. Composes naturally with `getPictureHtml()` for a three-stage progressive paint (color → SVG LQIP → responsive image).
- `Media::getUrlOrPlaceholder($conversion)` — single-URL helper for non-srcset contexts (email HTML, JSON payloads, OG/Twitter tags, CSS `background-image`). Returns the conversion URL when the file exists, the LQIP data URI as fallback, and finally the original URL.

### Changed

- `getPictureHtml()` always emits a `<picture>` wrapper, even when no responsive variants exist (`<picture><img></picture>`). Previously the method silently fell through to `getSimpleImgHtml()` and returned a bare `<img>` in that case — and also when only a single responsive format was configured (the default `formats=['webp']`), which left the rendered output without a `<picture>` despite the variants being there. Markup shape is now consistent across all states.
- `<source>` elements now cover **every** responsive format. Previously the last format was reserved for the inner `<img>` srcset, which mis-categorised single-format setups (e.g. WebP variants attached to a JPEG original) by mixing formats inside `<img srcset>`. The `<img>` always points at the original file now, with its native width as a single `srcset` candidate.
- `getSrcset()` filters out responsive entries with empty URL or zero width before assembling the string — degenerate data no longer surfaces as malformed `<source>` tags.
- **LQIP payload is an SVG wrapper instead of a raw JPEG.** The SVG `viewBox` pins the aspect ratio before any pixel data arrives, eliminating CLS, working inside `<picture>` (every `<source srcset>` now carries the placeholder), and removing the previous CSP friction from inline `style="background-image:…"` injection.
- `getPictureHtml()` and `getSimpleImgHtml()` always populate `width` and `height` on the `<img>` (from `custom_properties.image_meta`), not only with `sizes='auto'` — CLS is fixed even when LQIP is off. The `sizes='auto'` branch no longer overrides `width`/`height` with the smallest responsive variant.
- `decoding="async"` is now set by default on the rendered `<img>`. Override per call with `['decoding' => 'sync']`. `loading="lazy"` is **not** defaulted (it hurts LCP on above-the-fold images); opt in per call where appropriate.
- `getImageWidth()` / `getImageHeight()` read from `custom_properties.image_meta` first, then fall back to responsive variants, then lazy-decode.
- `Media::PROPERTY_DIMENSIONS` constant renamed to `Media::PROPERTY_IMAGE_META` (and the underlying key `dimensions` → `image_meta`) to make room for the additional fields. Pre-v2.13 records keep working — the lazy fallback re-populates the new key on first read.
- Placeholder config keys for the default generator moved to a `blurred_svg` sub-block: `mediaman.placeholder.{width, blur, quality}` → `mediaman.placeholder.blurred_svg.{width, blur, quality}`. Per-generator knobs are now scoped to their own namespace — swapping `generator` to a different implementation no longer silently reuses or ignores unrelated keys.
- `PlaceholderGenerator` service-container bind resolves the configured class lazily via a closure (instead of capturing the FQCN at register time). Apps and tests can swap the implementation via `Config::set('mediaman.placeholder.generator', …)` without having to call `app()->instance()` to force a rebind.

### Removed

- Inline `style="background-image:url('data:image/jpeg;…')"` injection in `getPictureHtml()` / `getSimpleImgHtml()`.

### Notes

- `getSimpleImgHtml()` is unchanged and remains the explicit escape hatch when callers need a bare `<img>` (email templates, etc.).

### Upgrading

Media uploaded with v2.11 / v2.12 still hold the old JPEG payload in `custom_properties.placeholder`. Re-upload affected media to refresh; non-refreshed records keep rendering the JPEG inline as a degraded fallback.

## [2.12.0] — 2026-06-17

### Added

- `mediaman:rotate-paths` artisan command — renames the on-disk media directories after an `APP_KEY` rotation. Iterates `Media` records, computes the path under the previous key vs the current key, and physically moves files when they differ. Dry-run by default; `--force` applies the moves; `--disk` and `--media` scope the operation; idempotent across re-runs. See [Security → APP_KEY rotation](docs/security.md#app_key-rotation) and [Commands → Rotate media paths after APP_KEY rotation](docs/commands.md#rotate-media-paths-after-app_key-rotation).

## [2.11.0] — 2026-06-17

### Changed

- `mediaman.driver` default is now `null` and **auto-detected** at boot — `imagick` when ext-imagick is loaded, `gd` otherwise. Previously hardcoded to `imagick`, which threw `InvalidArgumentException` at runtime on servers without ext-imagick. Existing installations that set `MEDIAMAN_DRIVER` explicitly are unaffected.
- `mediaman.disk` default is now `null` and **falls back to** `config('filesystems.default')`. Previously hardcoded to `'public'`. Existing installations that set the value explicitly are unaffected; in practice Laravel's default disk is also `'public'` in fresh apps, so behavior matches for the common case.

### Tooling

- CI now runs `phpstan` and `pint --test` jobs in parallel with the test matrix. Previously only pest ran on PRs.
- New `.github/workflows/release.yml` creates a GitHub Release automatically when a `v*` tag is pushed, extracting the matching section from `CHANGELOG.md`. The manual `gh release create --notes-file …` step is no longer needed.
- New `.github/PULL_REQUEST_TEMPLATE.md` prompts contributors to update the CHANGELOG and relevant docs.

### Added

- **LQIP placeholder is now a native (opt-in) feature.** Enable via `MEDIAMAN_PLACEHOLDER_ENABLED=true` to have image uploads generate a tiny blurred JPEG (~2 KB) stored as a base64 data URI in `custom_properties.placeholder`. New methods on `Media`:
  - `getPlaceholder(): ?string` — returns the data URI or null
  - `getUrlOrPlaceholder(string $conversion = ''): string` — returns conversion URL when the file exists, falls back to placeholder, then to the original URL. Useful right after upload when queued conversions have not run yet.
  - `getPictureHtml()` and `getSimpleImgHtml()` automatically inject the placeholder as a CSS background-image on the inner `<img>`. Opt out per call with `['placeholder' => false]`. Silent when no placeholder exists.
  - Configurable via `mediaman.placeholder` (enabled, width, blur, quality). Default off, matching the package convention of opt-in feature toggles (mirrors `responsive_images.auto_generate`). Only fires for `image/*` uploads; failures fall back to `null` without breaking the upload.
- `docs/recipes.md` — seven pluggable patterns for needs that MediaMan deliberately doesn't ship (image optimization, PDF/video thumbnails, SVG rasterization, ZIP downloads, multi-file uploads, string/stream uploads). Each recipe consumes the package's events + `custom_properties` + `PathGenerator` to slot cleanly into the existing pipeline.

## [2.10.0] — 2026-06-17

### Added

- `mediaman:publish` artisan command — publishes config and migration in one step. Individual `mediaman:publish-config` and `mediaman:publish-migration` remain for selective use.
- `MediaUploader::fromRequest($key = 'file', ?$request = null)` — convenience entry point for the most common case (pull a single file from the current HTTP request). The request is resolved from the container when not passed. Throws `InvalidArgumentException` when the field is missing or contains a multi-file array.

### Changed

- Split `README.md` into 12 topic-focused files under `docs/`. README is now a short index aligned with the package's core concepts.
- Reorganized `config/mediaman.php` into four labeled sections (essentials, validation/security defaults, per-feature configuration, customization) to mirror the doc layout. Existing published configs are not affected.
- Added per-file tables of contents to every `docs/*.md` page.

### Added (docs)

- `CHANGELOG.md` backfilled with entries for v2.2.0 → v2.9.0.
- `docs/api.md` — public API reference by class/trait.

## [2.9.0] — 2026-06-17

### Added

- **Pluggable `PathGenerator`, `UrlGenerator`, and `FileNamer`** interfaces under `Emaia\MediaMan\Generators` with bit-for-bit default implementations.
- `config('mediaman.generators.*')` to swap implementations; container singletons.
- `config('mediaman.url.prefix')` — CDN/origin prefix prepended to all generated URLs. Handles absolute storage URLs (S3-style) by stripping scheme+host before prefixing.
- `config('mediaman.url.version_query')` — append `?v={updated_at}` for cache busting.
- `FileNamer::getConversionFileName` and `getResponsiveFileName` wired into `Media`, `ImageManipulator`, and `ResponsiveImageGenerator` for full pluggability.

### Notes

- Temporary signed URLs are not prefixed or version-tagged (signatures cover expiration).

## [2.8.0] — 2026-06-17

### Added

- **Ordering** via `order_column` on `mediaman_mediables`.
  - `HasMedia::attachMedia($media, $channel, $conversions, ?int $order = null)` — optional position; auto-assigns `max(order_column)+1` when null.
  - `HasMedia::syncMedia(..., ?int $startOrder = null)` — replaces previous `bool $preserveOrder`.
  - `HasMedia::setMediaOrder(array $ids, $channel)` — batch reorder in a DB transaction. Throws `InvalidArgumentException` if any id isn't attached in the channel.
  - `media()` / `getMedia()` order by `order_column` with NULLS LAST semantics (cross-DB).
- **Channel fallbacks** via `MediaChannel::useFallbackUrl()` / `useFallbackPath()` with per-conversion overrides.
- **`Media::copy($target, $channel)`** — clones record + primary file + conversions + responsive variants; rolls back the DB record if any file copy fails. Cross-disk copies stream.
- **`Media::attachTo($target, $channel)`** — re-attach without touching disk (chainable).
- New exception `Emaia\MediaMan\Exceptions\InvalidCopyTarget`.

### Changed

- `HasMedia::addMediaChannel()` widened from `protected` to `public` for ad-hoc channel configuration.

### Breaking

- `HasMedia::syncMedia` 5th parameter changed type/name (`bool $preserveOrder` → `?int $startOrder`).
- `media()` / `getMedia()` now order results; code relying on insertion order may see different orderings.
- Existing installations must add `order_column` to `mediaman_mediables` via a custom migration before upgrading.

## [2.7.0] — 2026-06-17

### Added

- **Collection validation & auto-prune** via fluent setters on `MediaCollection`:
  - `singleFile()`, `onlyKeepLatest($n)`, `acceptsMimeTypes([...])`.
  - `validateMedia()` enforces MIME whitelist (supports wildcards like `image/*`); throws `MediaNotAcceptedByCollection`.
  - `enforceMaxItems()` auto-detaches oldest media when count exceeds `max_items` (Media records are never deleted).
- Validation and prune fire on **both** upload and direct attach paths.
- New columns on `mediaman_collections`: `max_items`, `allowed_mime_types`, `fallback_url`, `fallback_path`.

### Fixed

- `Media::collections()` had its `BelongsToMany` foreign/related pivot keys swapped — `$media->collections` now correctly returns the collections the media belongs to.

### Breaking

- Fresh installs get the new columns automatically via the updated `create_mediaman_tables` stub. Existing v2.6.0 installations must add columns via a custom migration before upgrading.

## [2.6.0] — 2026-06-16

### Added

- `Media::toResponse()` / `toInlineResponse()` (StreamedResponse for download / inline display).
- `Media::getStream()` returning a read stream resource (caller closes).
- `Media::getTemporaryUrl($expiration, $conversion)` with `providesTemporaryUrls()` capability detection; throws `TemporaryUrlNotSupported` on unsupported drivers.
- `Media::mailAttachment()` and `Media implements Attachable` — pass `$media` directly to `$mailable->attach()`.
- Complete `HasMedia::getLastMedia*` API mirroring `getFirstMedia*`.
- `temporary_url.default_lifetime_minutes` config (default 5).
- `TemporaryUrlNotSupported` exception.

### Changed

- README backfills a `Security` section covering extension blocking, SSRF guard, and `mediaman:clean`.

## [2.5.0] — 2026-06-16

### Added

- **Multi-source uploads** on `MediaUploader`:
  - `fromDisk(string $path, string $disk)` — preserves the source file on the original disk.
  - `fromBase64(string $data, string $filename, ?string $name)` — pre-decode size check (`base64.max_size_bytes`, default 50 MB).
  - `fromUrl(string $url)` — SSRF validation + `CURLOPT_RESOLVE` pinning to mitigate DNS rebinding.
- `Downloader` interface + `HttpDownloader` (Laravel HTTP client) — bindable in container for testing.
- Defense layers for `fromUrl`: HEAD `Content-Length` pre-check + in-stream size guard + post-download verification.
- `InvalidBase64Data` exception.

### Notes

- `fromUrl()` requires **ext-curl** (declared in `composer.json`).
- Uses the `url_sources` config block introduced in 2.3.0.

## [2.4.0] — 2026-06-16

### Added

- `mediaman:clean` artisan command — detect orphan files on disk and Media records with missing files.
  - Dry-run by default; `--force` deletes orphan files.
  - DB records are never auto-deleted (cascade safety).
  - `--disk` option scopes the scan.

## [2.3.0] — 2026-06-16

### Added

- **SSRF `UrlGuard`** under `Emaia\MediaMan\Support` — validates remote URLs before fetching.
  - Scheme allowlist (`http`, `https`).
  - IPv4 blocks: `0/8`, `10/8`, `127/8`, `169.254/16` (AWS/GCP metadata), `172.16/12`, `192.168/16`, `255.255.255.255`.
  - IPv6 blocks: `::`, `::1`, `fc00::/7`, `fe80::/10`, `::ffff:x.x.x.x`, `2002::/16`, `2001::/32`.
  - DNS resolution checks A and AAAA records.
- `url_sources` config block: `allow_private_hosts`, `timeout_seconds`, `max_size_bytes`, `verify_ssl`, `user_agent`.
- `UrlNotAllowed` exception.

### Notes

- Standalone utility in 2.3.0 — wired into uploads in 2.5.0.

## [2.2.0] — 2026-06-16

### Added

- **Block dangerous file extensions** on upload by default: `php`, `phtml`, `phar`, `shtml`, `htaccess`, `cgi`, `pl`, `asp`, `aspx`, `jsp`, `jspx`.
- `block_disallowed_extensions` and `disallowed_extensions` config options.
- `DisallowedExtension` exception.

### Notes

- The check runs against the **sanitized** filename so double-extension attacks like `legit.php.jpg` are defused before validation.

### Breaking

- Uploads with disallowed extensions now throw `DisallowedExtension`. Set `mediaman.block_disallowed_extensions = false` to restore previous behavior.

---

For releases v2.1.0 and earlier, see the [GitHub Releases page](https://github.com/emaia/laravel-mediaman/releases).
