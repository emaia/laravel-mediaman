<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
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

    $this->artisan('mediaman:prune-conversion-generations', ['--older-than' => '999999999999999999999'])
        ->expectsOutputToContain('Invalid --older-than value')
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

it('reports malformed markers as failures instead of protecting them silently', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = Ulid::generate(now()->subDays(10));
    $directory = $media->getDirectory().'/conversions/thumb/'.$generation;
    Storage::disk($media->disk)->put($directory.'/'.ImageManipulator::IN_PROGRESS_MARKER, '{invalid');

    $this->artisan('mediaman:prune-conversion-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])
        ->expectsOutputToContain('Malformed conversion generation marker')
        ->assertExitCode(1);

    expect(Storage::disk($media->disk)->exists($directory))->toBeTrue();
});

it('ignores unsafe legacy registrations during implicit discovery', function () {
    Conversion::register('../legacy-thumb', fn (Image $image) => $image);

    $this->artisan('mediaman:prune-conversion-generations', ['--older-than' => '0'])
        ->assertExitCode(0);
});

it('rejects empty destructive filters and undefined disks before scanning media', function () {
    $this->artisan('mediaman:prune-conversion-generations', ['--media' => ''])
        ->expectsOutputToContain('Invalid --media value')
        ->assertExitCode(1);

    $this->artisan('mediaman:prune-conversion-generations', ['--collection' => ''])
        ->expectsOutputToContain('Invalid --collection value')
        ->assertExitCode(1);

    $this->artisan('mediaman:prune-conversion-generations', ['--disk' => ''])
        ->expectsOutputToContain('Invalid --disk value')
        ->assertExitCode(1);

    $this->artisan('mediaman:prune-conversion-generations', ['--disk' => 'undefined-conversion-disk'])
        ->expectsOutputToContain('Disk [undefined-conversion-disk] does not have a configured driver')
        ->assertExitCode(1);
});

it('scopes pruning by media key collection conversion and disk', function () {
    $selected = MediaUploader::source(UploadedFile::fake()->image('selected.jpg'))
        ->useCollection('Selected')
        ->upload();
    $other = MediaUploader::source(UploadedFile::fake()->image('other.jpg'))
        ->useCollection('Other')
        ->upload();
    $generation = Ulid::generate(now()->subDays(10));
    $selectedDirectory = $selected->getDirectory()."/conversions/thumb/$generation";
    $otherDirectory = $other->getDirectory()."/conversions/thumb/$generation";
    Storage::disk('default')->put($selectedDirectory.'/selected.jpg', 'candidate');
    Storage::disk('default')->put($otherDirectory.'/other.jpg', 'candidate');

    $this->artisan('mediaman:prune-conversion-generations', [
        '--media' => (string) $selected->getKey(),
        '--collection' => 'Selected',
        '--conversion' => 'thumb',
        '--disk' => 'default',
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($selectedDirectory))->toBeFalse()
        ->and(Storage::disk('default')->exists($otherDirectory))->toBeTrue();
});

it('releases its pruning claim when storage deletion fails', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = Ulid::generate(now()->subDays(10));
    $base = $media->getDirectory().'/conversions/thumb';
    $directory = "$base/$generation";
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('directories')->with($base)->andReturn([$directory]);
    $filesystem->shouldReceive('files')->with($directory)->andReturn([]);
    $filesystem->shouldReceive('deleteDirectory')->with($directory)->once()->andReturnUsing(
        function () use ($media, $generation): bool {
            expect($media->fresh()->getCustomProperty(Media::PROPERTY_CONVERSION_PRUNING.".thumb.$generation"))
                ->toBeArray();

            return false;
        },
    );
    Storage::set('conversion-delete-failure', $filesystem);

    $this->artisan('mediaman:prune-conversion-generations', [
        '--conversion' => 'thumb',
        '--disk' => 'conversion-delete-failure',
        '--older-than' => '0',
        '--force' => true,
    ])
        ->expectsOutputToContain('Failed to delete conversion generation')
        ->assertExitCode(1);

    expect($media->fresh()->hasCustomProperty(Media::PROPERTY_CONVERSION_PRUNING))->toBeFalse();
});

it('preserves generations protected by a fresh pruning claim', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = Ulid::generate(now()->subDays(10));
    $directory = $media->getDirectory()."/conversions/thumb/$generation";
    Storage::disk('default')->put($directory.'/photo.jpg', 'candidate');
    $media->setCustomProperty(Media::PROPERTY_CONVERSION_PRUNING, [
        'thumb' => [
            $generation => [
                'token' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'started_at' => now()->toIso8601String(),
            ],
        ],
    ])->save();

    $this->artisan('mediaman:prune-conversion-generations', [
        '--conversion' => 'thumb',
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($directory))->toBeTrue()
        ->and($media->fresh()->getCustomProperty(Media::PROPERTY_CONVERSION_PRUNING.".thumb.$generation"))
        ->toBeArray();
});

it('deletes expired markers while retaining future recent and unmanaged directories', function () {
    Config::set('mediaman.conversions.generation_timeout_minutes', 60);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $base = $media->getDirectory().'/conversions/thumb';
    $expired = Ulid::generate(now()->subDays(10));
    $future = Ulid::generate(now()->addDay());
    $recent = Ulid::generate(now()->subDay());
    $unmanaged = strtolower(Ulid::generate(now()->subDays(10)));

    foreach ([$expired, $future, $recent, $unmanaged] as $generation) {
        Storage::disk('default')->put("$base/$generation/photo.jpg", 'candidate');
    }

    Storage::disk('default')->put("$base/$expired/".ImageManipulator::IN_PROGRESS_MARKER, json_encode([
        'started_at' => now()->subHours(2)->toIso8601String(),
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mediaman:prune-conversion-generations', [
        '--conversion' => 'thumb',
        '--older-than' => '7',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists("$base/$expired"))->toBeFalse()
        ->and(Storage::disk('default')->exists("$base/$future"))->toBeTrue()
        ->and(Storage::disk('default')->exists("$base/$recent"))->toBeTrue()
        ->and(Storage::disk('default')->exists("$base/$unmanaged"))->toBeTrue();
});

it('reports filesystem discovery failures with conversion context', function () {
    MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('directories')->andThrow(new RuntimeException('listing failed'));
    Storage::set('conversion-listing-failure', $filesystem);

    $this->artisan('mediaman:prune-conversion-generations', [
        '--disk' => 'conversion-listing-failure',
        '--older-than' => '0',
    ])
        ->expectsOutputToContain('listing failed')
        ->assertExitCode(1);
});
