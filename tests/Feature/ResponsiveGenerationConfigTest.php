<?php

use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationConfig;
use Illuminate\Support\Facades\Config;

it('uses backward-compatible defaults when an old published block omits generation settings', function () {
    Config::set('mediaman.responsive_images', [
        'enabled' => true,
        'formats' => ['webp'],
    ]);

    $config = ResponsiveGenerationConfig::fromConfig();

    expect($config->versioning)->toBeFalse()
        ->and($config->retentionDays)->toBe(7)
        ->and($config->generationTimeoutMinutes)->toBe(1440)
        ->and($config->isVersioned())->toBeFalse();
});

it('normalizes canonical integer strings from environment-backed config', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');
    Config::set('mediaman.responsive_images.version_retention_days', '0');
    Config::set('mediaman.responsive_images.generation_timeout_minutes', '60');

    $config = ResponsiveGenerationConfig::fromConfig();

    expect($config->isVersioned())->toBeTrue()
        ->and($config->retentionDays)->toBe(0)
        ->and($config->generationTimeoutMinutes)->toBe(60);
});

it('rejects unsupported responsive versioning strategies', function (mixed $value) {
    Config::set('mediaman.responsive_images.versioning', $value);

    expect(fn () => ResponsiveGenerationConfig::fromConfig())
        ->toThrow(InvalidArgumentException::class, "versioning must be false or 'generation'");
})->with([null, true, 'timestamp', 'false']);

it('rejects invalid retention values', function (mixed $value) {
    Config::set('mediaman.responsive_images.version_retention_days', $value);

    expect(fn () => ResponsiveGenerationConfig::fromConfig())
        ->toThrow(InvalidArgumentException::class, 'version_retention_days must be an integer');
})->with([-1, 1.5, true, '01', 'one']);

it('rejects invalid in-progress timeout values', function (mixed $value) {
    Config::set('mediaman.responsive_images.generation_timeout_minutes', $value);

    expect(fn () => ResponsiveGenerationConfig::fromConfig())
        ->toThrow(InvalidArgumentException::class, 'generation_timeout_minutes must be an integer');
})->with([0, -1, 1.5, true, '01', 'one']);
