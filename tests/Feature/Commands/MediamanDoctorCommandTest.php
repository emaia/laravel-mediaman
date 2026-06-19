<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Capture the rendered output of `mediaman:doctor`. Laravel's `twoColumnDetail`
 * emits a single writeln per row with dots-filler between label and value;
 * the PendingCommand testing helper's `expectsOutputToContain` matches only the
 * first registered substring per doWrite call, which makes label+value matching
 * on the same line unreliable. Capturing the full buffered output and asserting
 * substring containment directly is the cleaner pattern for value verification.
 */
function captureDoctorOutput(): string
{
    $output = new BufferedOutput;
    Artisan::call('mediaman:doctor', [], $output);

    return $output->fetch();
}

it('reports a healthy pipeline on a fresh install', function () {
    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('Schema migrations')
        ->expectsOutputToContain('Disk')
        ->expectsOutputToContain('Image driver')
        ->expectsOutputToContain('Queue')
        ->expectsOutputToContain('Conversions')
        ->expectsOutputToContain('Media inventory')
        ->assertExitCode(0);
});

it('shows the effective image driver class', function () {
    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('Intervention\Image\Drivers\\')
        ->assertExitCode(0);
});

it('reports an error and exits non-zero when a required table is missing', function () {
    Schema::dropIfExists('mediaman_mediables');

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('missing: mediaman_mediables')
        ->assertExitCode(1);
});

it('reports an error when the configured disk is not defined', function () {
    Config::set('mediaman.disk', 'nonexistent-disk');

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain("disk 'nonexistent-disk' is not defined")
        ->assertExitCode(1);
});

/**
 * Best-effort cleanup of a path that might be a symlink, a regular file, or
 * a directory. Windows requires `rmdir()` for directory symlinks while POSIX
 * accepts `unlink()` on any kind of symlink — we try both so the same teardown
 * works on either platform.
 */
function removeLinkOrPath(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    @unlink($path) || @rmdir($path);
}

it('reports symlink ok when filesystems.links entry exists and points correctly', function () {
    $rootDir = sys_get_temp_dir().'/mediaman-doctor-'.bin2hex(random_bytes(4));
    $linkPath = sys_get_temp_dir().'/mediaman-doctor-link-'.bin2hex(random_bytes(4));

    mkdir($rootDir, 0o755, true);

    if (! @symlink($rootDir, $linkPath)) {
        @rmdir($rootDir);
        $this->markTestSkipped('symlink() unavailable on this host (likely Windows without developer mode or admin)');
    }

    Config::set('filesystems.disks.symlink-test', [
        'driver' => 'local',
        'root' => $rootDir,
        'url' => 'http://localhost/symlink-test',
        'visibility' => 'public',
    ]);
    Config::set('filesystems.links', [$linkPath => $rootDir]);
    Config::set('mediaman.disk', 'symlink-test');

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Public symlink')
        ->toContain('✓');

    removeLinkOrPath($linkPath);
    @rmdir($rootDir);
});

it('warns when filesystems.links entry exists but the symlink is missing', function () {
    $rootDir = sys_get_temp_dir().'/mediaman-doctor-'.bin2hex(random_bytes(4));
    $linkPath = sys_get_temp_dir().'/mediaman-doctor-missing-'.bin2hex(random_bytes(4));

    mkdir($rootDir, 0o755, true);
    // Intentionally do NOT create the symlink.

    Config::set('filesystems.disks.symlink-missing', [
        'driver' => 'local',
        'root' => $rootDir,
    ]);
    Config::set('filesystems.links', [$linkPath => $rootDir]);
    Config::set('mediaman.disk', 'symlink-missing');

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('missing — run `php artisan storage:link`')
        ->assertExitCode(0); // warning, not error

    @rmdir($rootDir);
});

it('reports error when the link path exists but is not a symlink', function () {
    $rootDir = sys_get_temp_dir().'/mediaman-doctor-'.bin2hex(random_bytes(4));
    $linkPath = sys_get_temp_dir().'/mediaman-doctor-squat-'.bin2hex(random_bytes(4));

    mkdir($rootDir, 0o755, true);
    file_put_contents($linkPath, 'squatting file');

    Config::set('filesystems.disks.symlink-squat', [
        'driver' => 'local',
        'root' => $rootDir,
    ]);
    Config::set('filesystems.links', [$linkPath => $rootDir]);
    Config::set('mediaman.disk', 'symlink-squat');

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('not a symlink')
        ->assertExitCode(1);

    removeLinkOrPath($linkPath);
    @rmdir($rootDir);
});

it('skips the symlink check for remote drivers', function () {
    Config::set('filesystems.disks.remote-fake', [
        'driver' => 's3',
        'bucket' => 'mediaman-test',
    ]);
    Config::set('mediaman.disk', 'remote-fake');

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain("not applicable (driver: 's3')")
        ->assertExitCode(1); // exit 1 because the disk probe will fail on a fake s3 — checkSymlink itself doesn't fail
});

it('confirms the disk probe round-trip on the default disk', function () {
    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Probe (write/read/delete)')
        ->toContain('✓ OK');
});

it('warns when auto_generate is enabled together with the queue', function () {
    Config::set('mediaman.responsive_images.auto_generate', true);
    Config::set('mediaman.responsive_images.queue', true);

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('ensure a queue worker is running')
        ->assertExitCode(0);
});

it('marks auto_generate as synchronous when queue is disabled', function () {
    Config::set('mediaman.responsive_images.auto_generate', true);
    Config::set('mediaman.responsive_images.queue', false);

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('synchronous, no worker required')
        ->assertExitCode(0);
});

it('reports the number of registered conversions', function () {
    $initial = count(app(ConversionRegistry::class)->all());
    Conversion::register('doctor-thumb', fn ($image) => $image);
    Conversion::register('doctor-cover', fn ($image) => $image);

    $out = captureDoctorOutput();
    $expected = $initial + 2;

    expect($out)
        ->toContain('Registered')
        ->toContain("· {$expected}");
});

it('reports record count and total size from the media table', function () {
    MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Records')
        ->toContain('· 2')
        ->toContain('Total size');
});

it('skips the coverage line when no records exist', function () {
    $this->artisan('mediaman:doctor')
        ->doesntExpectOutputToContain('Responsive coverage')
        ->assertExitCode(0);
});

it('reports responsive coverage when records have responsive_images persisted', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    // Simulate one of the two records having responsive_images custom property.
    $media->setCustomProperty('responsive_images', [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/u', 'size' => 1],
    ]);
    $media->save();

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Responsive coverage')
        ->toContain('1 / 2 (50%)');
});
