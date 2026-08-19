<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Conversions\ConversionClearer;
use Emaia\MediaMan\Conversions\ConversionManifest;
use Emaia\MediaMan\Conversions\ConversionPath;
use Emaia\MediaMan\Exceptions\ConversionFormatNotSupported;
use Emaia\MediaMan\Exceptions\MediaFileWriteFailed;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Support\SigningKeys;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\EncodedImage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;

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

function conversionManipulatorBeforePublish(Closure $callback): ImageManipulator
{
    return new class(app(ConversionRegistry::class), app(ImageManager::class), $callback) extends ImageManipulator
    {
        public function __construct(
            ConversionRegistry $conversionRegistry,
            ImageManager $imageManager,
            private readonly Closure $beforePublish,
        ) {
            parent::__construct($conversionRegistry, $imageManager);
        }

        protected function publishVersionedConversions(Media $media, array $candidates): Media
        {
            ($this->beforePublish)($media, $candidates);

            return parent::publishVersionedConversions($media, $candidates);
        }
    };
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
    Conversion::register('thumb', fn (Image $image) => $image->cover(320, 240));
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    Config::set('mediaman.conversions.versioning', false);
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

it('keeps concurrent clear authoritative when a stale legacy writer recreates a pre-existing file', function () {
    Conversion::register('thumb', fn (Image $image) => $image->cover(320, 240));
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    Config::set('mediaman.conversions.versioning', false);
    $legacyPath = $media->getDirectory().'/conversions/thumb/photo.jpg';
    Storage::disk($media->disk)->put($legacyPath, 'existing');
    Conversion::register('thumb', function (Image $image) use ($media) {
        app(ConversionClearer::class)->clear($media, 'thumb');

        return $image->cover(320, 240);
    });

    expect(fn () => app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false))
        ->toThrow(RuntimeException::class, 'changed while conversion [thumb] was being generated');

    expect(Storage::disk($media->disk)->exists($legacyPath))->toBeFalse();
});

it('preserves a pre-existing legacy file when publication fails without a clear', function () {
    Conversion::register('thumb', fn (Image $image) => $image->cover(320, 240));
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    Config::set('mediaman.conversions.versioning', false);
    $legacyPath = $media->getDirectory().'/conversions/thumb/photo.jpg';
    Storage::disk($media->disk)->put($legacyPath, 'existing');
    Conversion::register('thumb', function (Image $image) use ($media) {
        $media->newQuery()->whereKey($media->getKey())->update(['file_name' => 'renamed.jpg']);

        return $image->cover(320, 240);
    });

    expect(fn () => app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false))
        ->toThrow(RuntimeException::class, 'changed while conversion [thumb] was being generated');

    expect(Storage::disk($media->disk)->exists($legacyPath))->toBeTrue();
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

it('rejects a validly signed entry stored under another conversion segment', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $properties = $media->custom_properties;
    $properties[Media::PROPERTY_CONVERSION_FILES]['thumb']['path'] = str_replace(
        '/conversions/thumb/',
        '/conversions/cover/',
        $properties[Media::PROPERTY_CONVERSION_FILES]['thumb']['path'],
    );
    $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
        $media,
        $properties[Media::PROPERTY_CONVERSION_FILES],
        $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS],
    );
    $media->custom_properties = $properties;

    expect($media->getConversionFile('thumb'))->toBeNull();
});

it('applies conversion-name grammar consistently when listing signed entries', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $properties = $media->custom_properties;
    $properties[Media::PROPERTY_CONVERSION_FILES]['../thumb'] =
        $properties[Media::PROPERTY_CONVERSION_FILES]['thumb'];
    unset($properties[Media::PROPERTY_CONVERSION_FILES]['thumb']);
    $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
        $media,
        $properties[Media::PROPERTY_CONVERSION_FILES],
        $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS],
    );
    $media->custom_properties = $properties;

    expect($media->conversionFiles())->toBe([]);
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

it('keeps unsafe legacy conversion names working with a deprecation warning', function () {
    Config::set('mediaman.conversions.versioning', false);
    app(ConversionRegistry::class)->register('thumb large', fn (Image $image) => $image->cover(320, 240));
    $media = uploadVersionedConversionMedia();
    Log::spy();

    $report = app(ImageManipulator::class)->manipulate($media, ['thumb large'], onlyIfMissing: false);

    expect($report['completed'])->toBe(['thumb large'])
        ->and($media->hasConversion('thumb large'))->toBeTrue();
    Log::shouldHaveReceived('warning')->once()->with(
        'MediaMan: Unsafe legacy conversion name is deprecated',
        ['conversion' => 'thumb large'],
    );
});

