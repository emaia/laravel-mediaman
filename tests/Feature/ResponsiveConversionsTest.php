<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\ResponsiveImages\ResponsiveConversion;
use Emaia\MediaMan\ResponsiveImages\ResponsiveConversions;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

beforeEach(function () {
    // Reset the registry to keep tests independent
    app()->forgetInstance(ConversionRegistry::class);
    app()->singleton(ConversionRegistry::class);

    ResponsiveConversions::register();
});

function invokeRegistered(string $name): ResponsiveConversion
{
    $image = (new ImageManager(Driver::class))->createImage(100, 100);

    $closure = Conversion::get($name);

    return $closure($image);
}

it('registers the breakpoint responsive conversion', function () {
    $conversion = invokeRegistered('responsive');

    expect($conversion)->toBeInstanceOf(ResponsiveConversion::class)
        ->and($conversion->getOptions())->toMatchArray([
            'type' => 'breakpoint',
            'breakpoints' => config('mediaman.responsive_images.breakpoints'),
        ]);
});

it('registers the file-size optimized responsive conversion', function () {
    $conversion = invokeRegistered('responsive-optimized');

    expect($conversion->getOptions())->toEqual(['type' => 'file_size_optimized']);
});

it('registers the custom-widths responsive conversion', function () {
    $conversion = invokeRegistered('responsive-custom');

    expect($conversion->getOptions())->toMatchArray([
        'type' => 'custom',
        'widths' => config(
            'mediaman.responsive_images.predefined_conversions.responsive_custom_widths',
            [400, 800, 1200]
        ),
    ]);
});

it('registers the webp-only responsive conversion', function () {
    $conversion = invokeRegistered('responsive-webp');

    expect($conversion->getOptions())->toMatchArray([
        'type' => 'breakpoint',
        'formats' => ['webp'],
    ]);
});

it('registers the high quality responsive conversion', function () {
    $conversion = invokeRegistered('responsive-hq');

    $options = $conversion->getOptions();
    expect($options)->toHaveKeys(['type', 'quality', 'formats'])
        ->and($options['type'])->toEqual('breakpoint')
        ->and($options['formats'])->toEqual(['webp', 'jpg'])
        ->and($options['quality'])->toEqual(config(
            'mediaman.responsive_images.predefined_conversions.responsive_hq_quality',
            95
        ));
});
