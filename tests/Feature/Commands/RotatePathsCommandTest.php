<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\Ulid;

function rotatePathsKeyPair(): array
{
    $oldKey = 'base64:'.base64_encode(str_repeat('x', 32));
    $newKey = 'base64:'.base64_encode(str_repeat('y', 32));

    return [$oldKey, $newKey];
}

function expectedDirFor(int $id, string $key): string
{
    return $id.'-'.md5($id.$key);
}

it('requires --old-key', function () {
    $this->artisan('mediaman:rotate-paths')
        ->expectsOutputToContain('--old-key is required')
        ->assertExitCode(1);
});

it('rejects invalid generation config and empty filters', function () {
    [$oldKey] = rotatePathsKeyPair();
    Config::set('mediaman.responsive_images.versioning', 'timestamp');

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey])
        ->expectsOutputToContain("versioning must be false or 'generation'")
        ->assertExitCode(1);

    Config::set('mediaman.responsive_images.versioning', false);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--media' => ''])
        ->expectsOutputToContain('Invalid --media value')
        ->assertExitCode(1);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--disk' => ''])
        ->expectsOutputToContain('Invalid --disk value')
        ->assertExitCode(1);
});

it('noops when --old-key matches the current key', function () {
    $this->artisan('mediaman:rotate-paths', ['--old-key' => config('app.key')])
        ->expectsOutputToContain('matches the current app.key')
        ->assertExitCode(0);
});

it('reports planned moves in dry-run mode without touching disk', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();
    $media->newQuery()->whereKey($media->getKey())->update(['updated_at' => '2000-01-01 00:00:00']);
    $before = $media->fresh();

    Config::set('app.key', $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('would move')
        ->assertExitCode(0);

    // File still at the old location after dry-run
    expect(Storage::disk($media->disk)->exists($oldDir))->toBeTrue()
        ->and($media->fresh()->getCustomProperty('responsive_generation_epoch', 0))->toBe(0)
        ->and($media->fresh()->getRawOriginal('updated_at'))->toBe($before->getRawOriginal('updated_at'))
        ->and($media->fresh()->custom_properties)->toBe($before->custom_properties);
});

it('moves configured disks but fails when a required variant disk is unavailable', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();
    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();
    Config::set('mediaman.responsive_images.disk', 'missing-responsive');
    Config::set('app.key', $newKey);
    $newDir = expectedDirFor($media->id, $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('disk [missing-responsive] not configured; path rotation incomplete')
        ->assertExitCode(1);

    expect(Storage::disk($media->disk)->exists($oldDir))->toBeFalse()
        ->and(Storage::disk($media->disk)->exists($newDir.'/'.$media->file_name))->toBeTrue();
});

it('skips a media row deleted between cursor hydration and rotation claim', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();
    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    Config::set('app.key', $newKey);
    $eventDispatcher = clone Media::getEventDispatcher();
    $removed = false;

    try {
        Media::retrieved(function (Media $retrieved) use ($media, &$removed): void {
            if ($removed || $retrieved->getKey() !== $media->getKey()) {
                return;
            }

            $removed = true;
            $retrieved->newQueryWithoutScopes()->whereKey($retrieved->getKey())->delete();
        });

        $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
            ->expectsOutputToContain('record no longer exists, skipping')
            ->expectsOutputToContain('Deleted records:  1')
            ->assertExitCode(0);
    } finally {
        Media::setEventDispatcher($eventDispatcher);
    }
});

it('actually moves files when --force is passed', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();

    Config::set('app.key', $newKey);
    $newDir = expectedDirFor($media->id, $newKey);

    expect(Storage::disk($media->disk)->exists($oldDir))->toBeTrue();
    expect(Storage::disk($media->disk)->exists($newDir))->toBeFalse();

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('Renamed: 1')
        ->assertExitCode(0);

    expect(Storage::disk($media->disk)->exists($oldDir))->toBeFalse();
    expect(Storage::disk($media->disk)->exists($newDir.'/'.$media->file_name))->toBeTrue();
    expect($media->fresh()->hasCustomProperty('responsive_rotating'))->toBeFalse()
        ->and($media->fresh()->getCustomProperty('responsive_generation_epoch'))->toBe(1);
});

