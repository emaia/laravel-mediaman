<?php

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Models\MediaCollection;
use Emaia\MediaMan\Placeholders\BlurredSvgPlaceholder;
use Emaia\MediaMan\Resolvers\DefaultMediaResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | === Essentials ===
    |--------------------------------------------------------------------------
    |
    | Most apps only need to look at this section.
    |
    */

    /*
    | The default disk where files should be uploaded. When null, falls
    | back to Laravel's default filesystem disk (config/filesystems.php).
    */

    'disk' => env('MEDIAMAN_DISK'),

    /*
    | Image processing driver for intervention/image.
    | Supported: "vips" (libvips, fastest), "imagick" (ImageMagick), "gd" (GD Library).
    | When null, auto-detected in this order: vips (requires ext-vips AND the
    | intervention/image-driver-vips package), then imagick (requires ext-imagick),
    | then gd as the universal fallback. Set explicitly when you need a guarantee.
    */

    'driver' => env('MEDIAMAN_DRIVER'),

    /*
    | The queue used for image conversions and responsive image generation.
    | Leave null to use the default queue driver.
    */

    'queue' => null,

    /*
    | The default collection name where files should reside when uploads
    | do not specify a collection.
    */

    'collection' => 'Default',

    /*
    |--------------------------------------------------------------------------
    | === Validation & security defaults ===
    |--------------------------------------------------------------------------
    |
    | Tighten or loosen what uploads are allowed in.
    |
    */

    /*
    | Allowed MIME types for uploads. An empty array means all MIME types
    | are accepted. You may use wildcards like 'image/*'.
    |
    | Examples:
    |   ['image/jpeg', 'image/png', 'application/pdf']
    |   ['image/*', 'application/pdf']
    */

    'allowed_mime_types' => [],

    /*
    | Maximum allowed file size for uploads, in bytes. Set to 0 (default)
    | to disable the check. Enforced before the file touches disk,
    | complementing PHP's `upload_max_filesize` / `post_max_size`.
    |
    | Examples:
    |   5 * 1024 * 1024   // 5 MB
    |   100 * 1024 * 1024 // 100 MB
    */

    'max_file_size' => env('MEDIAMAN_MAX_FILE_SIZE', 0),

    /*
    | Block executable/server-side file extensions on upload.
    | Set `block_disallowed_extensions` to false to opt out.
    */

    'block_disallowed_extensions' => true,

    'disallowed_extensions' => [
        'php', 'phtml', 'phar', 'shtml', 'htaccess',
        'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
    ],

    /*
    |--------------------------------------------------------------------------
    | === Per-feature configuration ===
    |--------------------------------------------------------------------------
    |
    | Knobs that only matter when you opt into a specific feature.
    |
    */

    /*
    | Settings for downloading files from remote URLs (MediaUploader::fromUrl).
    */

    'url_sources' => [

        /*
        | Allow URLs that resolve to private or reserved IP addresses
        | (localhost, 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16,
        | 169.254.0.0/16). Leave false to keep SSRF protection on.
        */

        'allow_private_hosts' => false,

        /*
        | Maximum time (seconds) to wait for a response when downloading.
        */

        'timeout_seconds' => 30,

        /*
        | Maximum download size in bytes. Downloads exceeding this are
        | aborted mid-stream. Default is 100 MB.
        */

        'max_size_bytes' => 100 * 1024 * 1024,

        /*
        | When true, SSL certificates are verified. Set to false only for
        | development or internal APIs with self-signed certificates.
        */

        'verify_ssl' => true,

        /*
        | The User-Agent header sent with remote download requests.
        */

        'user_agent' => 'MediaMan/2.x',

    ],

    /*
    | Settings for uploading files from base64-encoded data
    | (MediaUploader::fromBase64).
    */

    'base64' => [

        /*
        | Maximum size of the raw base64 string before decoding. The check
        | runs before any memory-intensive decode operation. Default is 50 MB.
        */

        'max_size_bytes' => 50 * 1024 * 1024,

    ],

    /*
    | Settings for generating temporary signed URLs on cloud disks.
    */

    'temporary_url' => [

        /*
        | Default expiration time (minutes) used when no explicit expiration
        | is passed to Media::getTemporaryUrl().
        */

        'default_lifetime_minutes' => 5,

    ],

    /*
    | Options applied to generated media URLs by DefaultMediaResolver.
    */

    'url' => [
        // Append ?v={updated_at} for cache busting.
        'version_query' => false,

        // Optional CDN/origin prefix (e.g. 'https://cdn.example.com').
        // Absolute storage URLs (S3-style) are stripped before prefixing.
        'prefix' => null,
    ],

    /*
    | Low-quality image placeholder (LQIP). Generated synchronously on
    | upload for images and stored as a data URI in custom_properties.
    | The default generator wraps a tiny blurred JPEG in an SVG with the
    | original viewBox, producing a placeholder that pins aspect ratio
    | (zero CLS) and is injected into the responsive `srcset`.
    | The `generator` key accepts any class implementing PlaceholderGenerator.
    |
    | Per-generator tuning lives under its own sub-block (`blurred_svg`,
    | etc.) so swapping `generator` ignores knobs that don't apply.
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
    | Responsive image generation. The feature is opt-in: nothing is
    | generated unless you call generateResponsive() on an upload or
    | flip `auto_generate` on.
    */

    'responsive_images' => [

        /*
        | Kill-switch for the whole responsive pipeline. When false, even
        | explicit generateResponsive() calls no-op.
        */

        'enabled' => env('MEDIAMAN_RESPONSIVE_ENABLED', true),

        /*
        | When true, responsive images are generated automatically on
        | every image upload.
        */

        'auto_generate' => env('MEDIAMAN_RESPONSIVE_AUTO_GENERATE', false),

        /*
        | Whether to queue generation or run synchronously.
        */

        'queue' => env('MEDIAMAN_RESPONSIVE_QUEUE', true),

        /*
        | Default breakpoint widths in pixels.
        */

        'breakpoints' => [320, 640, 1024, 1366, 1920],

        /*
        | Output formats. Supported: webp, avif, jpg, png.
        */

        'formats' => ['webp'],

        /*
        | JPEG/WebP quality (1–100). Higher means larger files.
        */

        'quality' => 85,

        /*
        | Width-selection strategy. Available: breakpoint, file_size_optimized.
        */

        'width_calculator' => 'breakpoint',

        /*
        | Hard floor for generated widths.
        */

        'min_width' => 320,

        /*
        | Hard ceiling for generated widths.
        */

        'max_width' => 2560,

        /*
        | Parameters for the file_size_optimized width calculator.
        |
        | reduction_factor: file size reduction per iteration (0–1).
        | min_width: stop when calculated width falls below this value (px).
        | min_file_size_bytes: stop when predicted file size falls below this.
        */

        'file_size_optimized' => [
            'reduction_factor' => 0.7,
            'min_width' => 20,
            'min_file_size_bytes' => 10240,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | === Customization ===
    |--------------------------------------------------------------------------
    |
    | Advanced knobs. Most apps never touch this section.
    |
    */

    /*
    | Swap the default Media / MediaCollection models with your own.
    | Useful for UUID primary keys, soft deletes, or extra fields.
    */

    'models' => [
        'media' => Media::class,
        'collection' => MediaCollection::class,
    ],

    /*
    | The table names used by MediaMan.
    */

    'tables' => [
        'media' => 'mediaman_media',
        'collections' => 'mediaman_collections',
        'collection_media' => 'mediaman_collection_media',
        'mediables' => 'mediaman_mediables',
    ],

    /*
    | Pluggable MediaResolver — the single surface for every path, URL, and
    | filename produced by MediaMan. Extend `DefaultMediaResolver` and
    | override only the methods you need: tenant-prefixed paths, CDN URL
    | strategies, custom filename formats, anything else.
    */

    'resolver' => DefaultMediaResolver::class,

    /*
    | When true, the package performs a write/delete probe before mutating
    | disk-backed Media attributes (file_name, disk). Catches misconfigured
    | disks early at the cost of an extra round-trip per mutation.
    */

    'check_disk_accessibility' => env('MEDIAMAN_CHECK_DISK_ACCESSIBILITY', false),

];