it('keeps failed active entries while publishing successful conversions in a partial batch', function () {
    registerVersionedThumb();
    Conversion::register('cover', fn (Image $image) => $image->cover(640, 320));
    $media = uploadVersionedConversionMedia();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb', 'cover'], onlyIfMissing: false);
    $oldThumb = $media->getConversionFile('thumb');
    $oldCover = $media->getConversionFile('cover');
    registerVersionedThumb();
    Conversion::register('cover', fn () => throw new RuntimeException('cover failed'));

    $report = $manipulator->manipulate($media, ['thumb', 'cover'], onlyIfMissing: false);

    expect($report['completed'])->toBe(['thumb'])
        ->and($report['failed'])->toHaveCount(1)
        ->and($media->getConversionFile('thumb')['generation'])->not->toBe($oldThumb['generation'])
        ->and($media->getConversionFile('cover'))->toBe($oldCover);
});

it('merges different conversion names generated from stale model instances', function () {
    registerVersionedThumb();
    Conversion::register('cover', fn (Image $image) => $image->cover(640, 320));
    $media = uploadVersionedConversionMedia();
    $thumbWorker = $media->fresh();
    $coverWorker = $media->fresh();

    app(ImageManipulator::class)->manipulate($thumbWorker, ['thumb'], onlyIfMissing: false);
    app(ImageManipulator::class)->manipulate($coverWorker, ['cover'], onlyIfMissing: false);

    expect($media->fresh()->conversionFiles())->toHaveKeys(['thumb', 'cover']);
});

it('publishes one complete active entry when stale workers generate the same conversion', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $workerA = $media->fresh();
    $workerB = $media->fresh();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($workerA, ['thumb'], onlyIfMissing: false);
    $generationA = $workerA->getConversionFile('thumb');
    $manipulator->manipulate($workerB, ['thumb'], onlyIfMissing: false);
    $active = $media->fresh()->getConversionFile('thumb');

    expect($active['generation'])->not->toBe($generationA['generation'])
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeTrue()
        ->and(dirname($active['path']))->toEndWith($active['generation']);
});

it('scopes conversion clearing guards to the selected conversion name', function () {
    registerVersionedThumb();
    Conversion::register('cover', fn (Image $image) => $image->cover(640, 320));
    $media = uploadVersionedConversionMedia();
    $media->setCustomProperty(Media::PROPERTY_CONVERSION_CLEARING, [
        'thumb' => ['token' => 'blocked'],
    ])->save();

    $report = app(ImageManipulator::class)->manipulate($media, ['cover'], onlyIfMissing: false);

    expect($report['completed'])->toBe(['cover'])
        ->and($media->getConversionFile('cover'))->not->toBeNull();
});

it('keeps signed conversion paths readable across app key rotation', function () {
    $oldKey = config('app.key');
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    Config::set('app.key', 'base64:bmV3LWtleS1mb3ItY29udmVyc2lvbi10ZXN0');
    Config::set('app.previous_keys', [$oldKey]);
    $rotated = $media->fresh();

    expect($rotated->getConversionFile('thumb'))->toBe($active)
        ->and($rotated->getPath('thumb'))->toBe($active['path'])
        ->and($rotated->hasConversion('thumb'))->toBeTrue();
});

it('clears the signed active path after app key rotation', function () {
    $oldKey = config('app.key');
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    Config::set('app.key', 'base64:bmV3LWtleS1mb3ItY29udmVyc2lvbi10ZXN0');
    Config::set('app.previous_keys', [$oldKey]);
    $rotated = $media->fresh();

    expect(app(ConversionClearer::class)->clear($rotated, 'thumb'))->toBeTrue()
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeFalse()
        ->and($rotated->getConversionFile('thumb'))->toBeNull();
});

