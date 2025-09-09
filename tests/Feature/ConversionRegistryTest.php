<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Exceptions\InvalidConversion;

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