it('skips media whose files are already at the new path', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $newKey); // Upload directly under the new key
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('already at')
        ->expectsOutputToContain('Already migrated: 1')
        ->assertExitCode(0);
});

it('flags media with both old and new directories present as conflict', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    Config::set('app.key', $newKey);
    // Simulate a partial previous run leaving both directories on disk
    $newDir = expectedDirFor($media->id, $newKey);
    Storage::disk($media->disk)->put($newDir.'/stray.txt', 'leftover');

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('Manual review required')
        ->expectsOutputToContain('Conflicts')
        ->assertExitCode(1);

    // Nothing was moved
    expect(Storage::disk($media->disk)->exists($media->getDirectory()))->toBeTrue();
});

it('--disk scopes the operation', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Storage::fake('other-disk');

    Config::set('app.key', $oldKey);
    $mediaDefault = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $mediaOther = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->useDisk('other-disk')->upload();

    Config::set('app.key', $newKey);

    $this->artisan('mediaman:rotate-paths', [
        '--old-key' => $oldKey,
        '--disk' => 'other-disk',
        '--force' => true,
    ])->assertExitCode(0);

    // other-disk media moved to new dir, old dir gone
    $oldDirOther = expectedDirFor($mediaOther->id, $oldKey);
    $newDirOther = expectedDirFor($mediaOther->id, $newKey);
    expect(Storage::disk('other-disk')->exists($oldDirOther))->toBeFalse();
    expect(Storage::disk('other-disk')->exists($newDirOther.'/'.$mediaOther->file_name))->toBeTrue();

    // default disk untouched (still at the old directory)
    $oldDirDefault = expectedDirFor($mediaDefault->id, $oldKey);
    expect(Storage::disk($mediaDefault->disk)->exists($oldDirDefault))->toBeTrue();
});

it('--media scopes to a single id', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    Config::set('app.key', $newKey);

    $this->artisan('mediaman:rotate-paths', [
        '--old-key' => $oldKey,
        '--media' => $m1->id,
        '--force' => true,
    ])->assertExitCode(0);

    expect(Storage::disk($m1->disk)->exists(expectedDirFor($m1->id, $newKey).'/'.$m1->file_name))->toBeTrue();
    // m2 unchanged
    expect(Storage::disk($m2->disk)->exists(expectedDirFor($m2->id, $oldKey).'/'.$m2->file_name))->toBeTrue();
});

it('warns when media directory is missing entirely', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    // Wipe the directory
    Storage::disk($media->disk)->deleteDirectory($media->getDirectory());

    Config::set('app.key', $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('neither')
        ->expectsOutputToContain('Missing on disk:  1')
        ->assertExitCode(0);
});

it('moves conversion + responsive subfiles along with the primary file', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();

    // Drop a fake conversion and responsive sibling into the old directory
    Storage::disk($media->disk)->put($oldDir.'/conversions/thumb/photo.jpg', 'fake-thumb');
    Storage::disk($media->disk)->put($oldDir.'/responsive/photo_320w.webp', 'fake-webp');

    Config::set('app.key', $newKey);
    $newDir = expectedDirFor($media->id, $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->assertExitCode(0);

    expect(Storage::disk($media->disk)->exists($newDir.'/'.$media->file_name))->toBeTrue();
    expect(Storage::disk($media->disk)->exists($newDir.'/conversions/thumb/photo.jpg'))->toBeTrue();
    expect(Storage::disk($media->disk)->exists($newDir.'/responsive/photo_320w.webp'))->toBeTrue();
    expect(Storage::disk($media->disk)->exists($oldDir))->toBeFalse();
});

