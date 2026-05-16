<?php

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Models\MediaCollection;

return [

    /*
    |--------------------------------------------------------------------------
    | The default disk where files should be uploaded
    |--------------------------------------------------------------------------
    |
    */

    'disk' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Image Processing Driver
    |--------------------------------------------------------------------------
    |
    | Choose which driver intervention/image should use for processing.
    | Supported: "imagick" (ImageMagick) or "gd" (GD Library).
    |
    | Make sure the corresponding PHP extension is installed.
    |
    */

    'driver' => env('MEDIAMAN_DRIVER', 'imagick'),

    /*
    |--------------------------------------------------------------------------
    | The default collection name where files should reside.
    |--------------------------------------------------------------------------
    |
    */

    'collection' => 'Default',

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | Define which MIME types are allowed for upload. An empty array means
    | all MIME types are accepted. You may use wildcards like 'image/*'.
    |
    | Examples:
    |   ['image/jpeg', 'image/png', 'application/pdf']
    |   ['image/*', 'application/pdf']
    |
    */

    'allowed_mime_types' => [],

    /*
    |--------------------------------------------------------------------------
    | The queue that should be used to perform image conversions
    |--------------------------------------------------------------------------
    |
    | Leave empty to use the default queue driver.
    |
    */

    'queue' => null,

    /*
    |--------------------------------------------------------------------------
    | The fully qualified class name of the MediaMan models
    |--------------------------------------------------------------------------
    |
    */

    'models' => [
        'media' => Media::class,
        'collection' => MediaCollection::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | The table names for MediaMan
    |--------------------------------------------------------------------------
    |
    */

    'tables' => [
        'media' => 'mediaman_media',
        'collections' => 'mediaman_collections',
        'collection_media' => 'mediaman_collection_media',
        'mediables' => 'mediaman_mediables',
    ],

    /*
    |--------------------------------------------------------------------------
    | Accessibility Check Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration determines whether the package should perform an
    | accessibility check (i.e., write and delete) when ensuring the disk is
    | writable. If set to true, a temporary file will be created and deleted
    | to validate write permissions on the specified disk.
    |
    */

    'check_disk_accessibility' => env('MEDIAMAN_CHECK_DISK_ACCESSIBILITY', false),

    /*
    |--------------------------------------------------------------------------
    | Responsive Images Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how responsive images should be generated and optimized.
    |
    */

    'responsive_images' => [

        /*
        |--------------------------------------------------------------------------
        | Enable Responsive Images
        |--------------------------------------------------------------------------
        |
        | Set to true to enable responsive image generation functionality.
        |
        */

        'enabled' => env('MEDIAMAN_RESPONSIVE_ENABLED', true),

        /*
        |--------------------------------------------------------------------------
        | Default Breakpoints
        |--------------------------------------------------------------------------
        |
        | Default breakpoint widths for responsive image generation.
        | These will be used when no custom widths are specified.
        |
        */

        'breakpoints' => [320, 640, 1024, 1366, 1920],

        /*
        |--------------------------------------------------------------------------
        | Image Quality
        |--------------------------------------------------------------------------
        |
        | Default quality for responsive images (1-100).
        | Higher values mean better quality but larger file sizes.
        |
        */

        'quality' => 85,

        /*
        |--------------------------------------------------------------------------
        | Output Formats
        |--------------------------------------------------------------------------
        |
        | Formats to generate for responsive images.
        | Supported: webp, avif, jpg, png
        |
        */

        'formats' => ['webp'],

        /*
        |--------------------------------------------------------------------------
        | Queue Responsive Generation
        |--------------------------------------------------------------------------
        |
        | Whether to queue responsive image generation or process immediately.
        |
        */

        'queue' => env('MEDIAMAN_RESPONSIVE_QUEUE', true),

        /*
        |--------------------------------------------------------------------------
        | Width Calculator
        |--------------------------------------------------------------------------
        |
        | The width calculator to use for automatic responsive generation.
        | Available: breakpoint, file_size_optimized
        |
        */

        'width_calculator' => 'breakpoint',

        /*
        |--------------------------------------------------------------------------
        | Auto Generate
        |--------------------------------------------------------------------------
        |
        | Automatically generate responsive images on upload.
        |
        */

        'auto_generate' => env('MEDIAMAN_RESPONSIVE_AUTO_GENERATE', false),

        /*
        |--------------------------------------------------------------------------
        | Minimum Width
        |--------------------------------------------------------------------------
        |
        | Minimum width for responsive images. Images smaller than this
        | will not have responsive variants generated.
        |
        */

        'min_width' => 320,

        /*
        |--------------------------------------------------------------------------
        | Maximum Width
        |--------------------------------------------------------------------------
        |
        | Maximum width for responsive images. Larger images will be
        | resized down to this width for the largest variant.
        |
        */

        'max_width' => 2560,

        /*
        |--------------------------------------------------------------------------
        | File Size Optimized Width Calculator
        |--------------------------------------------------------------------------
        |
        | Parameters for the file_size_optimized width calculator algorithm.
        |
        | reduction_factor: File size reduction per iteration (0-1).
        | min_width: Stop when calculated width falls below this value (px).
        | min_file_size_bytes: Stop when predicted file size falls below this.
        |
        */

        'file_size_optimized' => [
            'reduction_factor' => 0.7,
            'min_width' => 20,
            'min_file_size_bytes' => 10240,
        ],

        /*
        |--------------------------------------------------------------------------
        | Predefined Conversion Defaults
        |--------------------------------------------------------------------------
        |
        | Default values used by the built-in responsive conversions.
        |
        */

        'predefined_conversions' => [
            'responsive_custom_widths' => [400, 800, 1200],
            'responsive_hq_quality' => 95,
        ],

    ],
];
