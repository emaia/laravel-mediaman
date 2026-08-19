<?php

use Emaia\MediaMan\Events\ConversionCompleted;
use Emaia\MediaMan\Events\ConversionFailed;
use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Events\ResponsiveImagesGenerated;
use Emaia\MediaMan\Exceptions\InvalidConversion;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

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

it('dispatches ConversionCompleted event with the conversions that succeeded', function () {
    Event::fake(ConversionCompleted::class);

    Conversion::register('thumb', fn ($img) => $img->resize(100, 100));

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $job = new PerformConversions($media, ['thumb']);
    app()->call([$job, 'handle']);

    Event::assertDispatched(ConversionCompleted::class, function ($event) use ($media) {
        return $event->media->id === $media->id
            && $event->conversions === ['thumb'];
    });
});

it('defaults force to false when hydrating a job payload created before the property existed', function () {
    $job = (new ReflectionClass(PerformConversions::class))->newInstanceWithoutConstructor();

    expect($job->isForced())->toBeFalse();
});

it('preserves queued force and non-force conversion semantics', function () {
    Event::fake(ConversionCompleted::class);
    config(['mediaman.conversions.versioning' => 'generation']);
    $invocations = 0;
    Conversion::register('thumb', function ($image) use (&$invocations) {
        $invocations++;

        return $image->resize(100, 100);
    });
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $forced = new PerformConversions($media, ['thumb'], force: true);
    app()->call([$forced, 'handle']);
    $first = $media->getConversionFile('thumb');

    $nonForced = new PerformConversions($media, ['thumb']);
    app()->call([$nonForced, 'handle']);

    expect($nonForced->isForced())->toBeFalse()
        ->and($invocations)->toBe(1)
        ->and($media->getConversionFile('thumb'))->toBe($first);

    app()->call([$forced, 'handle']);

    expect($forced->isForced())->toBeTrue()
        ->and($invocations)->toBe(2)
        ->and($media->getConversionFile('thumb')['generation'])->not->toBe($first['generation']);
});

it('logs and rethrows on all-failed but defers ConversionFailed until retries exhaust', function () {
    Event::fake([ConversionCompleted::class, ConversionFailed::class]);
    Log::spy();

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $job = new PerformConversions($media, ['not-registered']);

    expect(fn () => app()->call([$job, 'handle']))
        ->toThrow(RuntimeException::class, 'all 1 conversion(s) failed for media #'.$media->id);

    // No events during handle() — the queue is still in retry territory.
    Event::assertNotDispatched(ConversionCompleted::class);
    Event::assertNotDispatched(ConversionFailed::class);

    // Log per-attempt observability still fires.
    Log::shouldHaveReceived('warning')->with(
        'MediaMan: Conversion failed',
        Mockery::on(fn ($ctx) => $ctx['mediaId'] === $media->id
            && $ctx['conversion'] === 'not-registered'),
    );

    // failed() hook fires the deferred event — listener now sees "queue gave up".
    $job->failed(new RuntimeException('outer'));

    Event::assertDispatched(ConversionFailed::class, function ($event) use ($media) {
        return $event->media->id === $media->id
            && $event->conversion === 'not-registered'
            && $event->exception instanceof InvalidConversion;
    });
});

it('defers manifest publication failures until conversion job retries exhaust', function () {
    Event::fake([ConversionCompleted::class, ConversionFailed::class]);
    config(['mediaman.conversions.versioning' => 'generation']);
    Conversion::register('thumb', fn ($image) => $image->resize(100, 100));
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    Media::saving(function (Media $saving): void {
        if ($saving->hasCustomProperty(Media::PROPERTY_CONVERSION_FILES)) {
            throw new RuntimeException('manifest unavailable');
        }
    });
    $job = new PerformConversions($media, ['thumb']);
    $failure = null;

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure->getMessage())->toBe('manifest unavailable');
    Event::assertNotDispatched(ConversionCompleted::class);
    Event::assertNotDispatched(ConversionFailed::class);

    $job->failed($failure);

    Event::assertDispatched(ConversionFailed::class, fn ($event) => $event->conversion === 'thumb'
        && $event->exception === $failure);
});

it('does not rethrow on partial-batch failures — surviving conversions ship', function () {
    Event::fake([ConversionCompleted::class, ConversionFailed::class]);

    Conversion::register('thumb', fn ($img) => $img->resize(100, 100));
    // 'missing' is not registered → fails per-iteration

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $job = new PerformConversions($media, ['thumb', 'missing']);
    app()->call([$job, 'handle']);   // should NOT throw

    Event::assertDispatched(ConversionCompleted::class, function ($event) use ($media) {
        return $event->media->id === $media->id
            && $event->conversions === ['thumb'];
    });
    Event::assertDispatched(ConversionFailed::class, function ($event) use ($media) {
        return $event->media->id === $media->id
            && $event->conversion === 'missing';
    });
});

it('ConversionFailed::reschedule queues a single-conversion PerformConversions with delay', function () {
    Bus::fake();

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $event = new ConversionFailed($media, 'thumb', new RuntimeException('blip'));
    $event->reschedule(120);

    Bus::assertDispatched(PerformConversions::class, function ($job) use ($media) {
        // The job is constructed with the single failed conversion.
        $reflect = new ReflectionProperty($job, 'conversions');
        $reflect->setAccessible(true);
        $conversions = $reflect->getValue($job);

        $reflectMedia = new ReflectionProperty($job, 'media');
        $reflectMedia->setAccessible(true);

        return $conversions === ['thumb']
            && $reflectMedia->getValue($job)->id === $media->id
            && $job->delay !== null;   // delay was applied
    });
});

it('dispatches ResponsiveImagesGenerated event after responsive images are generated', function () {
    Event::fake(ResponsiveImagesGenerated::class);

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $options = ['quality' => 80];
    $job = new GenerateResponsiveImages($media, $options);
    app()->call([$job, 'handle']);

    Event::assertDispatched(ResponsiveImagesGenerated::class, function ($event) use ($media, $options) {
        return $event->media->id === $media->id
            && $event->options === $options
            && $event->result?->wasPublished() === true;
    });
});

it('uses the configured media queue connection for conversion jobs', function () {
    config(['mediaman.queue' => 'media-queue']);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect((new PerformConversions($media, ['thumb']))->connection)->toBe('media-queue');
});
