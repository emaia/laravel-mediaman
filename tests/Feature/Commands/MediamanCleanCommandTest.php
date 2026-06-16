<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('reports no orphans when everything is clean', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    MediaUploader::source($file)->upload();

    $this->artisan('mediaman:clean')
        ->expectsOutputToContain('Media records on this disk: 1')
        ->expectsOutputToContain('No orphaned files found')
        ->expectsOutputToContain('No reverse orphans found')
        ->assertExitCode(0);
});

it('detects orphaned files on disk without a media record', function () {
    Storage::fake('orphan-disk');
    Storage::disk('orphan-disk')->put('orphan-dir/orphan.txt', 'orphan content');

    $this->artisan('mediaman:clean', ['--disk' => 'orphan-disk'])
        ->expectsOutputToContain('Found 1 orphaned file(s)')
        ->expectsOutputToContain('orphan-dir/orphan.txt')
        ->assertExitCode(0);
});

it('does not delete orphaned files in dry run mode', function () {
    Storage::fake('dry-run-disk');
    Storage::disk('dry-run-disk')->put('orphan/evil.php', 'bad content');

    $this->artisan('mediaman:clean', ['--disk' => 'dry-run-disk'])
        ->expectsOutputToContain('Run with --force to delete orphaned files.')
        ->assertExitCode(0);

    expect(Storage::disk('dry-run-disk')->exists('orphan/evil.php'))->toBeTrue();
});

it('deletes orphaned files with --force flag', function () {
    Storage::fake('force-disk');
    Storage::disk('force-disk')->put('orphan/evil.php', 'bad content');

    $this->artisan('mediaman:clean', ['--disk' => 'force-disk', '--force' => true])
        ->expectsOutputToContain('Deleted')
        ->assertExitCode(0);

    expect(Storage::disk('force-disk')->exists('orphan/evil.php'))->toBeFalse();
});

it('detects multiple orphaned files', function () {
    Storage::fake('multi-orphan-disk');
    Storage::disk('multi-orphan-disk')->put('a/file1.txt', 'x');
    Storage::disk('multi-orphan-disk')->put('b/file2.txt', 'y');

    $this->artisan('mediaman:clean', ['--disk' => 'multi-orphan-disk'])
        ->expectsOutputToContain('Found 2 orphaned file(s)')
        ->assertExitCode(0);
});

it('detects reverse orphans when media record has no file on disk', function () {
    $media = new Media;
    $media->name = 'lostfile';
    $media->file_name = 'lost.jpg';
    $media->disk = self::DEFAULT_DISK;
    $media->mime_type = 'image/jpeg';
    $media->size = 100;
    $media->save();

    $this->artisan('mediaman:clean')
        ->expectsOutputToContain('Found 1 record(s) with missing files')
        ->expectsOutputToContain('lostfile')
        ->assertExitCode(0);
});

it('shows record count correctly', function () {
    MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    $this->artisan('mediaman:clean')
        ->expectsOutputToContain('Media records on this disk: 2')
        ->assertExitCode(0);
});

it('does not flag files within a valid media directory as orphans', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $this->artisan('mediaman:clean')
        ->expectsOutputToContain('No orphaned files found')
        ->expectsOutputToContain('No reverse orphans found')
        ->assertExitCode(0);
});

it('preserves valid media files when --force is used', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    Storage::disk(self::DEFAULT_DISK)->put('orphan-dir/evil.txt', 'x');

    $this->artisan('mediaman:clean', ['--force' => true])
        ->assertExitCode(0);

    expect(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath()))->toBeTrue();
    expect(Storage::disk(self::DEFAULT_DISK)->exists('orphan-dir/evil.txt'))->toBeFalse();
});

it('handles non-existent disk gracefully', function () {
    $this->artisan('mediaman:clean', ['--disk' => 'nonexistent'])
        ->expectsOutputToContain('not defined')
        ->assertExitCode(1);
});

it('allows scanning a specific disk', function () {
    Storage::fake('secondary');
    Storage::disk('secondary')->put('orphan/other.txt', 'data');

    $this->artisan('mediaman:clean', ['--disk' => 'secondary'])
        ->expectsOutputToContain('Disk: secondary')
        ->expectsOutputToContain('Media records on this disk: 0')
        ->expectsOutputToContain('orphan/other.txt')
        ->assertExitCode(0);
});
