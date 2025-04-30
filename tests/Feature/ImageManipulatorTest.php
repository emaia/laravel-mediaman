<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Models\Media;
use Intervention\Image\Drivers\Gd\Driver;
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

it('it will apply registered conversions', function () {
    $conversionRegistry = new ConversionRegistry();

    $conversionRegistry->register('resize', function (Image $image) {
        return $image->resize(64, 50);
    });

    $manager = new ImageManager(Driver::class);

    $image = $manager->create(640, 480);

    $image->save(Storage::disk('test')->path('1-'.md5('1'.config('app.key')).'/original.png'));

    $manipulator = new ImageManipulator($conversionRegistry, $manager);

    $media = new Media();
    $media->name = 'test image';
    $media->file_name = 'original.png';
    $media->mime_type = 'image/png';
    $media->disk = 'test';
    $media->size = 1;
    $media->save();

    $manipulator->manipulate($media, ['resize'], onlyIfMissing: false);

    $convertedImage = $manager->read(Storage::disk('test')->path('1-'.md5('1'.config('app.key')).'/conversions/resize/original.png'));
    expect($convertedImage->width())->toBe(64)
        ->and($convertedImage->height())->toBe(50);

});

it('will only apply conversions to an image', function () {
//    $conversionRegistry = new ConversionRegistry();
//
//    $conversionRegistry->register('resize', function ($image) {
//        return $image->resize(64);
//    });
//
//    $imageManager = Mockery::mock(ImageManager::class);
//
//    // Assert that the conversion was not applied...
//    $imageManager->shouldNotReceive('make');
//
//    $manipulator = new ImageManipulator($conversionRegistry, $imageManager);
//
//    $media = new Media(['mime_type' => 'text/html']);
//
//    $manipulator->manipulate($media, ['resize'], $onlyIfMissing = false);
})->todo();

it('will ignore unregistered conversions', function () {
//    $this->expectException(InvalidConversion::class);
//
//    $conversionRegistry = new ConversionRegistry();
//
//    $imageManager = Mockery::mock(ImageManager::class);
//
//    // Assert that the conversion was not applied...
//    $imageManager->shouldNotReceive('make');
//
//    $manipulator = new ImageManipulator($conversionRegistry, $imageManager);
//
//    $media = new Media(['mime_type' => 'image/png']);
//
//    $manipulator->manipulate($media, ['unknown'], $onlyIfMissing = false);
})->todo();

it('will skip conversions if the converted image already exists', function() {
//    $conversionRegistry = new ConversionRegistry();
//
//    $conversionRegistry->register('resize', function (Image $image) use (&$conversionApplied) {
//        return $image;
//    });
//
//    $imageManager = Mockery::mock(ImageManager::class);
//
//    // Assert that the conversion was not applied...
//    $imageManager->shouldNotReceive('make');
//
//    $manipulator = new ImageManipulator($conversionRegistry, $imageManager);
//
//    /** @var \FarhanShares\MediaMan\Models\Media|MockInterface $media */
//    $media = Mockery::mock(Media::class)->makePartial();
//    $media->file_name = 'file-name.png';
//    $media->mime_type = 'image/png';
//
//    $filesystem = Mockery::mock(Filesystem::class);
//
//    // Mock that the file already exists...
//    $filesystem->shouldReceive('exists')->with($media->getPath('resize'))->once()->andReturn(true);
//
//    $media->shouldReceive('filesystem')->once()->andReturn($filesystem);
//
//    $manipulator->manipulate($media, ['resize']);
})->todo();
