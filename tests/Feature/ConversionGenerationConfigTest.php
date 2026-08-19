<?php

use Emaia\MediaMan\Conversions\ConversionGenerationConfig;
use Illuminate\Support\Facades\Config;

it('uses backward-compatible defaults when an old conversions block omits generation settings', function () {
    Config::set('mediaman.conversions', ['disk' => null]);

    $config = ConversionGenerationConfig::fromConfig();

    expect($config->versioning)->toBeFalse()
        ->and($config->retentionDays)->toBe(7)
        ->and($config->generationTimeoutMinutes)->toBe(1440)
        ->and($config->isVersioned())->toBeFalse();
});

it('normalizes conversion generation integer strings', function () {
    Config::set('mediaman.conversions.versioning', 'generation');
    Config::set('mediaman.conversions.version_retention_days', '0');
    Config::set('mediaman.conversions.generation_timeout_minutes', '60');

    $config = ConversionGenerationConfig::fromConfig();

    expect($config->isVersioned())->toBeTrue()
        ->and($config->retentionDays)->toBe(0)
        ->and($config->generationTimeoutMinutes)->toBe(60);
});

it('rejects unsupported conversion versioning strategies', function (mixed $value) {
    Config::set('mediaman.conversions.versioning', $value);

    expect(fn () => ConversionGenerationConfig::fromConfig())
        ->toThrow(InvalidArgumentException::class, "versioning must be false or 'generation'");
})->with([null, true, 'timestamp', 'false']);

it('rejects invalid conversion retention values', function (mixed $value) {
    Config::set('mediaman.conversions.version_retention_days', $value);

    expect(fn () => ConversionGenerationConfig::fromConfig())
        ->toThrow(InvalidArgumentException::class, 'version_retention_days must be an integer');
})->with([-1, 1.5, true, '01', 'one']);

it('rejects invalid conversion generation timeout values', function (mixed $value) {
    Config::set('mediaman.conversions.generation_timeout_minutes', $value);

    expect(fn () => ConversionGenerationConfig::fromConfig())
        ->toThrow(InvalidArgumentException::class, 'generation_timeout_minutes must be an integer');
})->with([0, -1, 1.5, true, '01', 'one']);
