<?php

use Emaia\MediaMan\ResponsiveImages\ResponsiveConversion;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

it('invokes generateResponsiveImages on objects that support it', function () {
    $image = (new ImageManager(Driver::class))->createImage(100, 100);

    $media = new class
    {
        public ?array $receivedOptions = null;

        public function generateResponsiveImages(array $options = []): void
        {
            $this->receivedOptions = $options;
        }
    };

    $conversion = new ResponsiveConversion($image, ['type' => 'breakpoint', 'quality' => 90]);

    $result = ($conversion)($media);

    expect($media->receivedOptions)->toEqual(['type' => 'breakpoint', 'quality' => 90])
        ->and($result)->toBe($image);
});

it('is a no-op for objects without generateResponsiveImages', function () {
    $image = (new ImageManager(Driver::class))->createImage(100, 100);

    $bareObject = new stdClass;

    $conversion = new ResponsiveConversion($image);

    $result = ($conversion)($bareObject);

    expect($result)->toBe($image);
});

it('exposes the options it was constructed with', function () {
    $image = (new ImageManager(Driver::class))->createImage(10, 10);

    $options = ['type' => 'custom', 'widths' => [320, 640]];

    $conversion = new ResponsiveConversion($image, $options);

    expect($conversion->getOptions())->toEqual($options);
});

it('defaults options to an empty array', function () {
    $image = (new ImageManager(Driver::class))->createImage(10, 10);

    $conversion = new ResponsiveConversion($image);

    expect($conversion->getOptions())->toEqual([]);
});
