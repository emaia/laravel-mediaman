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

it('reports the config file as not published on a fresh install', function () {
    $path = config_path('mediaman.php');
    $existed = file_exists($path);
    if ($existed) {
        $backup = file_get_contents($path);
        unlink($path);
    }

    try {
        $out = captureDoctorOutput();
        expect($out)
            ->toContain('Config file')
            ->toContain('no (using package defaults');
    } finally {
        if ($existed) {
            file_put_contents($path, $backup);
        }
    }
});

it('reports the config file as published when config/mediaman.php exists', function () {
    $path = config_path('mediaman.php');
    $existed = file_exists($path);
    if (! $existed) {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }
        file_put_contents($path, "<?php\n\nreturn [];\n");
    }

    try {
        $out = captureDoctorOutput();
        expect($out)
            ->toContain('Config file')
            ->toContain('✓')
            ->toContain('config/mediaman.php');
    } finally {
        if (! $existed) {
            @unlink($path);
        }
    }
});

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

it('reports a successful format probe when the driver supports the format', function () {
    Config::set('mediaman.responsive_images.formats', ['webp']);

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Format probe (webp)')
        ->toContain('encodes ✓');
});

it('warns on a format probe when the driver returns zero bytes or throws', function () {
    // GD has no HEIC encoder — the probe surfaces this without breaking the
    // doctor (warn, not error).
    Config::set('mediaman.driver', 'gd');
    Config::set('mediaman.responsive_images.formats', ['heic']);

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Format probe (heic)')
        ->toContain('⚠');
});

it('warns on a format probe with an unknown format', function () {
    Config::set('mediaman.responsive_images.formats', ['bogus']);

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Format probe (bogus)')
        ->toContain('unrecognized');
});

it('skips the format probe when formats is empty', function () {
    Config::set('mediaman.responsive_images.formats', []);

    $out = captureDoctorOutput();

    expect($out)->not->toContain('Format probe');
});

it('does not emit the vips SAPI hint when a non-vips driver is effective', function () {
    Config::set('mediaman.driver', 'gd');

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Drivers\\Gd\\Driver')
        ->not->toContain('Probe SAPI')
        ->not->toContain('vips uses PHP FFI');
});

it('emits a successful 1x1 encode probe under any healthy driver', function () {
    Config::set('mediaman.driver', 'gd');

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Probe')
        ->toContain('driver encodes a 1×1 test image');
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

// ─── Security section ────────────────────────────────────────────────

it('reports MIME allow-list size when whitelisted types are configured', function () {
    Config::set('mediaman.allowed_mime_types', ['image/jpeg', 'image/png', 'application/pdf']);

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('MIME allow-list')
        ->toContain('3 MIME type(s) whitelisted');
});

it('warns when SVG uploads are enabled', function () {
    Config::set('mediaman.svg.enabled', true);

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('SVG uploads')
        ->toContain('enabled — uploads sanitized');
});

it('warns when the extension blocklist is disabled', function () {
    Config::set('mediaman.block_disallowed_extensions', false);

    $out = captureDoctorOutput();

    expect($out)
        ->toContain('Extension blocklist')
        ->toContain('server-executable extensions can land');
});

// ─── Variant disks ──────────────────────────────────────────────────

it('probes a distinct conversion-default disk when it differs from the main disk', function () {
    Config::set('filesystems.disks.conv-default', ['driver' => 'local', 'root' => storage_path('framework/testing/conv-default')]);
    Config::set('mediaman.conversions.disk', 'conv-default');

    $out = captureDoctorOutput();

    expect($out)->toContain("Conversion disk 'conv-default'");
});

it('probes a distinct responsive-images disk when it differs from the main disk', function () {
    Config::set('filesystems.disks.resp-disk', ['driver' => 'local', 'root' => storage_path('framework/testing/resp-disk')]);
    Config::set('mediaman.responsive_images.disk', 'resp-disk');

    $out = captureDoctorOutput();

    expect($out)->toContain("Responsive disk 'resp-disk'");
});

// ─── Public symlink: remaining branches ─────────────────────────────

it('skips the symlink check when the local disk has no root configured', function () {
    Config::set('filesystems.disks.no-root', ['driver' => 'local']);
    Config::set('mediaman.disk', 'no-root');

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('skipped (disk root not configured)');
});

it('reports the no-matching-links message when filesystems.links has no entry for the disk root', function () {
    $rootDir = sys_get_temp_dir().'/mediaman-doctor-orphan-'.bin2hex(random_bytes(4));
    mkdir($rootDir, 0o755, true);

    Config::set('filesystems.disks.orphan', ['driver' => 'local', 'root' => $rootDir]);
    Config::set('filesystems.links', []);
    Config::set('mediaman.disk', 'orphan');

    $out = captureDoctorOutput();

    expect($out)->toContain('no entry in filesystems.links targets');

    @rmdir($rootDir);
});

it('warns when an existing symlink points to a path other than the disk root', function () {
    $rootDir = sys_get_temp_dir().'/mediaman-doctor-root-'.bin2hex(random_bytes(4));
    $otherDir = sys_get_temp_dir().'/mediaman-doctor-other-'.bin2hex(random_bytes(4));
    $linkPath = sys_get_temp_dir().'/mediaman-doctor-mismatch-'.bin2hex(random_bytes(4));

    mkdir($rootDir, 0o755, true);
    mkdir($otherDir, 0o755, true);

    if (! @symlink($otherDir, $linkPath)) {
        @rmdir($rootDir);
        @rmdir($otherDir);
        $this->markTestSkipped('symlink() unavailable on this host');
    }

    Config::set('filesystems.disks.mismatch', ['driver' => 'local', 'root' => $rootDir]);
    Config::set('filesystems.links', [$linkPath => $rootDir]);
    Config::set('mediaman.disk', 'mismatch');

    $out = captureDoctorOutput();

    expect($out)->toContain('exists but points to');

    removeLinkOrPath($linkPath);
    @rmdir($rootDir);
    @rmdir($otherDir);
});

// ─── Inventory query failure ────────────────────────────────────────

it('reports an error when the media inventory query fails', function () {
    Schema::dropIfExists('mediaman_media');

    $this->artisan('mediaman:doctor')
        ->expectsOutputToContain('query failed')
        ->assertExitCode(1);
});
