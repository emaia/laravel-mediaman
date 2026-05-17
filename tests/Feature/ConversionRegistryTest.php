<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Exceptions\InvalidConversion;
use Emaia\MediaMan\ResponsiveImages\ResponsiveConversion;
use Intervention\Image\Image;

it('can register and retrieve specific conversions', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('conversion', function () {
        return true;
    });

    $conversion = $conversionRegistry->get('conversion');

    expect($conversion())->toBeTrue();
});

it('can retrieve all the registered conversions', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('one', function () {
        return 'one';
    });

    $conversionRegistry->register('two', function () {
        return 'two';
    });

    $conversions = $conversionRegistry->all();

    expect($conversions)->toHaveCount(2);
    expect($conversions['one']())->toEqual('one');
    expect($conversions['two']())->toEqual('two');
});

test('there can only be one conversion registered with the same name', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('conversion', function () {
        return 'one';
    });

    $conversionRegistry->register('conversion', function () {
        return 'two';
    });

    expect($conversionRegistry->all())->toHaveCount(1);
    expect($conversionRegistry->get('conversion')())->toEqual('two');
});

it('can determine if a conversion has been registered', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('registered', function () {
        //
    });

    expect($conversionRegistry->exists('registered'))->toBeTrue();
    expect($conversionRegistry->exists('unregistered'))->toBeFalse();
});

it('will error when attempting to retrieve an unregistered conversion', function () {
    $this->expectException(InvalidConversion::class);

    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->get('unregistered');
});

it('detects webp format from closure source at registration time', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('thumb', function (Image $image) {
        return $image->resize(64, 64)->toWebp(80);
    });

    expect($conversionRegistry->getFormat('thumb'))->toBe('webp');
});

it('detects avif format from closure source', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('hero', function (Image $image) {
        return $image->toAvif(50);
    });

    expect($conversionRegistry->getFormat('hero'))->toBe('avif');
});

it('detects png format from closure source', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('logo', function (Image $image) {
        return $image->toPng();
    });

    expect($conversionRegistry->getFormat('logo'))->toBe('png');
});

it('detects jpg format from closure source', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('thumb', function (Image $image) {
        return $image->toJpeg(90);
    });

    expect($conversionRegistry->getFormat('thumb'))->toBe('jpg');
});

it('returns null format for closures without explicit format method', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('passthrough', function (Image $image) {
        return $image->resize(100, 100);
    });

    expect($conversionRegistry->getFormat('passthrough'))->toBeNull();
});

it('returns null format for ResponsiveConversion closures', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('responsive', function (Image $image) {
        return new ResponsiveConversion($image, ['type' => 'breakpoint']);
    });

    expect($conversionRegistry->getFormat('responsive'))->toBeNull();
});

it('returns null format for unknown conversion names', function () {
    $conversionRegistry = new ConversionRegistry;

    expect($conversionRegistry->getFormat('unregistered'))->toBeNull();
});

it('get() still returns the original closure after format refactor', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('test', function (Image $image) {
        return $image->toWebp();
    });

    $closure = $conversionRegistry->get('test');

    expect($closure)->toBeCallable();
});

it('all() returns closures only, not internal format data', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('one', function () {
        return 'one';
    });
    $conversionRegistry->register('two', function () {
        return 'two';
    });

    $all = $conversionRegistry->all();

    expect($all)->toHaveCount(2);
    expect($all['one']())->toEqual('one');
    expect($all['two']())->toEqual('two');
});
