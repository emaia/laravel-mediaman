<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Emaia\MediaMan\Facades\Conversion;

class ResponsiveConversions
{
    /**
     * Register predefined responsive conversions.
     */
    public static function register(): void
    {
        // Responsive with breakpoints
        Conversion::register('responsive', function($image) {
            return new ResponsiveConversion($image, [
                'type' => 'breakpoint',
                'breakpoints' => config('mediaman.responsive_images.breakpoints', [320, 640, 1024, 1366, 1920])
            ]);
        });

        // Responsive with file size optimization
        Conversion::register('responsive-optimized', function($image) {
            return new ResponsiveConversion($image, [
                'type' => 'file_size_optimized'
            ]);
        });

        // Responsive with custom widths
        Conversion::register('responsive-custom', function($image) {
            return new ResponsiveConversion($image, [
                'type' => 'custom',
                'widths' => [400, 800, 1200]
            ]);
        });

        // WebP only responsive
        Conversion::register('responsive-webp', function($image) {
            return new ResponsiveConversion($image, [
                'type' => 'breakpoint',
                'formats' => ['webp']
            ]);
        });

        // High quality responsive
        Conversion::register('responsive-hq', function($image) {
            return new ResponsiveConversion($image, [
                'type' => 'breakpoint',
                'quality' => 95,
                'formats' => ['webp', 'jpg']
            ]);
        });
    }
}

