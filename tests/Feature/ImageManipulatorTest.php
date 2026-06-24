<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Exceptions\InvalidConversion;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Models\Media;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;

beforeEach(function () {
    config(['filesystems.disks.test' => [
        'driver' => 'local',
        'root' => storage_path('app/test'),
    ]]);

    if (Storage::disk('test')->exists('')) {
        Storage::disk('test')->deleteDirectory('');
    }
    Storage::disk('test')->makeDirectory('1-'.md5('1'.config('app.key')));
});

function makeImageMedia(): Media
{
    $media = new Media;
    $media->name = 'test image';
    $media->file_name = 'original.png';
    $media->mime_type = 'image/png';
    $media->disk = 'test';
    $media->size = 1;
    $media->save();

    return $media;
}

function createOriginalImage(Media $media, ImageManager $manager, int $width = 640, int $height = 480): void
{
    $manager->createImage($width, $height)->save(
        Storage::disk('test')->path($media->getDirectory().'/original.png')
    );
}

it('it will apply registered conversions', function () {
    $conversionRegistry = new ConversionRegistry;

    $conversionRegistry->register('resize', function (Image $image) {
        return $image->resize(64, 50);
    });

    $manager = new ImageManager(Driver::class);

    $image = $manager->createImage(640, 480);

    $image->save(Storage::disk('test')->path('1-'.md5('1'.config('app.key')).'/original.png'));

    $manipulator = new ImageManipulator($conversionRegistry, $manager);

    $media = new Media;
    $media->name = 'test image';
    $media->file_name = 'original.png';
    $media->mime_type = 'image/png';
    $media->disk = 'test';
    $media->size = 1;
    $media->save();

    $manipulator->manipulate($media, ['resize'], onlyIfMissing: false);

    $convertedImage = $manager->decode(Storage::disk('test')->path('1-'.md5('1'.config('app.key')).'/conversions/resize/original.png'));
    expect($convertedImage->width())->toBe(64)
        ->and($convertedImage->height())->toBe(50);

});

it('will only apply conversions to an image', function () {
    $registry = new ConversionRegistry;
    $invoked = false;

    $registry->register('resize', function (Image $image) use (&$invoked) {
        $invoked = true;

        return $image->resize(64, 50);
    });

    $manager = new ImageManager(Driver::class);
    $manipulator = new ImageManipulator($registry, $manager);

    $media = new Media;
    $media->name = 'document';
    $media->file_name = 'file.txt';
    $media->mime_type = 'text/plain';
    $media->disk = 'test';
    $media->size = 1;
    $media->save();

    $manipulator->manipulate($media, ['resize'], onlyIfMissing: false);

    expect($invoked)->toBeFalse();
});

it('captures InvalidConversion in the report instead of throwing', function () {
    $registry = new ConversionRegistry;
    $manager = new ImageManager(Driver::class);
    $manipulator = new ImageManipulator($registry, $manager);

    $media = makeImageMedia();
    createOriginalImage($media, $manager);

    $report = $manipulator->manipulate($media, ['does-not-exist'], onlyIfMissing: false);

    expect($report['completed'])->toBe([])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['conversion'])->toBe('does-not-exist')
        ->and($report['failed'][0]['exception'])->toBeInstanceOf(InvalidConversion::class);
});

