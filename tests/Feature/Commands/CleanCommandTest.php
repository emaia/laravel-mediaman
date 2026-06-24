<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\DefaultMediaResolver;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('reports no orphans when everything is clean', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    MediaUploader::source($file)->upload();

    $this->artisan('mediaman:clean')
        ->expectsOutputToContain('Media records whose primary disk is here: 1')
        ->expectsOutputToContain('No orphaned files found')
        ->expectsOutputToContain('No reverse orphans found')
        ->assertExitCode(0);
});

it('detects orphaned files on disk without a media record', function () {
    Storage::fake('orphan-disk');
    $orphan = '999-'.str_repeat('a', 32);
    Storage::disk('orphan-disk')->put("{$orphan}/orphan.txt", 'orphan content');

    $this->artisan('mediaman:clean', ['--disk' => 'orphan-disk'])
        ->expectsOutputToContain('Found 1 orphaned file(s)')
        ->expectsOutputToContain("{$orphan}/orphan.txt")
        ->assertExitCode(0);
});

it('does not delete orphaned files in dry run mode', function () {
    Storage::fake('dry-run-disk');
    $orphan = '999-'.str_repeat('a', 32);
    Storage::disk('dry-run-disk')->put("{$orphan}/evil.php", 'bad content');

    $this->artisan('mediaman:clean', ['--disk' => 'dry-run-disk'])
        ->expectsOutputToContain('Run with --force to delete orphaned files.')
        ->assertExitCode(0);

    expect(Storage::disk('dry-run-disk')->exists("{$orphan}/evil.php"))->toBeTrue();
});

it('deletes orphaned files with --force flag', function () {
    Storage::fake('force-disk');
    $orphan = '999-'.str_repeat('a', 32);
    Storage::disk('force-disk')->put("{$orphan}/evil.php", 'bad content');

    $this->artisan('mediaman:clean', ['--disk' => 'force-disk', '--force' => true])
        ->expectsOutputToContain('Deleted')
        ->assertExitCode(0);

    expect(Storage::disk('force-disk')->exists("{$orphan}/evil.php"))->toBeFalse();
});

it('detects multiple orphaned files', function () {
    Storage::fake('multi-orphan-disk');
    $orphanA = '777-'.str_repeat('a', 32);
    $orphanB = '888-'.str_repeat('b', 32);
    Storage::disk('multi-orphan-disk')->put("{$orphanA}/file1.txt", 'x');
    Storage::disk('multi-orphan-disk')->put("{$orphanB}/file2.txt", 'y');

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
        ->expectsOutputToContain('Media records whose primary disk is here: 2')
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

    $orphan = '666-'.str_repeat('c', 32);
    Storage::disk(self::DEFAULT_DISK)->put("{$orphan}/evil.txt", 'x');

    $this->artisan('mediaman:clean', ['--force' => true])
        ->assertExitCode(0);

    expect(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath()))->toBeTrue();
    expect(Storage::disk(self::DEFAULT_DISK)->exists("{$orphan}/evil.txt"))->toBeFalse();
});

it('handles non-existent disk gracefully', function () {
    $this->artisan('mediaman:clean', ['--disk' => 'nonexistent'])
        ->expectsOutputToContain('not defined')
        ->assertExitCode(1);
});

it('allows scanning a specific disk', function () {
    Storage::fake('secondary');
    $orphan = '555-'.str_repeat('d', 32);
    Storage::disk('secondary')->put("{$orphan}/other.txt", 'data');

    $this->artisan('mediaman:clean', ['--disk' => 'secondary'])
        ->expectsOutputToContain('Disk: secondary')
        ->expectsOutputToContain('Media records whose primary disk is here: 0')
        ->expectsOutputToContain("{$orphan}/other.txt")
        ->assertExitCode(0);
});

