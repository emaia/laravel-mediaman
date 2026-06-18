# Changelog

All notable changes to `emaia/laravel-mediaman` will be documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