it('warns once per model state when a non-empty manifest signature is invalid', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $properties = $media->custom_properties;
    $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = 'invalid';
    $media->custom_properties = $properties;
    Log::spy();

    expect($media->getConversionFile('thumb'))->toBeNull()
        ->and($media->getConversionFile('thumb'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('MediaMan: Conversion manifest signature is invalid', ['mediaId' => $media->getKey()]);
});

it('reports invalid conversion manifest signatures in doctor and stats', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $properties = $media->custom_properties;
    $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = 'invalid';
    $media->custom_properties = $properties;
    $media->save();

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('Invalid conversion manifests')
        ->assertExitCode(1);

    $this->artisan('mediaman:stats', ['--conversions' => true])
        ->expectsOutputToContain('Invalid manifests')
        ->assertExitCode(0);
});

it('uses active conversion disk filename and mime metadata across read APIs', function () {
    Storage::fake('conversion-read');
    Storage::disk('conversion-read')->buildTemporaryUrlsUsing(
        fn (string $path) => 'https://temporary.test/'.$path,
    );
    registerVersionedThumb('conversion-read');
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');

    $stream = $media->getStream('thumb');
    $bytes = stream_get_contents($stream);
    fclose($stream);
    $attachment = $media->mailAttachment('thumb');
    $download = $media->toResponse('thumb');
    $inline = $media->toInlineResponse('thumb');

    expect($media->getFullPath('thumb'))->toBe(Storage::disk('conversion-read')->path($active['path']))
        ->and($bytes)->toBe(Storage::disk('conversion-read')->get($active['path']))
        ->and($media->getTemporaryUrl(conversion: 'thumb'))->toBe('https://temporary.test/'.$active['path'])
        ->and($download->headers->get('content-disposition'))->toContain($active['file_name'])
        ->and($inline->headers->get('content-disposition'))->toContain($active['file_name'])
        ->and($attachment->as)->toBe($active['file_name'])
        ->and($attachment->mime)->toBe($active['mime_type']);
});

it('reports zero-byte encoded conversions as unsupported instead of storage failures', function () {
    Conversion::register('empty', fn () => new EncodedImage('', 'image/webp'));
    $media = uploadVersionedConversionMedia();

    $report = app(ImageManipulator::class)->manipulate($media, ['empty'], onlyIfMissing: false);

    expect($report['completed'])->toBe([])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['exception'])->toBeInstanceOf(ConversionFormatNotSupported::class);
});

it('retries a persisted conversion clear tombstone after APP_KEY rotation', function () {
    $oldKey = config('app.key');
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    $realFilesystem = Storage::disk($active['disk']);
    $failingFilesystem = Mockery::mock(Filesystem::class);
    $failingFilesystem->shouldReceive('exists')->once()->andReturn(true);
    $failingFilesystem->shouldReceive('deleteDirectory')->once()->andReturn(false);
    Storage::set($active['disk'], $failingFilesystem);

    expect(fn () => app(ConversionClearer::class)->clear($media, 'thumb'))
        ->toThrow(RuntimeException::class, 'Failed to delete conversion');

    expect($media->fresh()->getCustomProperty(Media::PROPERTY_CONVERSION_CLEARING.'.thumb'))->toBeArray();

    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    Config::set('app.previous_keys', [$oldKey]);
    Storage::set($active['disk'], $realFilesystem);

    expect(app(ConversionClearer::class)->clear($media->fresh(), 'thumb'))->toBeTrue()
        ->and($media->fresh()->hasCustomProperty(Media::PROPERTY_CONVERSION_CLEARING))->toBeFalse()
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeFalse();
});

it('captures a persisted conversion clear tombstone during force delete', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    $realFilesystem = Storage::disk($active['disk']);
    $failingFilesystem = Mockery::mock(Filesystem::class);
    $failingFilesystem->shouldReceive('exists')->once()->andReturn(true);
    $failingFilesystem->shouldReceive('deleteDirectory')->once()->andReturn(false);
    Storage::set($active['disk'], $failingFilesystem);

    expect(fn () => app(ConversionClearer::class)->clear($media, 'thumb'))
        ->toThrow(RuntimeException::class, 'Failed to delete conversion');

    Storage::set($active['disk'], $realFilesystem);
    $media->fresh()->forceDelete();

    expect(Media::find($media->getKey()))->toBeNull()
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeFalse();
});

