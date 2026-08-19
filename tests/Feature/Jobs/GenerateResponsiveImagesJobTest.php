<?php

use Emaia\MediaMan\Events\ResponsiveImagesGenerated;
use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationResult;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationStatus;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Intervention\Image\ImageManager;

it('exposes the media and options it was constructed with', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();
    $options = ['quality' => 80, 'formats' => ['webp']];

    $job = new GenerateResponsiveImages($media, $options);

    expect($job->getMedia()->id)->toEqual($media->id)
        ->and($job->getOptions())->toEqual($options);
});

it('uses the configured media queue connection from every dispatch site', function () {
    Config::set('mediaman.queue', 'media-queue');
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect((new GenerateResponsiveImages($media))->connection)->toBe('media-queue');
});

it('defaults options to an empty array', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $job = new GenerateResponsiveImages($media);

    expect($job->getOptions())->toEqual([]);
});

it('delegates handling to the generator and fires an event', function () {
    Event::fake([ResponsiveImagesGenerated::class]);

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();
    $options = ['widths' => [200], 'formats' => ['jpg']];

    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('generateResponsiveImages')
        ->once()
        ->with(Mockery::on(fn ($m) => $m->id === $media->id), $options)
        ->andReturn(new ResponsiveGenerationResult(
            ResponsiveGenerationStatus::Published,
            published: 1,
        ));

    (new GenerateResponsiveImages($media, $options))->handle($generator);

    Event::assertDispatched(
        ResponsiveImagesGenerated::class,
        fn (ResponsiveImagesGenerated $event) => $event->result?->wasPublished() === true,
    );
});

it('does not fire the generated event for a no-op result', function () {
    Event::fake([ResponsiveImagesGenerated::class]);
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();
    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('generateResponsiveImages')
        ->once()
        ->andReturn(ResponsiveGenerationResult::noOp('source-missing'));

    (new GenerateResponsiveImages($media))->handle($generator);

    Event::assertNotDispatched(ResponsiveImagesGenerated::class);
});

it('supports legacy generator subclasses with a void override', function () {
    Event::fake([ResponsiveImagesGenerated::class]);
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();
    $generator = new class(app(ImageManager::class), app(WidthCalculator::class)) extends ResponsiveImageGenerator
    {
        public function generateResponsiveImages(Media $media, array $options = []): void {}
    };

    (new GenerateResponsiveImages($media))->handle($generator);

    Event::assertDispatched(
        ResponsiveImagesGenerated::class,
        fn (ResponsiveImagesGenerated $event) => $event->result === null,
    );
});
