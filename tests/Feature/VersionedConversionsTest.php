<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Conversions\ConversionClearer;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Image;

beforeEach(function () {
    Config::set('mediaman.conversions.versioning', 'generation');
});

function uploadVersionedConversionMedia(): Media
{
    return MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
}

function registerVersionedThumb(?string $disk = null): void
{
    Conversion::register(
        'thumb',
        fn (Image $image) => $image->cover(320, 240)->encode(new WebpEncoder(quality: 80)),
        disk: $disk,
    );
}

it('preserves the exact legacy conversion path when versioning is disabled', function () {
    Config::set('mediaman.conversions.versioning', false);
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();

    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);

    $path = $media->getDirectory().'/conversions/thumb/photo.webp';
    expect($media->getPath('thumb'))->toBe($path)
        ->and(Storage::disk($media->disk)->exists($path))->toBeTrue()
        ->and($media->fresh()->hasCustomProperty(Media::PROPERTY_CONVERSION_FILES))->toBeFalse();
});

it('publishes a validated conversion manifest after the generated file exists', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();

    $report = app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');

    expect($report['completed'])->toBe(['thumb'])
        ->and($report['failed'])->toBe([])
        ->and($active)->not->toBeNull()
        ->and($active['generation'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($active['path'])->toBe($media->getDirectory().'/conversions/thumb/'.$active['generation'].'/photo.webp')
        ->and($active['disk'])->toBe($media->disk)
        ->and($active['format'])->toBe('webp')
        ->and($active['mime_type'])->toBe('image/webp')
        ->and($active['size'])->toBeGreaterThan(0)
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeTrue()
        ->and(Storage::disk($active['disk'])->exists(
            dirname($active['path']).'/'.ImageManipulator::IN_PROGRESS_MARKER
        ))->toBeFalse()
        ->and($media->getPath('thumb'))->toBe($active['path'])
        ->and($media->hasConversion('thumb'))->toBeTrue();
});

it('keeps the previous generation when a forced regeneration publishes a new one', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    $first = $media->getConversionFile('thumb');

    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    $second = $media->getConversionFile('thumb');

    expect($second['generation'])->not->toBe($first['generation'])
        ->and($second['path'])->not->toBe($first['path'])
        ->and(Storage::disk($first['disk'])->exists($first['path']))->toBeTrue()
        ->and(Storage::disk($second['disk'])->exists($second['path']))->toBeTrue();
});

it('keeps independent active generations for each conversion name', function () {
    registerVersionedThumb();
    Conversion::register('cover', fn (Image $image) => $image->cover(640, 320));
    $media = uploadVersionedConversionMedia();

    app(ImageManipulator::class)->manipulate($media, ['thumb', 'cover'], onlyIfMissing: false);

    $files = $media->conversionFiles();
    expect($files)->toHaveKeys(['thumb', 'cover'])
        ->and($files['thumb']['generation'])->not->toBe($files['cover']['generation'])
        ->and(Storage::disk($files['thumb']['disk'])->exists($files['thumb']['path']))->toBeTrue()
        ->and(Storage::disk($files['cover']['disk'])->exists($files['cover']['path']))->toBeTrue();
});

it('reads a published conversion after its registration and write disk change', function () {
    Storage::fake('conversion-old');
    Storage::fake('conversion-new');
    registerVersionedThumb('conversion-old');
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');

    $registry = new ConversionRegistry;
    $registry->register('thumb', fn (Image $image) => $image->cover(100, 100), disk: 'conversion-new');
    app()->instance(ConversionRegistry::class, $registry);

    expect($media->getConversionDisk('thumb'))->toBe('conversion-old')
        ->and($media->getConversionWriteDisk('thumb'))->toBe('conversion-new')
        ->and($media->getPath('thumb'))->toBe($active['path'])
        ->and($media->hasConversion('thumb'))->toBeTrue();

    app()->instance(ConversionRegistry::class, new ConversionRegistry);

    expect($media->getConversionDisk('thumb'))->toBe('conversion-old')
        ->and($media->getPath('thumb'))->toBe($active['path'])
        ->and($media->hasConversion('thumb'))->toBeTrue();
});

it('merges publication into fresh custom properties', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $stale = $media->fresh();
    $media->setCustomProperty('application_state', 'new')->save();

    app(ImageManipulator::class)->manipulate($stale, ['thumb'], onlyIfMissing: false);

    expect($stale->getCustomProperty('application_state'))->toBe('new')
        ->and($stale->getConversionFile('thumb'))->not->toBeNull();
});

it('removes the active manifest only after a successful legacy replacement', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    $versioned = $media->getConversionFile('thumb');
    Config::set('mediaman.conversions.versioning', false);

    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    $legacyPath = $media->getDirectory().'/conversions/thumb/photo.webp';

    expect($media->getConversionFile('thumb'))->toBeNull()
        ->and($media->getPath('thumb'))->toBe($legacyPath)
        ->and(Storage::disk($media->disk)->exists($legacyPath))->toBeTrue()
        ->and(Storage::disk($versioned['disk'])->exists($versioned['path']))->toBeTrue();
});

