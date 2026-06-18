<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Placeholders\GeometricBlurPlaceholder;
use Emaia\MediaMan\Placeholders\PlaceholderGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('mediaman.placeholder.enabled', true);
    Config::set('mediaman.placeholder.generator', GeometricBlurPlaceholder::class);
    // The closure bind reads config lazily, but the singleton caches the
    // first resolve. Forget so the next app() call picks up the swapped
    // generator from config.
    app()->forgetInstance(PlaceholderGenerator::class);
});

function decodeGeometricSvg(string $dataUri): string
{
    return rawurldecode(substr($dataUri, strlen('data:image/svg+xml,')));
}

it('wraps a 4×4 grid of rects with a feGaussianBlur filter', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $svg = decodeGeometricSvg($media->getPlaceholder());

    expect($svg)->toContain('viewBox="0 0 800 600"')
        ->and($svg)->toContain('<filter')
        ->and($svg)->toContain('<feGaussianBlur')
        ->and(substr_count($svg, '<rect'))->toEqual(16)
        ->and($svg)->not->toContain('<image');
});

it('respects mediaman.placeholder.geometric_blur.grid_size', function () {
    Config::set('mediaman.placeholder.geometric_blur.grid_size', 8);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $svg = decodeGeometricSvg($media->getPlaceholder());

    expect(substr_count($svg, '<rect'))->toEqual(64);
});

it('propagates mediaman.placeholder.geometric_blur.blur_std_deviation into the SVG filter', function () {
    Config::set('mediaman.placeholder.geometric_blur.blur_std_deviation', 35);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $svg = decodeGeometricSvg($media->getPlaceholder());

    expect($svg)->toContain('stdDeviation="35"');
});

it('returns null when grid_size is 0 or negative', function () {
    Config::set('mediaman.placeholder.geometric_blur.grid_size', 0);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    expect($media->getPlaceholder())->toBeNull();
});

it('produces a srcset-safe data URI', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $uri = $media->getPlaceholder();
    $body = substr($uri, strlen('data:image/svg+xml,'));

    expect($uri)->not->toMatch('/\s/')
        ->and($body)->not->toContain(',');
});

it('produces a compact payload at grid=4 (< 2.5 KB regardless of source size)', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 4000, 3000))->upload();

    // 16 rects × ~120 bytes url-encoded + SVG wrapper + filter ≈ 2 KB.
    // Stays under 2.5 KB independently of source dimensions; grid=8 trades
    // size (~6-8 KB) for visual richness.
    expect(strlen($media->getPlaceholder()))->toBeLessThan(2500);
});

it('returns null for non-image uploads', function () {
    $media = MediaUploader::source(UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))->upload();

    expect($media->getPlaceholder())->toBeNull();
});