it('blocks rotation when an immutable responsive generation is active', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    Config::set('mediaman.responsive_images.versioning', 'generation');
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);
    $oldDir = $media->getDirectory();

    Config::set('app.key', $newKey);
    $newDir = expectedDirFor($media->id, $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('versioning is enabled')
        ->assertExitCode(1);

    expect(Storage::disk($media->disk)->exists($oldDir))->toBeTrue()
        ->and(Storage::disk($media->disk)->exists($newDir))->toBeFalse();
});

it('blocks rotation while a first responsive generation is in progress', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();
    Storage::disk($media->disk)->put(
        $oldDir.'/responsive/'.Ulid::generate(now()->subDay()).'/'.ResponsiveImageGenerator::IN_PROGRESS_MARKER,
        'marker',
    );

    Config::set('app.key', $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('responsive generation or copy is in progress')
        ->assertExitCode(1);

    expect(Storage::disk($media->disk)->exists($oldDir))->toBeTrue();
});

it('blocks fresh and malformed rotation leases', function (string $startedAt) {
    [$oldKey, $newKey] = rotatePathsKeyPair();
    Config::set('app.key', $oldKey);
    Config::set('mediaman.responsive_images.generation_timeout_minutes', 60);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();
    $token = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING, [
        'token' => $token,
        'started_at' => $startedAt,
    ])->save();
    Config::set('app.key', $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->expectsOutputToContain('lifecycle state blocks path rotation')
        ->assertExitCode(1);

    expect(Storage::disk($media->disk)->exists($oldDir))->toBeTrue()
        ->and($media->fresh()->getCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING.'.token'))->toBe($token);
})->with([
    'fresh' => fn () => now()->toIso8601String(),
    'malformed' => 'not-a-date',
]);

it('reclaims an expired rotation lease', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();
    Config::set('app.key', $oldKey);
    Config::set('mediaman.responsive_images.generation_timeout_minutes', 60);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING, [
        'token' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'started_at' => now()->subHours(2)->toIso8601String(),
    ])->save();
    Config::set('app.key', $newKey);
    $newDir = expectedDirFor($media->id, $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
        ->assertExitCode(0);

    expect(Storage::disk($media->disk)->exists($newDir.'/'.$media->file_name))->toBeTrue()
        ->and($media->fresh()->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING))->toBeFalse();
});

it('releases its claim when responsive marker enumeration fails', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();
    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();
    Config::set('app.key', $newKey);
    $realFilesystem = Storage::disk($media->disk);
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('allFiles')
        ->once()
        ->with($oldDir.'/responsive')
        ->andThrow(new RuntimeException('listing failed'));
    Storage::set($media->disk, $filesystem);

    try {
        $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
            ->expectsOutputToContain('cannot verify responsive markers')
            ->assertExitCode(1);
    } finally {
        Storage::set($media->disk, $realFilesystem);
    }

    expect($media->fresh()->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING))->toBeFalse()
        ->and($media->fresh()->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH))->toBe(1);
});

it('does not delete the source directory when a filesystem move returns false', function () {
    [$oldKey, $newKey] = rotatePathsKeyPair();

    Config::set('app.key', $oldKey);
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $oldDir = $media->getDirectory();
    Config::set('app.key', $newKey);
    $newDir = expectedDirFor($media->id, $newKey);
    $realFilesystem = Storage::disk($media->disk);
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('allFiles')->with($oldDir.'/responsive')->andReturn([]);
    $filesystem->shouldReceive('exists')->with($oldDir)->andReturn(true);
    $filesystem->shouldReceive('exists')->with($newDir)->andReturn(false);
    $filesystem->shouldReceive('allFiles')->with($oldDir)->andReturn([$oldDir.'/'.$media->file_name]);
    $filesystem->shouldReceive('move')->once()->andReturn(false);
    $filesystem->shouldNotReceive('deleteDirectory');
    Storage::set($media->disk, $filesystem);

    try {
        $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey, '--force' => true])
            ->expectsOutputToContain('failed to move')
            ->assertExitCode(1);
    } finally {
        Storage::set($media->disk, $realFilesystem);
    }

    expect($media->fresh()->hasCustomProperty('responsive_rotating'))->toBeFalse();
});
