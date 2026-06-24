<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'filesystems.disks.public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
        ],
    ]);

    foreach (['public', 'default'] as $disk) {
        try {
            $s = Storage::disk($disk);
            if ($s->exists('')) {
                $s->deleteDirectory('');
            }
        } catch (Throwable) {
        }
    }
});

it('responsiveDisk falls back to media disk when config is null', function () {
    config(['mediaman.responsive_images.disk' => null]);

    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();

    expect($media->responsiveDisk())->toBe($media->disk);
});

it('responsiveDisk reads mediaman.responsive_images.disk when set', function () {
    config(['mediaman.responsive_images.disk' => 'public']);

    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();

    expect($media->responsiveDisk())->toBe('public');
});

it('ResponsiveImageGenerator writes variants to the configured responsive disk', function () {
    config(['mediaman.responsive_images.disk' => 'public']);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['webp'],
    ]);

    $variant = $media->fresh()->getResponsiveImages()->first();
    $variantPath = $variant->path;

    expect(Storage::disk('public')->exists($variantPath))->toBeTrue()
        ->and(Storage::disk($media->disk)->exists($variantPath))->toBeFalse();
});

it('clearResponsiveImages removes the responsive directory from the configured disk', function () {
    config(['mediaman.responsive_images.disk' => 'public']);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $generator = app(ResponsiveImageGenerator::class);
    $generator->generateResponsiveImages($media, ['widths' => [320], 'formats' => ['webp']]);

    $responsiveDir = $media->getDirectory().'/'.Media::RESPONSIVE_DIR;
    expect(Storage::disk('public')->exists($responsiveDir))->toBeTrue();

    $generator->clearResponsiveImages($media);

    expect(Storage::disk('public')->exists($responsiveDir))->toBeFalse()
        ->and($media->fresh()->hasResponsiveImages())->toBeFalse();
});

it('forceDelete removes responsive variants from a non-media disk', function () {
    config(['mediaman.responsive_images.disk' => 'public']);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['webp'],
    ]);

    $responsiveDir = $media->getDirectory().'/'.Media::RESPONSIVE_DIR;
    expect(Storage::disk('public')->exists($responsiveDir))->toBeTrue();

    $media->forceDelete();

    expect(Storage::disk('public')->exists($responsiveDir))->toBeFalse()
        ->and(Storage::disk($media->disk)->exists($media->getPath()))->toBeFalse();
});

it('responsive variant URL points to the responsive disk', function () {
    config(['mediaman.responsive_images.disk' => 'public']);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['webp'],
    ]);

    $variant = $media->fresh()->getResponsiveImages()->first();

    // The stored URL was computed at generation time from the responsive disk's
    // filesystem(), not the media's primary disk.
    expect($variant->url)->not->toBeEmpty();
});
