<?php

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
    | The default collection name where files should reside.
    |--------------------------------------------------------------------------
    |
    */

    'collection' => 'Default',

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
        'media' => \Emaia\MediaMan\Models\Media::class,
        'collection' => \Emaia\MediaMan\Models\MediaCollection::class,
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

        'formats' => ['webp', 'jpg'],

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

    ],
];