it('clears one conversion without affecting another active generation', function () {
    registerVersionedThumb();
    Conversion::register('cover', fn (Image $image) => $image->cover(640, 320));
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb', 'cover'], onlyIfMissing: false);
    $thumb = $media->getConversionFile('thumb');
    $cover = $media->getConversionFile('cover');

    expect(app(ConversionClearer::class)->clear($media, 'thumb'))->toBeTrue();

    expect($media->getConversionFile('thumb'))->toBeNull()
        ->and($media->getConversionFile('cover'))->toBe($cover)
        ->and(Storage::disk($thumb['disk'])->exists($thumb['path']))->toBeFalse()
        ->and(Storage::disk($cover['disk'])->exists($cover['path']))->toBeTrue()
        ->and($media->hasCustomProperty(Media::PROPERTY_CONVERSION_CLEARING))->toBeFalse()
        ->and($media->getCustomProperty(Media::PROPERTY_CONVERSION_GENERATION_EPOCHS.'.thumb'))->toBe(1);
});

it('prevents a conversion started before clear from publishing afterward', function () {
    $media = uploadVersionedConversionMedia();
    Conversion::register('thumb', function (Image $image) use ($media) {
        app(ConversionClearer::class)->clear($media, 'thumb');

        return $image->cover(320, 240);
    });

    expect(fn () => app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false))
        ->toThrow(RuntimeException::class, 'changed while conversion [thumb] was being generated');

    expect($media->fresh()->getConversionFile('thumb'))->toBeNull()
        ->and(Storage::disk($media->disk)->allFiles(
            $media->getDirectory().'/conversions/thumb'
        ))->toBe([]);
});

it('copies only active versioned conversions and rebuilds target metadata', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    $inactive = $media->getConversionFile('thumb');
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    $copy = $media->copy(Subject::create());
    $copied = $copy->getConversionFile('thumb');

    expect($copied)->not->toBeNull()
        ->and($copied['generation'])->toBe($active['generation'])
        ->and($copied['path'])->toStartWith($copy->getDirectory().'/conversions/thumb/')
        ->and($copied['path'])->not->toBe($active['path'])
        ->and(Storage::disk($copied['disk'])->exists($copied['path']))->toBeTrue()
        ->and(Storage::disk($copied['disk'])->exists(str_replace(
            $media->getDirectory(),
            $copy->getDirectory(),
            $inactive['path'],
        )))->toBeFalse();
});

it('copies and deletes active conversion files after registration removal', function () {
    Storage::fake('conversion-archive');
    registerVersionedThumb('conversion-archive');
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    app()->instance(ConversionRegistry::class, new ConversionRegistry);

    $copy = $media->copy(Subject::create());
    $copied = $copy->getConversionFile('thumb');

    expect($copied['disk'])->toBe('conversion-archive')
        ->and(Storage::disk('conversion-archive')->exists($copied['path']))->toBeTrue();

    $media->forceDelete();

    expect(Storage::disk('conversion-archive')->exists($active['path']))->toBeFalse()
        ->and(Storage::disk('conversion-archive')->exists($copied['path']))->toBeTrue();
});

it('prevents a legacy writer started before clear from recreating the conversion', function () {
    Config::set('mediaman.conversions.versioning', false);
    $media = uploadVersionedConversionMedia();
    Conversion::register('thumb', function (Image $image) use ($media) {
        app(ConversionClearer::class)->clear($media, 'thumb');

        return $image->cover(320, 240);
    });

    expect(fn () => app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false))
        ->toThrow(RuntimeException::class, 'changed while conversion [thumb] was being generated');

    expect(Storage::disk($media->disk)->exists(
        $media->getDirectory().'/conversions/thumb/photo.jpg'
    ))->toBeFalse();
});

it('rejects tampered conversion metadata before using its path or disk', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    $properties = $media->custom_properties;
    $properties[Media::PROPERTY_CONVERSION_FILES]['thumb']['disk'] = 'forged';
    $media->custom_properties = $properties;

    expect($media->getConversionFile('thumb'))->toBeNull()
        ->and($media->getPath('thumb'))->not->toBe($active['path']);
});

it('does not replace an active generation with a stale legacy file in non-force mode', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    Config::set('mediaman.conversions.versioning', false);
    $legacyPath = $media->getDirectory().'/conversions/thumb/photo.webp';
    Storage::disk($media->disk)->put($legacyPath, 'stale');

    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: true);

    expect($media->getConversionFile('thumb'))->toBe($active)
        ->and(Storage::disk($media->disk)->get($legacyPath))->toBe('stale');
});

it('keeps legacy conversion generation free of model saves', function () {
    Config::set('mediaman.conversions.versioning', false);
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $saves = 0;
    Media::saving(function () use (&$saves): void {
        $saves++;
    });

    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);

    expect($saves)->toBe(0);
});

it('reports unsafe conversion names as failures only in versioned mode', function () {
    app(ConversionRegistry::class)->register('../thumb', fn (Image $image) => $image);
    $media = uploadVersionedConversionMedia();

    $report = app(ImageManipulator::class)->manipulate($media, ['../thumb'], onlyIfMissing: false);

    expect($report['completed'])->toBe([])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['exception'])->toBeInstanceOf(InvalidArgumentException::class);
});
