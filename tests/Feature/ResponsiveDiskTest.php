<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Contracts\Filesystem\Filesystem;
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

it('forceDelete uses the persisted generation disk after configuration changes', function () {
    config([
        'mediaman.responsive_images.disk' => 'public',
        'mediaman.responsive_images.versioning' => 'generation',
    ]);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['webp'],
    ]);
    $media->refresh();
    $responsiveDir = $media->getDirectory().'/'.Media::RESPONSIVE_DIR;
    expect($media->activeResponsiveDisk())->toBe('public')
        ->and(Storage::disk('public')->exists($responsiveDir))->toBeTrue();

    config(['mediaman.responsive_images.disk' => null]);
    $media->forceDelete();

    expect(Storage::disk('public')->exists($responsiveDir))->toBeFalse();
});

it('tracks prior generation disks for later cleanup', function () {
    Storage::fake('responsive-a');
    Storage::fake('responsive-b');
    config([
        'mediaman.responsive_images.disk' => 'responsive-a',
        'mediaman.responsive_images.versioning' => 'generation',
    ]);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $generator = app(ResponsiveImageGenerator::class);
    $options = ['widths' => [320], 'formats' => ['webp']];
    $generator->generateResponsiveImages($media, $options);

    config(['mediaman.responsive_images.disk' => 'responsive-b']);
    $generator->generateResponsiveImages($media, $options);
    $media->refresh();
    $responsiveDir = $media->getDirectory().'/'.Media::RESPONSIVE_DIR;

    expect($media->responsiveGenerationDisks())->toEqualCanonicalizing(['responsive-a', 'responsive-b'])
        ->and(Storage::disk('responsive-a')->exists($responsiveDir))->toBeTrue()
        ->and(Storage::disk('responsive-b')->exists($responsiveDir))->toBeTrue();

    $generator->clearResponsiveImages($media);

    expect(Storage::disk('responsive-a')->exists($responsiveDir))->toBeFalse()
        ->and(Storage::disk('responsive-b')->exists($responsiveDir))->toBeFalse();
});

it('retries a partially failed multi-disk clear from its signed tombstone', function () {
    Storage::fake('responsive-a');
    Storage::fake('responsive-b');
    config([
        'mediaman.responsive_images.disk' => 'responsive-a',
        'mediaman.responsive_images.versioning' => 'generation',
    ]);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $generator = app(ResponsiveImageGenerator::class);
    $options = ['widths' => [320], 'formats' => ['webp']];
    $generator->generateResponsiveImages($media, $options);
    config(['mediaman.responsive_images.disk' => 'responsive-b']);
    $generator->generateResponsiveImages($media, $options);
    $media->refresh();
    $responsiveDir = $media->getDirectory().'/'.Media::RESPONSIVE_DIR;
    $realA = Storage::disk('responsive-a');
    $failingA = Mockery::mock(Filesystem::class);
    $failingA->shouldReceive('exists')->once()->andReturn(true);
    $failingA->shouldReceive('deleteDirectory')->once()->andReturn(false);
    Storage::set('responsive-a', $failingA);

    expect(fn () => $generator->clearResponsiveImages($media))
        ->toThrow(RuntimeException::class, 'responsive-a');

    expect(Storage::disk('responsive-b')->exists($responsiveDir))->toBeFalse()
        ->and($media->fresh()->getCustomProperty(Media::PROPERTY_RESPONSIVE_CLEARING))->toBeArray();

    Storage::set('responsive-a', $realA);
    $generator->clearResponsiveImages($media->fresh());

    expect(Storage::disk('responsive-a')->exists($responsiveDir))->toBeFalse()
        ->and($media->fresh()->hasCustomProperty(Media::PROPERTY_RESPONSIVE_CLEARING))->toBeFalse();
});

it('does not trust a forged clear tombstone during force delete', function () {
    Storage::fake('unrelated-responsive');
    Storage::disk('unrelated-responsive')->put('unrelated/keep.jpg', 'keep');
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_CLEARING, [
        'token' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'base_path' => 'unrelated',
        'disks' => ['unrelated-responsive'],
        'started_at' => now()->toIso8601String(),
        'signature' => 'forged',
    ])->save();

    $media->forceDelete();

    expect(Storage::disk('unrelated-responsive')->exists('unrelated/keep.jpg'))->toBeTrue();
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
