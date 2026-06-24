<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

beforeEach(function () {
    config([
        'filesystems.disks.public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
        ],
        'filesystems.disks.s3' => [
            'driver' => 'local',
            'root' => storage_path('app/s3'),
        ],
        'filesystems.disks.local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
    ]);

    foreach (['public', 's3', 'local', 'default'] as $disk) {
        try {
            $s = Storage::disk($disk);
            if ($s->exists('')) {
                $s->deleteDirectory('');
            }
        } catch (Throwable) {
        }
    }
});

function newMediaOnDisk(string $disk): Media
{
    $media = new Media;
    $media->name = 'test';
    $media->file_name = 'photo.jpg';
    $media->mime_type = 'image/jpeg';
    $media->disk = $disk;
    $media->size = 1024;
    $media->save();

    Storage::disk($disk)->put($media->getPath(), 'fake-content');

    return $media;
}

function createRealImage(Media $media): void
{
    $manager = new ImageManager(Driver::class);
    $image = $manager->createImage(640, 480);
    $encoded = $image->encode(new JpegEncoder(quality: 85));
    Storage::disk($media->disk)->put($media->getPath(), (string) $encoded);
}

// ─── ConversionRegistry disk API ────────────────────────────────────────

it('registers a conversion with an explicit disk', function () {
    $registry = new ConversionRegistry;

    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    expect($registry->getDisk('thumb'))->toBe('public');
});

it('registers a conversion without a disk (null)', function () {
    $registry = new ConversionRegistry;

    $registry->register('thumb', fn ($img) => $img->resize(200, 200));

    expect($registry->getDisk('thumb'))->toBeNull();
});

it('getDisk returns null for unregistered conversions', function () {
    $registry = new ConversionRegistry;

    expect($registry->getDisk('nonexistent'))->toBeNull();
});

it('disks() returns unique non-null disks', function () {
    $registry = new ConversionRegistry;

    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');
    $registry->register('large', fn ($img) => $img->resize(800, null), disk: 'public');
    $registry->register('archive', fn ($img) => $img->resize(4096, null), disk: 's3');
    $registry->register('medium', fn ($img) => $img->resize(400, 400));

    $disks = $registry->disks();

    expect($disks)->toEqualCanonicalizing(['public', 's3']);
});

// ─── Media::getConversionDisk / conversionFilesystem ────────────────────

it('getConversionDisk returns registered disk', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = newMediaOnDisk('default');

    expect($media->getConversionDisk('thumb'))->toBe('public');
});

it('getConversionDisk falls back to media disk when none registered', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200));

    $media = newMediaOnDisk('default');

    expect($media->getConversionDisk('thumb'))->toBe('default');
});

it('getConversionDisk falls back to mediaman.conversions.disk before media disk', function () {
    config(['mediaman.conversions.disk' => 'public']);

    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200));

    $media = newMediaOnDisk('default');

    expect($media->getConversionDisk('thumb'))->toBe('public');
});

it('explicit register disk overrides the conversions config default', function () {
    config(['mediaman.conversions.disk' => 'public']);

    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 's3');

    $media = newMediaOnDisk('default');

    expect($media->getConversionDisk('thumb'))->toBe('s3');
});

it('conversionFilesystem reads from the conversion disk', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = newMediaOnDisk('default');

    $path = $media->getDirectory().'/conversions/thumb/photo.webp';
    Storage::disk('public')->put($path, 'conversion-content');
    Storage::disk('default')->put($path, 'wrong-disk-content');

    $fs = $media->conversionFilesystem('thumb');

    expect($fs->get($path))->toBe('conversion-content');
});

// ─── ImageManipulator writes to conversion disk ─────────────────────────

it('ImageManipulator writes to the registered conversion disk', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(100, 100), disk: 'public');

    $media = newMediaOnDisk('default');
    createRealImage($media);

    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);

    $expectedPath = $media->getDirectory().'/conversions/thumb/photo.jpg';

    expect(Storage::disk('public')->exists($expectedPath))->toBeTrue();
    expect(Storage::disk('default')->exists($expectedPath))->toBeFalse();
});

// ─── getUrl resolves through conversion filesystem ──────────────────────

it('getUrl produces a URL (conversion file lives on registered disk)', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = newMediaOnDisk('default');

    $conversionPath = $media->getDirectory().'/conversions/thumb/photo.webp';
    Storage::disk('public')->put($conversionPath, 'webp-content');

    $url = $media->getUrl('thumb');

    // The URL should be non-empty (file was placed on the 'public' disk)
    expect($url)->not->toBeEmpty();
});

