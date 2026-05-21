<?php

use Emaia\MediaMan\Events\ResponsiveImagesGenerated;
use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;

it('exposes the media and options it was constructed with', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();
    $options = ['quality' => 80, 'formats' => ['webp']];

    $job = new GenerateResponsiveImages($media, $options);

    expect($job->getMedia()->id)->toEqual($media->id)
        ->and($job->getOptions())->toEqual($options);
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
        ->with(Mockery::on(fn ($m) => $m->id === $media->id), $options);

    (new GenerateResponsiveImages($media, $options))->handle($generator);

    Event::assertDispatched(ResponsiveImagesGenerated::class);
});
