<?php

use Emaia\MediaMan\Events\ConversionCompleted;
use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Events\ResponsiveImagesGenerated;
use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;

it('dispatches MediaUploaded event when a file is uploaded', function () {
    Event::fake(MediaUploaded::class);

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    Event::assertDispatched(MediaUploaded::class, function ($event) use ($media) {
        return $event->media->id === $media->id;
    });
});

it('dispatches MediaDeleted event when a media is deleted', function () {
    Event::fake(MediaDeleted::class);

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $mediaId = $media->id;
    $media->delete();

    Event::assertDispatched(MediaDeleted::class, function ($event) use ($mediaId) {
        return $event->media->id === $mediaId;
    });
});

it('dispatches ConversionCompleted event after conversions are performed', function () {
    Event::fake(ConversionCompleted::class);

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $conversions = [];
    $job = new PerformConversions($media, $conversions);
    $job->handle();

    Event::assertDispatched(ConversionCompleted::class, function ($event) use ($media, $conversions) {
        return $event->media->id === $media->id
            && $event->conversions === $conversions;
    });
});

it('dispatches ResponsiveImagesGenerated event after responsive images are generated', function () {
    Event::fake(ResponsiveImagesGenerated::class);

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $options = ['quality' => 80];
    $job = new GenerateResponsiveImages($media, $options);
    $job->handle();

    Event::assertDispatched(ResponsiveImagesGenerated::class, function ($event) use ($media, $options) {
        return $event->media->id === $media->id
            && $event->options === $options;
    });
});
