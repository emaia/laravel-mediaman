<?php

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Models\MediaCollection;
use Emaia\MediaMan\Placeholders\BlurredSvgPlaceholder;
use Emaia\MediaMan\Resolvers\DefaultMediaResolver;

return [

    /*
    | Default disk for new uploads. When null, falls back to Laravel's
    | own default filesystem disk (see config/filesystems.php).
    */

    'disk' => env('MEDIAMAN_DISK'),

    /*
    | Image processing driver for intervention/image. Supported values:
    |
    |   "vips"    — libvips, fastest (requires ext-vips + intervention/image-driver-vips)
    |   "imagick" — ImageMagick (requires ext-imagick)
    |   "gd"      — GD library, universal fallback
    |
    | When null, the driver is auto-detected in the order above. Pin to a
    | specific value when you need a guarantee (CI, multi-host deployments).
    */

    'driver' => env('MEDIAMAN_DRIVER'),

    /*
    | Queue connection used for image conversions and responsive image
    | generation jobs. Leave null to use the application's default connection.
    */

    'queue' => env('MEDIAMAN_QUEUE'),

    /*
    | Default collection assigned to uploads that don't call useCollection().
    */

    'collection' => 'Default',

    /*
    | Global MIME allow-list for uploads. Empty array = accept everything.
    | Wildcards like `image/*` are supported.
    |
    | Examples:
    |   ['image/jpeg', 'image/png', 'application/pdf']
    |   ['image/*', 'application/pdf']
    */

    'allowed_mime_types' => [],

    /*
    | Maximum upload size in bytes. Set to 0 (default) to disable the check.
    | Enforced inside the package before the file is written to disk,
    | complementing PHP's own `upload_max_filesize` / `post_max_size`.
    |
    | Examples:
    |   5 * 1024 * 1024   // 5 MB
    |   100 * 1024 * 1024 // 100 MB
    */

    'max_file_size' => env('MEDIAMAN_MAX_FILE_SIZE', 0),

    /*
    | When true, the extensions in `disallowed_extensions` below are rejected
    | at upload time. Defaults to true — flip off only when you have a very
    | specific reason to accept server-executable file types.
    */

    'block_disallowed_extensions' => true,

    'disallowed_extensions' => [
        // Server-side execution (Apache/Nginx interpret these when configured)
        'php', 'phtml', 'phar', 'shtml', 'htaccess',
        'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
        // Defense in depth: interpreter scripts + Windows-side executables
        // (not a server-execution risk, but harmless to deny by default)
        'sh', 'bash', 'zsh', 'py', 'rb',
        'exe', 'com', 'msi', 'scr', 'bat', 'cmd', 'vbs', 'ps1',
    ],

    /*
    | Minimum upload size in bytes. Uploads below the threshold are rejected
    | as `FileSizeExceeded` (same exception family as max_file_size). Set to 0
    | to allow zero-byte uploads (placeholder records, late binding, etc.).
    | Default of 1 rejects empty files — the common "ghost record" failure mode.
    */

    'min_file_size' => env('MEDIAMAN_MIN_FILE_SIZE', 1),

    /*
    | Settings for `MediaUploader::fromUrl()` — downloading from remote URLs.
    */

    'url_sources' => [

        /*
        | SSRF guard. Leave false to reject URLs resolving to private or
        | reserved ranges (localhost, 127/8, 10/8, 172.16/12, 192.168/16,
        | 169.254/16). Flip to true only when you actually need to fetch
        | from an internal network and trust every URL passed in.
        */

        'allow_private_hosts' => env('MEDIAMAN_ALLOW_PRIVATE_HOSTS', false),

        /*
        | Maximum time (seconds) to wait for the remote response.
        */

        'timeout_seconds' => 30,

        /*
        | Maximum bytes downloaded before the request is aborted mid-stream.
        | Default is 100 MB.
        */

        'max_size_bytes' => 100 * 1024 * 1024,

        /*
        | Verify the remote's SSL certificate. Disable only for local dev or
        | internal APIs with self-signed certs — never in production.
        */

        'verify_ssl' => env('MEDIAMAN_VERIFY_SSL', true),

        /*
        | User-Agent header sent with the download request.
        */

        'user_agent' => 'MediaMan/2.x',

    ],

    /*
    | Settings for `MediaUploader::fromBase64()` — uploads from base64-encoded data.
    */

    'base64' => [

        /*
        | Maximum size of the raw base64 string (before decoding). The check
        | runs before any memory-intensive decode happens. Default is 50 MB.
        */

        'max_size_bytes' => 50 * 1024 * 1024,

    ],

    /*
    | Settings for `Media::getTemporaryUrl()` — short-lived signed URLs on
    | cloud disks that support them (S3, GCS, etc.).
    */

    'temporary_url' => [

        /*
        | Default expiration (minutes) used when `getTemporaryUrl()` is called
        | without an explicit expiration argument.
        */

        'default_lifetime_minutes' => 5,

    ],

    /*
    | Options applied to generated media URLs by DefaultMediaResolver.
    */

    'url' => [

        /*
        | Cache-busting strategy appended to generated URLs.
        | Supported: false | 'timestamp' (appends `?v={updated_at}`).
        */

        'versioning' => env('MEDIAMAN_URL_VERSIONING', false),

        /*
        | Optional CDN / origin prefix (e.g. 'https://cdn.example.com').
        | Absolute storage URLs (S3-style) are stripped down to the path
        | before the prefix is applied, so the same setting works for both
        | local and cloud disks.
        */

        'prefix' => env('MEDIAMAN_URL_PREFIX'),

    ],

    /*
    | Low-quality image placeholder (LQIP) — a tiny visual stand-in that
    | slots into the responsive `srcset` as the lowest-width entry. Pins
    | layout (zero CLS) and paints first, then swaps to the full-resolution
    | image once it loads. Generated synchronously on upload for image media
    | and stored as a data URI in `custom_properties`.
    |
    | Available generators (under `Emaia\MediaMan\Placeholders\`):
    |
    |   BlurredSvgPlaceholder     default. Tiny blurred JPEG wrapped in an SVG
    |                             carrying the original `viewBox`. Best preview
    |                             quality. Tuning: `blurred_svg` block below.
    |   DominantColorPlaceholder  solid SVG of the source's average color.
    |                             ~10 bytes over the wire; ideal for email or
    |                             SSR contexts where SVG blur is overkill. No
    |                             tuning.
    |   GeometricBlurPlaceholder  N×N color grid blurred via SVG `feGaussianBlur`.
    |                             Cheaper than the JPEG-in-SVG default but
    |                             coarser. Tuning: `geometric_blur` block below.
    |
    | Or set `generator` to your own class implementing `PlaceholderGenerator`.
    */

    'placeholder' => [
        'enabled' => env('MEDIAMAN_PLACEHOLDER_ENABLED', false),
        'generator' => BlurredSvgPlaceholder::class,

        'blurred_svg' => [
            'width' => 32,    // tiny JPEG width in px
            'blur' => 20,
            'quality' => 40,  // JPEG quality (1-100)
        ],

        'geometric_blur' => [
            'grid_size' => 4,             // N×N color grid sampled from the image
            'blur_std_deviation' => 20,   // feGaussianBlur intensity
        ],
    ],

    /*
    | Default disk for conversion variants. When set, every conversion
    | registered via `Conversion::register()` writes its output here unless
    | the registration overrides it with its own `disk:` argument.
    |
    | Resolution order, most specific wins:
    |
    |   per-conversion `disk:` argument  →  this config default  →  media's own disk
    |
    | Typical use case: keep originals on a durable cloud disk (S3, GCS)
    | while serving the hot, read-heavy variants from a faster local disk —
    | without having to repeat `disk: 'X'` on every register call.
    */

    'conversions' => [

        'disk' => env('MEDIAMAN_CONVERSIONS_DISK'),

    ],

    /*
    | Responsive image generation. The feature is opt-in: nothing is
    | generated unless you call generateResponsive() on an upload or
    | flip `auto_generate` on.
    */

    'responsive_images' => [

        /*
        | Master kill-switch. When false, the pipeline no-ops entirely, even
        | for explicit `generateResponsive()` calls.
        */

        'enabled' => env('MEDIAMAN_RESPONSIVE_ENABLED', true),

        /*
        | When true, every image upload generates responsive variants
        | automatically. Leave false to opt in per upload via
        | `MediaUploader::source(...)->generateResponsive()->upload()`.
        */

        'auto_generate' => env('MEDIAMAN_RESPONSIVE_AUTO_GENERATE', false),

        /*
        | Run generation as a queued job (true) or inline during the request
        | (false). Inline is convenient for local dev but blocks the response.
        */

        'queue' => env('MEDIAMAN_RESPONSIVE_QUEUE', true),

        /*
        | Disk for generated responsive variants. When null, variants share
        | the media's own disk.
        |
        | Same hot/cold tiering pattern as `conversions.disk` above —
        | originals on a durable cloud disk (S3, GCS), variants on a faster
        | local disk that the `<picture>` element hits on every page view.
        */

        'disk' => env('MEDIAMAN_RESPONSIVE_DISK'),

        /*
        | Output formats. Supported: webp, avif, jpg, png, heic. Order in
        | this array determines `<source>` precedence in the rendered `<picture>`.
        */

        'formats' => ['webp'],

        /*
        | Encoder quality (1–100) for the lossy formats. Higher = larger files
        | with better fidelity. 85 is a good sweet spot for WebP/JPEG/AVIF.
        */

        'quality' => 85,

        /*
        | Width-selection strategy. Available:
        |   "breakpoint"           — generate one variant per width in `breakpoints`.
        |   "file_size_optimized"  — iteratively shrink until the predicted
        |                            file size hits the floor in `file_size_optimized`.
        */

        'width_calculator' => 'breakpoint',

        /*
        | Default breakpoint widths (px) used by the `breakpoint` calculator.
        */

        'breakpoints' => [320, 640, 1024, 1366, 1920],

        /*
        | Hard clamp applied to every generated width regardless of which
        | calculator produced it. Variants narrower than `min_width` or wider
        | than `max_width` are dropped before encoding. Set either to 0 to
        | disable that side of the clamp.
        */

        'min_width' => 320,
        'max_width' => 2560,

        /*
        | Knobs for the `file_size_optimized` width calculator.
        |
        |   reduction_factor      — multiplier applied each iteration (0–1).
        |   min_width             — stop generating widths below this (px).
        |   min_file_size_bytes   — stop when predicted file size hits this floor.
        */

        'file_size_optimized' => [
            'reduction_factor' => 0.7,
            'min_width' => 20,
            'min_file_size_bytes' => 10240,
        ],

    ],

    /*
    | Swap the default Media / MediaCollection models with your own.
    | Useful for UUID primary keys, soft deletes, or extra fields.
    */

    'models' => [
        'media' => Media::class,
        'collection' => MediaCollection::class,
    ],

    /*
    | Table names used by MediaMan. Override here to coexist with naming
    | conventions that differ from the published migration.
    */

    'tables' => [
        'media' => 'mediaman_media',
        'collections' => 'mediaman_collections',
        'collection_media' => 'mediaman_collection_media',
        'mediables' => 'mediaman_mediables',
    ],

    /*
    | Pluggable MediaResolver — single surface for every path, URL, and
    | filename produced by MediaMan. Extend `DefaultMediaResolver` and
    | override only the methods you need: tenant-prefixed paths, CDN URL
    | strategies, custom filename formats, anything else.
    */

    'resolver' => DefaultMediaResolver::class,

    /*
    | Probe the target disk with a write+delete round-trip before mutating
    | disk-backed Media attributes (`file_name`, `disk`). Catches a
    | misconfigured disk early, at the cost of an extra request per mutation.
    */

    'check_disk_accessibility' => env('MEDIAMAN_CHECK_DISK_ACCESSIBILITY', false),

];