it('does not flag conversion files as orphans on a conversion-only disk', function () {
    Storage::fake('public');
    Storage::fake('default');

    $registry = app(ConversionRegistry::class);
    $registry->register('thumb', fn ($img) => $img->resize(200, 200), disk: 'public');

    $media = new Media;
    $media->name = 'test';
    $media->file_name = 'photo.jpg';
    $media->mime_type = 'image/jpeg';
    $media->disk = 'default';
    $media->size = 1024;
    $media->save();

    $mediaDir = $media->getDirectory();
    Storage::disk('default')->put($media->getPath(), 'original-content');

    // Put conversion files on the public disk (conversion-only disk)
    Storage::disk('public')->put("{$mediaDir}/conversions/thumb/photo.webp", 'thumb-content');

    // Orphan a MediaMan-shaped directory whose Media record no longer exists.
    $orphanDir = '999-'.str_repeat('a', 32);
    Storage::disk('public')->put("{$orphanDir}/bad.txt", 'orphan');

    $this->artisan('mediaman:clean', ['--disk' => 'public'])
        ->expectsOutputToContain('Disk: public')
        ->assertExitCode(0);

    // Media record is on 'default', not 'public'
    $this->artisan('mediaman:clean', ['--disk' => 'public'])
        ->expectsOutputToContain('Found 1 orphaned file(s)')
        ->expectsOutputToContain($orphanDir)
        ->doesntExpectOutputToContain("{$mediaDir}/conversions/thumb/photo.webp");
});

it('delegates orphan recognition to the bound MediaResolver — custom shapes participate', function () {
    Storage::fake('public');

    // Custom resolver that produces `tenant-acme/{id}` directories instead of
    // the default `{id}-{md5}`. The orphan recognition must follow the resolver,
    // not the default regex.
    $custom = new class extends DefaultMediaResolver
    {
        public function directory(Media $media): string
        {
            return 'tenant-acme/'.$media->getKey();
        }

        public function isManagedDirectory(string $segment): bool
        {
            return $segment === 'tenant-acme';
        }
    };
    app()->instance(MediaResolver::class, $custom);

    // Foreign files MediaMan must not touch
    Storage::disk('public')->put('.gitignore', "*\n");
    Storage::disk('public')->put('999-'.str_repeat('a', 32).'/bad.txt', 'looks default but is not our shape');

    // Orphan in the CUSTOM shape — no Media record references it
    Storage::disk('public')->put('tenant-acme/42/photo.jpg', 'orphan');

    $this->artisan('mediaman:clean', ['--disk' => 'public'])
        ->expectsOutputToContain('Found 1 orphaned file(s)')
        ->expectsOutputToContain('tenant-acme/42/photo.jpg')
        ->doesntExpectOutputToContain('.gitignore')
        ->doesntExpectOutputToContain('999-')
        ->assertExitCode(0);
});

it('ignores files outside the MediaMan directory pattern (.gitignore, foreign apps)', function () {
    Storage::fake('public');

    // Files Laravel and friends put on disks MediaMan happens to share
    Storage::disk('public')->put('.gitignore', "*\n!.gitignore\n");
    Storage::disk('public')->put('.gitkeep', '');
    Storage::disk('public')->put('README.md', 'docs');
    Storage::disk('public')->put('other-app/cache/foo.txt', 'foreign');
    Storage::disk('public')->put('uploads/profile.jpg', 'foreign');

    // A genuine MediaMan-shaped orphan that SHOULD still be flagged
    $orphanDir = '777-'.str_repeat('b', 32);
    Storage::disk('public')->put("{$orphanDir}/leftover.jpg", 'orphan');

    $this->artisan('mediaman:clean', ['--disk' => 'public'])
        ->expectsOutputToContain('Found 1 orphaned file(s)')
        ->expectsOutputToContain($orphanDir)
        ->doesntExpectOutputToContain('.gitignore')
        ->doesntExpectOutputToContain('.gitkeep')
        ->doesntExpectOutputToContain('README.md')
        ->doesntExpectOutputToContain('other-app')
        ->doesntExpectOutputToContain('uploads/profile.jpg')
        ->assertExitCode(0);
});
