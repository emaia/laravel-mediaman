<?php

use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

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

    Config::set('app.key', $newKey);

    $this->artisan('mediaman:rotate-paths', ['--old-key' => $oldKey])
        ->expectsOutputToContain('Dry run')
        ->expectsOutputToContain('would move')
        ->assertExitCode(0);

    // File still at the old location after dry-run
    expect(Storage::disk($media->disk)->exists($oldDir))->toBeTrue();
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
        ->assertExitCode(0);

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