// ─── hasConversion checks the conversion disk ───────────────────────────

it('hasConversion checks the conversion disk not media disk', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = newMediaOnDisk('default');

    $convPath = $media->getDirectory().'/conversions/thumb/photo.webp';
    Storage::disk('public')->put($convPath, 'content');

    expect($media->hasConversion('thumb'))->toBeTrue();

    Storage::disk('public')->delete($convPath);
    $media->clearConversionFormatCache();

    expect($media->hasConversion('thumb'))->toBeFalse();
});

// ─── Delete observer cleans conversion disks ────────────────────────────

it('forceDelete removes files from conversion disks', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = newMediaOnDisk('default');

    $convDir = $media->getDirectory().'/conversions/thumb';
    Storage::disk('public')->put($convDir.'/photo.webp', 'conversion');
    Storage::disk('public')->put($media->getDirectory().'/extra.txt', 'extra-file');

    $media->forceDelete();

    expect(Storage::disk('default')->exists($media->getPath()))->toBeFalse();
    expect(Storage::disk('public')->exists($media->getDirectory()))->toBeFalse();
});

// ─── getConversionDisks ─────────────────────────────────────────────────

it('getConversionDisks returns unique disks across all conversions', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');
    $registry->register('large', fn ($img) => $img->resize(800, null), disk: 'public');
    $registry->register('archive', fn ($img) => $img->resize(4096, null), disk: 's3');
    $registry->register('defaultSize', fn ($img) => $img->resize(100, 100));

    $media = newMediaOnDisk('default');

    $disks = $media->getConversionDisks();

    expect($disks)->toEqualCanonicalizing(['public', 's3', 'default']);
});

it('getConversionDisks returns empty when no conversions registered', function () {
    $media = newMediaOnDisk('default');

    expect($media->getConversionDisks())->toBe([]);
});

// ─── Media::copy copies conversions to the correct disk ─────────────────

it('Media::copy copies conversions across disks', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $source = newMediaOnDisk('default');
    createRealImage($source);

    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($source, ['thumb'], onlyIfMissing: false);

    $target = $source->replicate(['id']);
    $target->save();
    Storage::disk($target->disk)->put($target->getPath(), 'target-fake');

    $ref = new ReflectionMethod($source, 'copyConversions');
    $ref->invoke($source, $target);

    $targetConvPath = $target->getDirectory().'/conversions/thumb/photo.jpg';

    expect(Storage::disk('public')->exists($targetConvPath))->toBeTrue();
});

// ─── Disk migration: changing media disk preserves conversion placement ──

it('changing media disk does not move conversions stored on other disks', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = newMediaOnDisk('default');
    $convPath = $media->getDirectory().'/conversions/thumb/photo.webp';
    Storage::disk('public')->put($convPath, 'public-conversion');

    $media->disk = 'local';
    $media->save();

    expect(Storage::disk('public')->exists($convPath))->toBeTrue();
    expect($media->getConversionDisk('thumb'))->toBe('public');
});

// ─── 3 conversions on 3 different disks ─────────────────────────────────

it('supports three conversions on three different disks', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');
    $registry->register('medium', fn ($img) => $img->resize(800, null), disk: 'local');
    $registry->register('archive', fn ($img) => $img->resize(4096, null), disk: 's3');

    $media = newMediaOnDisk('default');

    expect($media->getConversionDisk('thumb'))->toBe('public');
    expect($media->getConversionDisk('medium'))->toBe('local');
    expect($media->getConversionDisk('archive'))->toBe('s3');

    $disks = $media->getConversionDisks();
    expect($disks)->toEqualCanonicalizing(['public', 'local', 's3']);
});

// ─── DefaultMediaResolver::url / temporaryUrl use conversionFilesystem ──

it('DefaultMediaResolver::url returns non-empty URL for conversion on another disk', function () {
    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = newMediaOnDisk('default');

    $convPath = $media->getDirectory().'/conversions/thumb/photo.webp';
    Storage::disk('public')->put($convPath, 'conversion-content');

    $resolver = app(MediaResolver::class);
    $url = $resolver->url($media, 'thumb');

    expect($url)->not->toBeEmpty();
});

it('DefaultMediaResolver::url for original uses media disk', function () {
    $media = newMediaOnDisk('default');

    $resolver = app(MediaResolver::class);
    $url = $resolver->url($media, null);

    expect($url)->not->toBeEmpty();
});