it('isolates per-conversion failures — surviving conversions still run', function () {
    $registry = new ConversionRegistry;

    // Two valid conversions bracketing a broken one. The broken closure throws
    // mid-batch; pre-isolation this would cancel `last` silently.
    $registry->register('first', fn (Image $image) => $image->resize(50, 50));
    $registry->register('broken', function (Image $image) {
        throw new RuntimeException('encoder blew up');
    });
    $registry->register('last', fn (Image $image) => $image->resize(30, 30));

    $manager = new ImageManager(Driver::class);
    $manipulator = new ImageManipulator($registry, $manager);

    $media = makeImageMedia();
    createOriginalImage($media, $manager);

    $report = $manipulator->manipulate($media, ['first', 'broken', 'last'], onlyIfMissing: false);

    expect($report['completed'])->toBe(['first', 'last'])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['conversion'])->toBe('broken')
        ->and($report['failed'][0]['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and($report['failed'][0]['exception']->getMessage())->toBe('encoder blew up');

    // Both surviving conversions actually wrote to disk (source is PNG, default encode preserves format)
    expect(Storage::disk('test')->exists($media->getDirectory().'/conversions/first/original.png'))->toBeTrue()
        ->and(Storage::disk('test')->exists($media->getDirectory().'/conversions/last/original.png'))->toBeTrue();
});

it('returns an empty report for non-image media', function () {
    $registry = new ConversionRegistry;
    $registry->register('thumb', fn (Image $image) => $image->resize(50, 50));

    $manager = new ImageManager(Driver::class);
    $manipulator = new ImageManipulator($registry, $manager);

    $media = new Media;
    $media->name = 'doc';
    $media->file_name = 'a.pdf';
    $media->mime_type = 'application/pdf';
    $media->disk = 'test';
    $media->size = 1024;
    $media->save();

    expect($manipulator->manipulate($media, ['thumb']))->toBe(['completed' => [], 'failed' => []]);
});

it('writes the encoded variant when a conversion returns an EncodedImage', function () {
    $registry = new ConversionRegistry;

    $registry->register('webp-thumb', function (Image $image) {
        return $image->resize(64, 50)->encode(new WebpEncoder(quality: 80));
    });

    $manager = new ImageManager(Driver::class);
    $manipulator = new ImageManipulator($registry, $manager);

    $media = makeImageMedia();
    createOriginalImage($media, $manager);

    $manipulator->manipulate($media, ['webp-thumb'], onlyIfMissing: false);

    $expectedPath = $media->getDirectory().'/conversions/webp-thumb/original.webp';
    expect(Storage::disk('test')->exists($expectedPath))->toBeTrue();
});

it('skips writing an EncodedImage variant when output already exists with onlyIfMissing', function () {
    $registry = new ConversionRegistry;
    $invocations = 0;

    $registry->register('webp-thumb', function (Image $image) use (&$invocations) {
        $invocations++;

        return $image->resize(64, 50)->encode(new WebpEncoder(quality: 80));
    });

    $manager = new ImageManager(Driver::class);
    $manipulator = new ImageManipulator($registry, $manager);

    $media = makeImageMedia();
    createOriginalImage($media, $manager);

    $manipulator->manipulate($media, ['webp-thumb'], onlyIfMissing: true);
    $conversionPath = $media->getDirectory().'/conversions/webp-thumb/original.webp';
    $firstMtime = Storage::disk('test')->lastModified($conversionPath);

    sleep(1);
    $manipulator->manipulate($media, ['webp-thumb'], onlyIfMissing: true);

    expect(Storage::disk('test')->lastModified($conversionPath))->toBe($firstMtime)
        ->and($invocations)->toBe(2);
});

it('will skip conversions if the converted image already exists', function () {
    $registry = new ConversionRegistry;
    $invocations = 0;

    $registry->register('resize', function (Image $image) use (&$invocations) {
        $invocations++;

        return $image->resize(64, 50);
    });

    $manager = new ImageManager(Driver::class);
    $manipulator = new ImageManipulator($registry, $manager);

    $media = makeImageMedia();
    createOriginalImage($media, $manager);

    // First run creates the output
    $manipulator->manipulate($media, ['resize'], onlyIfMissing: true);
    $conversionPath = $media->getDirectory().'/conversions/resize/original.png';
    expect(Storage::disk('test')->exists($conversionPath))->toBeTrue();
    $firstMtime = Storage::disk('test')->lastModified($conversionPath);

    // Second run with onlyIfMissing=true must not overwrite the existing file
    sleep(1);
    $manipulator->manipulate($media, ['resize'], onlyIfMissing: true);
    expect(Storage::disk('test')->lastModified($conversionPath))->toBe($firstMtime);

    // The closure still runs (encode happens before exists check), but writes are skipped
    expect($invocations)->toBe(2);
});
