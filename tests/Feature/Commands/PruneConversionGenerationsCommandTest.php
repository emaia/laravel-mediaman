<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Image;
use Symfony\Component\Uid\Ulid;

beforeEach(function () {
    Carbon::setTestNow();
    Config::set('mediaman.conversions.versioning', 'generation');
    Conversion::register('thumb', fn (Image $image) => $image->cover(320, 240));
});

function mediaWithTwoConversionGenerations(): array
{
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);
    Carbon::setTestNow(now()->addMinute());
    $inactive = $media->getConversionFile('thumb');
    $manipulator->manipulate($media, ['thumb'], onlyIfMissing: false);

    return [$media, $inactive, $media->getConversionFile('thumb')];
}

it('is a dry run and never removes the active conversion generation', function () {
    [$media, $inactive, $active] = mediaWithTwoConversionGenerations();

    $this->artisan('mediaman:prune-conversion-generations', ['--older-than' => '0'])
        ->expectsOutputToContain('dry run')
        ->expectsOutputToContain($inactive['generation'])
        ->assertExitCode(0);

    expect(Storage::disk($inactive['disk'])->exists($inactive['path']))->toBeTrue()
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeTrue()
        ->and($media->fresh()->getConversionFile('thumb')['generation'])->toBe($active['generation']);
});

it('deletes only inactive conversion generations with force', function () {
    [$media, $inactive, $active] = mediaWithTwoConversionGenerations();

    $this->artisan('mediaman:prune-conversion-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk($inactive['disk'])->exists($inactive['path']))->toBeFalse()
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeTrue()
        ->and($media->fresh()->hasCustomProperty($media::PROPERTY_CONVERSION_PRUNING))->toBeFalse();
});

it('protects fresh in-progress conversion generations', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = Ulid::generate(now()->subDays(10));
    $directory = $media->getDirectory().'/conversions/thumb/'.$generation;
    Storage::disk($media->disk)->put($directory.'/'.ImageManipulator::IN_PROGRESS_MARKER, json_encode([
        'started_at' => now()->toIso8601String(),
    ], JSON_THROW_ON_ERROR));
    Storage::disk($media->disk)->put($directory.'/photo.jpg', 'candidate');

    $this->artisan('mediaman:prune-conversion-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk($media->disk)->exists($directory.'/photo.jpg'))->toBeTrue();
});

it('prunes inactive generations after conversion registration removal', function () {
    [, $inactive, $active] = mediaWithTwoConversionGenerations();
    app()->instance(ConversionRegistry::class, new ConversionRegistry);

    $this->artisan('mediaman:prune-conversion-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk($inactive['disk'])->exists($inactive['path']))->toBeFalse()
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeTrue();
});

it('rejects invalid pruning options', function () {
    $this->artisan('mediaman:prune-conversion-generations', ['--older-than' => '-1'])
        ->expectsOutputToContain('Invalid --older-than value')
        ->assertExitCode(1);

    $this->artisan('mediaman:prune-conversion-generations', ['--conversion' => '../thumb'])
        ->expectsOutputToContain('Invalid --conversion value')
        ->assertExitCode(1);
});

it('fails closed when active conversion metadata is malformed', function () {
    [$media, $inactive, $active] = mediaWithTwoConversionGenerations();
    $properties = $media->custom_properties;
    $properties[$media::PROPERTY_CONVERSION_FILES]['thumb']['size'] = 0;
    $media->custom_properties = $properties;
    $media->save();

    $this->artisan('mediaman:prune-conversion-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])
        ->expectsOutputToContain('malformed active metadata retained')
        ->assertExitCode(1);

    expect(Storage::disk($inactive['disk'])->exists($inactive['path']))->toBeTrue()
        ->and(Storage::disk($active['disk'])->exists($active['path']))->toBeTrue();
});
