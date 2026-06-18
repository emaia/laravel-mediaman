<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Placeholders\DominantColorPlaceholder;
use Emaia\MediaMan\Placeholders\PlaceholderGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('mediaman.placeholder.enabled', true);
    // The service provider captures config('mediaman.placeholder.generator')
    // at register time, so swapping config here wouldn't rebind. Bind the
    // generator instance directly for the duration of the test.
    app()->instance(PlaceholderGenerator::class, app(DominantColorPlaceholder::class));
});

it('produces a single-fill SVG with the original viewBox', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 1280, 720))->upload();

    $svg = rawurldecode(substr($media->getPlaceholder(), strlen('data:image/svg+xml,')));

    expect($svg)->toContain('viewBox="0 0 1280 720"')
        ->and($svg)->toContain('<rect')
        ->and($svg)->toMatch('/fill="#[0-9a-f]{6}([0-9a-f]{2})?"/i')
        ->and($svg)->not->toContain('<filter')
        ->and($svg)->not->toContain('<image');
});

it('keeps the payload tiny (< 250 bytes regardless of source size)', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 4000, 3000))->upload();

    expect(strlen($media->getPlaceholder()))->toBeLessThan(250);
});

it('produces a srcset-safe data URI', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $uri = $media->getPlaceholder();
    $body = substr($uri, strlen('data:image/svg+xml,'));

    expect($uri)->not->toMatch('/\s/')
        ->and($body)->not->toContain(',');
});

it('returns null for non-image uploads', function () {
    $media = MediaUploader::source(UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))->upload();

    expect($media->getPlaceholder())->toBeNull();
});
