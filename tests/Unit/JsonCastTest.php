<?php

use Emaia\MediaMan\Casts\Json;
use Emaia\MediaMan\Models\Media;

it('returns null when getting a null value', function () {
    $cast = new Json;
    $model = new Media;

    expect($cast->get($model, 'custom_properties', null, []))->toBeNull();
});

it('returns null when setting a null value', function () {
    $cast = new Json;
    $model = new Media;

    expect($cast->set($model, 'custom_properties', null, []))->toBeNull();
});

it('decodes valid JSON strings to arrays', function () {
    $cast = new Json;
    $model = new Media;

    expect($cast->get($model, 'custom_properties', '{"a":1}', []))->toBe(['a' => 1]);
});

it('encodes arrays to JSON strings', function () {
    $cast = new Json;
    $model = new Media;

    expect($cast->set($model, 'custom_properties', ['a' => 1], []))->toBe('{"a":1}');
});

it('roundtrips null without emitting a deprecation notice', function () {
    $cast = new Json;
    $model = new Media;

    set_error_handler(function (int $errno, string $errstr): bool {
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            throw new RuntimeException("Unexpected deprecation: {$errstr}");
        }

        return false;
    });

    try {
        $stored = $cast->set($model, 'custom_properties', null, []);
        $retrieved = $cast->get($model, 'custom_properties', $stored, []);

        expect($retrieved)->toBeNull();
    } finally {
        restore_error_handler();
    }
});
