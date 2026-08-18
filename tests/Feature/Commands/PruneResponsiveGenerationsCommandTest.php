<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\Ulid;

function oldResponsiveGeneration(int $days = 10): string
{
    return Ulid::generate(now()->subDays($days));
}

function putResponsiveGeneration(Media $media, string $generation, string $disk = 'default'): string
{
    $directory = $media->getDirectory()."/responsive/$generation";
    Storage::disk($disk)->put($directory.'/photo_320w.jpg', 'variant');

    return $directory;
}

it('is a dry run by default', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = oldResponsiveGeneration();
    $directory = putResponsiveGeneration($media, $generation);

    $this->artisan('mediaman:prune-responsive-generations', ['--older-than' => '0'])
        ->expectsOutputToContain('dry run')
        ->expectsOutputToContain('would delete')
        ->assertExitCode(0);

    expect(Storage::disk('default')->exists($directory))->toBeTrue();
});

it('deletes an eligible inactive generation with force', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = oldResponsiveGeneration();
    $directory = putResponsiveGeneration($media, $generation);

    $this->artisan('mediaman:prune-responsive-generations', [
        '--older-than' => '7',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($directory))->toBeFalse();
});

it('protects generations referenced by the active property and manifest', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $explicit = oldResponsiveGeneration(20);
    $manifestOnly = oldResponsiveGeneration(15);
    $explicitDirectory = putResponsiveGeneration($media, $explicit);
    $manifestDirectory = putResponsiveGeneration($media, $manifestOnly);
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION, $explicit)
        ->setCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK, 'default')
        ->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [[
            'width' => 320,
            'height' => 240,
            'format' => 'jpg',
            'path' => $manifestDirectory.'/photo_320w.jpg',
            'url' => '/manifest/photo_320w.jpg',
            'size' => 7,
        ]])
        ->save();

    $this->artisan('mediaman:prune-responsive-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($explicitDirectory))->toBeTrue()
        ->and(Storage::disk('default')->exists($manifestDirectory))->toBeTrue();
});

it('protects a fresh in-progress marker', function () {
    Config::set('mediaman.responsive_images.generation_timeout_minutes', 60);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = oldResponsiveGeneration();
    $directory = putResponsiveGeneration($media, $generation);
    Storage::disk('default')->put($directory.'/'.ResponsiveImageGenerator::IN_PROGRESS_MARKER, json_encode([
        'started_at' => now()->toIso8601String(),
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mediaman:prune-responsive-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($directory))->toBeTrue();
});

it('deletes an abandoned generation after marker timeout and retention', function () {
    Config::set('mediaman.responsive_images.generation_timeout_minutes', 60);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $generation = oldResponsiveGeneration();
    $directory = putResponsiveGeneration($media, $generation);
    Storage::disk('default')->put($directory.'/'.ResponsiveImageGenerator::IN_PROGRESS_MARKER, json_encode([
        'started_at' => now()->subHours(2)->toIso8601String(),
    ], JSON_THROW_ON_ERROR));

    $this->artisan('mediaman:prune-responsive-generations', [
        '--older-than' => '7',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($directory))->toBeFalse();
});

it('ignores legacy files and unknown directories', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $base = $media->getDirectory().'/responsive';
    Storage::disk('default')->put($base.'/photo_320w.jpg', 'legacy');
    Storage::disk('default')->put($base.'/not-a-generation/file.jpg', 'unknown');
    Storage::disk('default')->put($base.'/00000000000000000000000000/file.jpg', 'nil');

    $this->artisan('mediaman:prune-responsive-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($base.'/photo_320w.jpg'))->toBeTrue()
        ->and(Storage::disk('default')->exists($base.'/not-a-generation/file.jpg'))->toBeTrue()
        ->and(Storage::disk('default')->exists($base.'/00000000000000000000000000/file.jpg'))->toBeTrue();
});

it('retains future and non-canonical lowercase ULID directories', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $future = Ulid::generate(now()->addDay());
    $lowercase = strtolower(oldResponsiveGeneration());
    $futureDirectory = putResponsiveGeneration($media, $future);
    $lowercaseDirectory = putResponsiveGeneration($media, $lowercase);

    $this->artisan('mediaman:prune-responsive-generations', [
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('default')->exists($futureDirectory))->toBeTrue()
        ->and(Storage::disk('default')->exists($lowercaseDirectory))->toBeTrue();
});

it('scopes pruning by opaque media key and explicit disk', function () {
    Storage::fake('old-responsive');

    $selected = MediaUploader::source(UploadedFile::fake()->image('selected.jpg'))->upload();
    $other = MediaUploader::source(UploadedFile::fake()->image('other.jpg'))->upload();
    $selectedDirectory = putResponsiveGeneration($selected, oldResponsiveGeneration(), 'old-responsive');
    $otherDirectory = putResponsiveGeneration($other, oldResponsiveGeneration(), 'old-responsive');

    $this->artisan('mediaman:prune-responsive-generations', [
        '--media' => (string) $selected->getKey(),
        '--disk' => 'old-responsive',
        '--older-than' => '0',
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk('old-responsive')->exists($selectedDirectory))->toBeFalse()
        ->and(Storage::disk('old-responsive')->exists($otherDirectory))->toBeTrue();
});

it('rejects invalid retention and oversized media ranges', function () {
    $this->artisan('mediaman:prune-responsive-generations', ['--older-than' => '-1'])
        ->expectsOutputToContain('--older-than must be a non-negative integer')
        ->assertExitCode(1);

    $this->artisan('mediaman:prune-responsive-generations', ['--media' => '1..10001'])
        ->expectsOutputToContain('Invalid --media value')
        ->assertExitCode(1);
});