it('rejects malformed entries even when the conversion manifest is validly signed', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $original = $media->custom_properties;
    $mutations = [
        'missing field' => fn (array $entry) => array_diff_key($entry, ['mime_type' => true]),
        'non-positive size' => fn (array $entry) => [...$entry, 'size' => 0],
        'invalid generation' => fn (array $entry) => [...$entry, 'generation' => 'not-a-generation'],
        'mismatched format' => fn (array $entry) => [...$entry, 'format' => 'png'],
        'undefined disk' => fn (array $entry) => [...$entry, 'disk' => 'undefined-conversion-disk'],
        'unsafe filename' => fn (array $entry) => [...$entry, 'file_name' => '../photo.webp'],
        'wrong path suffix' => fn (array $entry) => [...$entry, 'path' => $entry['path'].'.missing'],
    ];

    foreach ($mutations as $mutation) {
        $properties = $original;
        $properties[Media::PROPERTY_CONVERSION_FILES]['thumb'] = $mutation(
            $properties[Media::PROPERTY_CONVERSION_FILES]['thumb'],
        );
        $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
            $media,
            $properties[Media::PROPERTY_CONVERSION_FILES],
            $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS],
        );
        $media->custom_properties = $properties;

        expect($media->getConversionFile('thumb'))->toBeNull()
            ->and($media->conversionFiles())->toBe([]);
    }

    $properties = $original;
    $properties[Media::PROPERTY_CONVERSION_FILES] = [0 => $properties[Media::PROPERTY_CONVERSION_FILES]['thumb']];
    $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
        $media,
        $properties[Media::PROPERTY_CONVERSION_FILES],
        $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS],
    );
    $media->custom_properties = $properties;

    expect($media->conversionFiles())->toBe([]);
});

it('streams active versioned conversions to a different target disk when copying', function () {
    Storage::fake('conversion-source');
    Storage::fake('conversion-target');
    registerVersionedThumb('conversion-source');
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $source = $media->getConversionFile('thumb');
    registerVersionedThumb('conversion-target');

    $copy = $media->copy(Subject::create());
    $copied = $copy->getConversionFile('thumb');

    expect($copied['disk'])->toBe('conversion-target')
        ->and($copied['generation'])->toBe($source['generation'])
        ->and(Storage::disk('conversion-target')->exists($copied['path']))->toBeTrue()
        ->and(Storage::disk('conversion-source')->exists($source['path']))->toBeTrue();
});

it('rejects publication and removes the candidate when pruning claims its generation', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);
    $active = $media->getConversionFile('thumb');
    $candidate = null;
    $manipulator = conversionManipulatorBeforePublish(function (Media $publishing, array $candidates) use (&$candidate): void {
        $candidate = $candidates['thumb'];
        $fresh = $publishing->fresh();
        $fresh->setCustomProperty(Media::PROPERTY_CONVERSION_PRUNING, [
            'thumb' => [
                $candidate['generation'] => [
                    'token' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                    'started_at' => now()->toIso8601String(),
                ],
            ],
        ])->save();
    });

    expect(fn () => $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false))
        ->toThrow(RuntimeException::class, 'is being pruned');

    expect($media->fresh()->getConversionFile('thumb'))->toBe($active)
        ->and(Storage::disk($candidate['disk'])->exists($candidate['_directory']))->toBeFalse();
});

it('rejects publication and removes the candidate when its file disappears', function () {
    registerVersionedThumb();
    $media = uploadVersionedConversionMedia();
    $candidate = null;
    $manipulator = conversionManipulatorBeforePublish(function (Media $publishing, array $candidates) use (&$candidate): void {
        $candidate = $candidates['thumb'];
        Storage::disk($candidate['disk'])->delete($candidate['path']);
    });

    expect(fn () => $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false))
        ->toThrow(RuntimeException::class, 'disappeared before publication');

    expect($media->fresh()->getConversionFile('thumb'))->toBeNull()
        ->and(Storage::disk($candidate['disk'])->exists($candidate['_directory']))->toBeFalse();
});

it('preserves marker write failures when conversion cleanup throws', function () {
    Storage::fake('conversion-cleanup-failure');
    registerVersionedThumb('conversion-cleanup-failure');
    $media = uploadVersionedConversionMedia();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')->once()->andReturn(false);
    $filesystem->shouldReceive('deleteDirectory')->once()->andThrow(new RuntimeException('cleanup failed'));
    Storage::set('conversion-cleanup-failure', $filesystem);
    Log::spy();

    $report = app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);

    expect($report['completed'])->toBe([])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['exception'])->toBeInstanceOf(MediaFileWriteFailed::class);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context) => $message === 'MediaMan: Failed to clean unpublished conversion generation'
            && ($context['error'] ?? null) === 'cleanup failed',
    );
});

it('keeps a published conversion when marker cleanup throws', function () {
    Storage::fake('conversion-marker-failure');
    registerVersionedThumb('conversion-marker-failure');
    $media = uploadVersionedConversionMedia();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')->twice()->andReturnTrue();
    $filesystem->shouldReceive('exists')->once()->andReturnTrue();
    $filesystem->shouldReceive('delete')->once()->andThrow(new RuntimeException('marker cleanup failed'));
    Storage::set('conversion-marker-failure', $filesystem);
    Log::spy();

    $report = app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);

    expect($report['completed'])->toBe(['thumb'])
        ->and($media->getConversionFile('thumb'))->not->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context) => $message === 'MediaMan: Failed to remove conversion generation marker'
            && ($context['error'] ?? null) === 'marker cleanup failed',
    );
});

it('rejects unsafe conversion directories', function () {
    expect(fn () => ConversionPath::directory('/absolute/conversions/thumb'))
        ->toThrow(InvalidArgumentException::class, 'unsafe for storage')
        ->and(fn () => ConversionPath::directory('media/../thumb'))
        ->toThrow(InvalidArgumentException::class, 'unsafe for storage');
});

it('requires APP_KEY when signing conversion lifecycle metadata', function () {
    $media = uploadVersionedConversionMedia();
    Config::set('app.key', null);
    Config::set('app.previous_keys', []);

    expect(fn () => ConversionManifest::sign($media, [], []))
        ->toThrow(RuntimeException::class, 'APP_KEY is required')
        ->and(fn () => app(ConversionClearer::class)->clear($media, 'thumb'))
        ->toThrow(RuntimeException::class, 'APP_KEY is required');
});

it('parses comma-separated previous signing keys', function () {
    $oldKey = 'base64:'.base64_encode(random_bytes(32));
    Config::set('app.previous_keys', $oldKey.',another-key');

    expect(SigningKeys::all())->toContain($oldKey, 'another-key');
});

it('rejects conversion clearing while media paths are rotating', function () {
    $media = uploadVersionedConversionMedia();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING, ['token' => 'rotation'])->save();

    expect(fn () => app(ConversionClearer::class)->clear($media, 'thumb'))
        ->toThrow(RuntimeException::class, 'is rotating paths');
});

it('reports legacy conversion writes that return false', function (bool $encoded) {
    Config::set('mediaman.conversions.versioning', false);
    Storage::fake('legacy-write-failure');
    Conversion::register(
        'thumb',
        $encoded
            ? fn (Image $image) => $image->encode(new WebpEncoder)
            : fn (Image $image) => $image->cover(320, 240),
        disk: 'legacy-write-failure',
    );
    $media = uploadVersionedConversionMedia();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->zeroOrMoreTimes()->andReturnFalse();
    $filesystem->shouldReceive('put')->once()->andReturnFalse();
    Storage::set('legacy-write-failure', $filesystem);

    $report = app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);

    expect($report['completed'])->toBe([])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['exception'])->toBeInstanceOf(MediaFileWriteFailed::class);
})->with([true, false]);

it('reports unsupported conversion return values in both path strategies', function (string|false $versioning) {
    Config::set('mediaman.conversions.versioning', $versioning);
    Conversion::register('thumb', fn () => 'unsupported');
    $media = uploadVersionedConversionMedia();

    $report = app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);

    expect($report['completed'])->toBe([])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['exception'])
        ->toBeInstanceOf(RuntimeException::class)
        ->getMessage()->toContain('unsupported value');
})->with(['generation', false]);

it('does not publish a versioned conversion when the file write returns false', function () {
    Storage::fake('conversion-write-failure');
    registerVersionedThumb('conversion-write-failure');
    $media = uploadVersionedConversionMedia();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')->twice()->andReturn(true, false);
    $filesystem->shouldReceive('deleteDirectory')->once()->andReturnTrue();
    Storage::set('conversion-write-failure', $filesystem);

    $report = app(ImageManipulator::class)->manipulate($media, ['thumb'], onlyIfMissing: false);

    expect($report['completed'])->toBe([])
        ->and($report['failed'])->toHaveCount(1)
        ->and($report['failed'][0]['exception'])->toBeInstanceOf(MediaFileWriteFailed::class)
        ->and($media->fresh()->getConversionFile('thumb'))->toBeNull();
});
