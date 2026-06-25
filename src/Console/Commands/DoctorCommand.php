<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

class DoctorCommand extends Command
{
    use CommandOutputStyle;

    protected $signature = 'mediaman:doctor';

    protected $description = 'Run a health check against the MediaMan pipeline (schema, disk, image driver, queue, conversions, inventory). Read-only.';

    public function handle(): int
    {
        $this->checkSchema();
        $this->checkConfig();
        $this->checkDisk();
        $this->checkSymlink();
        $this->checkImageDriver();
        $this->checkQueue();
        $this->checkConversions();
        $this->checkSecurity();
        $this->checkMediaInventory();

        $this->newLine();

        return $this->hasErrors ? self::FAILURE : self::SUCCESS;
    }

    /** Surface security-posture defaults that adopters commonly miss. */
    protected function checkSecurity(): void
    {
        $this->section('Security');

        $allowed = config('mediaman.allowed_mime_types', []);

        if (empty($allowed)) {
            $this->statusLine(
                'MIME allow-list',
                'warn',
                'empty — accepting all MIME types. Set `mediaman.allowed_mime_types` for production. See docs/security.md → Hardening'
            );
        } else {
            $this->statusLine('MIME allow-list', 'ok', count($allowed).' MIME type(s) whitelisted');
        }

        if (config('mediaman.svg.enabled', false)) {
            $this->statusLine(
                'SVG uploads',
                'warn',
                'enabled — uploads sanitized via enshrined/svg-sanitize. Verify same-origin serving is safe.'
            );
        } else {
            $this->statusLine('SVG uploads', 'ok', 'disabled (default)');
        }

        if (! config('mediaman.block_disallowed_extensions', true)) {
            $this->statusLine(
                'Extension blocklist',
                'warn',
                'disabled — server-executable extensions can land. Re-enable unless you have a specific reason.'
            );
        } else {
            $count = count(config('mediaman.disallowed_extensions', []));
            $this->statusLine('Extension blocklist', 'ok', "$count extension(s) blocked");
        }
    }

    protected function checkSchema(): void
    {
        $this->section('Schema migrations');

        $expectedTables = [
            config('mediaman.tables.media', 'mediaman_media'),
            config('mediaman.tables.collections', 'mediaman_collections'),
            config('mediaman.tables.collection_media', 'mediaman_collection_media'),
            config('mediaman.tables.mediables', 'mediaman_mediables'),
        ];

        $missing = array_filter($expectedTables, fn ($t) => ! Schema::hasTable($t));

        if (empty($missing)) {
            $this->statusLine('Tables present', 'ok', 'all '.count($expectedTables).' expected tables found');
        } else {
            $this->statusLine('Tables present', 'error', 'missing: '.implode(', ', $missing));
        }
    }

    /**
     * Inform whether config/mediaman.php has been published. Both states are
     * fine — package defaults via mergeConfigFrom keep the app working — but
     * the line saves the user from "why isn't my config edit taking effect?"
     * when they expected to be editing a published file that doesn't exist yet.
     */
    protected function checkConfig(): void
    {
        $this->section('Config file');

        $path = config_path('mediaman.php');

        if (file_exists($path)) {
            // Display relative to base_path() so the value fits in the doctor
            // output and doesn't get truncated by twoColumnDetail; the absolute
            // root rarely adds diagnostic value.
            $relative = str_starts_with($path, base_path().'/')
                ? substr($path, strlen(base_path()) + 1)
                : $path;
            $this->statusLine('Published', 'ok', "at $relative");
        } else {
            $this->statusLine('Published', 'info', 'no (using package defaults — run `php artisan mediaman:publish-config` to customize)');
        }
    }

    protected function checkDisk(): void
    {
        $this->section('Disk');

        $configured = config('mediaman.disk');
        $effective = $configured ?? config('filesystems.default');

        $this->statusLine('Configured', 'info', $configured === null ? "null (fallback to filesystems.default = '$effective')" : "'$effective'");

        $this->probeDisk($effective, 'Probe (write/read/delete)');

        $this->checkVariantDisks();
    }

    /**
     * Probe every distinct disk referenced by conversion registrations, the
     * `mediaman.conversions.disk` default, and the `mediaman.responsive_images.disk`
     * config (each only when it differs from the main disk to avoid a duplicate probe).
     */
    protected function checkVariantDisks(): void
    {
        $main = config('mediaman.disk') ?? config('filesystems.default');

        $conversionDisks = array_filter([
            ...app(ConversionRegistry::class)->disks(),
            config('mediaman.conversions.disk'),
        ]);

        foreach (array_unique($conversionDisks) as $diskName) {
            if ($diskName === $main) {
                continue;
            }

            $this->probeDisk($diskName, "Conversion disk '$diskName'");
        }

        $responsiveDisk = config('mediaman.responsive_images.disk');

        if ($responsiveDisk !== null && $responsiveDisk !== $main) {
            $this->probeDisk($responsiveDisk, "Responsive disk '$responsiveDisk'");
        }
    }

    /**
     * Write a probe file to a disk and verify read-back integrity.
     */
    protected function probeDisk(string $diskName, string $label): void
    {
        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable $e) {
            $this->statusLine($label, 'error', "disk '$diskName' is not defined in filesystems.php");

            return;
        }

        $probeFile = 'mediaman-doctor-probe-'.bin2hex(random_bytes(6)).'.txt';
        $probeContent = 'mediaman-doctor-'.microtime(true);

        try {
            $disk->put($probeFile, $probeContent);
            $readBack = $disk->get($probeFile);
            $disk->delete($probeFile);

            if ($readBack !== $probeContent) {
                $this->statusLine($label, 'error', 'read-back content did not match (disk integrity issue)');

                return;
            }

            $this->statusLine($label, 'ok', 'OK');
        } catch (Throwable $e) {
            $this->statusLine($label, 'error', $e->getMessage());

            // Best-effort cleanup if write succeeded but a later step failed.
            try {
                $disk->delete($probeFile);
            } catch (Throwable) {
                // already gone or never written; ignore.
            }
        }
    }

    /**
     * Verify that the symlink expected by `php artisan storage:link` for the
     * effective disk actually exists. Catches the classic "I uploaded a file
     * and the URL returns 404" pitfall after package install.
     */
    protected function checkSymlink(): void
    {
        $this->section('Public symlink');

        $diskName = config('mediaman.disk') ?? config('filesystems.default');
        $diskConfig = config("filesystems.disks.$diskName");

        if (! is_array($diskConfig)) {
            // Disk not defined — checkDisk() already reported this.
            $this->statusLine('Disk config', 'info', 'skipped (disk not defined)');

            return;
        }

        if (($diskConfig['driver'] ?? null) !== 'local') {
            $this->statusLine('Status', 'info', "not applicable (driver: '{$diskConfig['driver']}')");

            return;
        }

        $root = $diskConfig['root'] ?? null;
        if (! is_string($root) || $root === '') {
            $this->statusLine('Status', 'info', 'skipped (disk root not configured)');

            return;
        }

        // Find any filesystems.links entries whose target points at this disk's root.
        // Paths are normalized (forward slashes, no trailing separator) so the compare
        // works under Windows too, where readlink() and config values may use `\`.
        $rootNorm = $this->normalizePath($root);
        $links = config('filesystems.links', []);
        $matching = is_array($links)
            ? array_keys(array_filter(
                $links,
                fn ($target) => is_string($target) && $this->normalizePath($target) === $rootNorm
            ))
            : [];

        if (empty($matching)) {
            $this->statusLine(
                'Status',
                'info',
                "no entry in filesystems.links targets '$root' — disk may be private, or run `artisan storage:link` after adding one"
            );

            return;
        }

        foreach ($matching as $linkPath) {
            if (is_link($linkPath)) {
                $actual = readlink($linkPath) ?: '';
                if ($this->normalizePath($actual) === $rootNorm) {
                    $this->statusLine('Symlink', 'ok', "$linkPath → $root");
                } else {
                    $this->statusLine('Symlink', 'warn', "$linkPath exists but points to '$actual' (expected '$root')");
                }
            } elseif (file_exists($linkPath)) {
                $this->statusLine('Symlink', 'error', "$linkPath exists but is not a symlink (something is squatting on the path)");
            } else {
                $this->statusLine('Symlink', 'warn', "$linkPath missing — run `php artisan storage:link`");
            }
        }
    }

    /**
     * Normalize a filesystem path for portable comparison: collapse backslashes
     * to forward slashes and drop any trailing separator. Windows' readlink()
     * and filesystem config values may use `\`, while Laravel config typically
     * uses `/` — comparing without normalization breaks on Windows hosts.
     */
    protected function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    protected function checkImageDriver(): void
    {
        $this->section('Image driver');

        $configured = config('mediaman.driver');
        $this->statusLine('Configured', 'info', $configured ?? 'null (auto-detect)');

        try {
            $manager = app(ImageManager::class);
            $this->statusLine('Effective', 'ok', get_class($manager->driver));
        } catch (Throwable $e) {
            $this->statusLine('Effective', 'error', 'resolution failed: '.$e->getMessage());
            $this->driverHint($e);

            return;
        }

        // Real 1×1 PNG encode — Vips driver class instantiates without
        // exercising FFI; bindings only load on the first decode/encode
        // call. Without this probe doctor reports "ok" while real uploads
        // would blow up. PNG is the universal fallback every driver
        // supports, so a failure here is unambiguously a driver/FFI issue
        // (not a codec gap).
        try {
            $manager->createImage(1, 1)->encodeUsingFormat(Format::PNG);
            $this->statusLine('Probe', 'ok', 'driver encodes a 1×1 test image');
        } catch (Throwable $e) {
            $this->statusLine('Probe', 'error', 'driver loaded but failed first encode: '.$e->getMessage());
            $this->driverHint($e);

            return;
        }

        $this->sapiHint($manager);
        $this->probeResponsiveFormats($manager);
    }

    /**
     * Surface the SAPI/ini divergence gotcha. Drivers backed by FFI (vips)
     * are configured per-SAPI; the SAPI doctor runs under may not be the
     * one production serves under. The hint always shows the current SAPI
     * + ffi.enable so operators can compare against whichever runtime
     * actually handles requests.
     */
    protected function sapiHint(ImageManager $manager): void
    {
        if (! str_contains(get_class($manager->driver), 'Vips')) {
            return;
        }

        $sapi = php_sapi_name();
        $ffi = ini_get('ffi.enable') ?: 'unset';

        $this->statusLine('Probe SAPI', 'info', "{$sapi} (ffi.enable={$ffi})");

        $this->statusLine(
            'Hint',
            'info',
            "vips uses PHP FFI, which is configured per-SAPI. This probe ran under '{$sapi}' — if uploads fail at runtime under a different SAPI (fpm-fcgi, cli-server via 'artisan serve', apache2handler, …) verify ffi.enable in THAT SAPI's php.ini. 'php --ini' (CLI) and '<?php phpinfo(); ?>' (web) show the loaded file per SAPI."
        );
    }

    /**
     * Surface an actionable hint for the known FFI-related failure modes —
     * triggered by Intervention's misleading "libvips not installed" message
     * (FFI off entirely) and by FFI-level errors thrown on the 1×1 probe.
     * Both routes point at the same fix because FFI misconfiguration is the
     * actual cause in every case we've seen.
     */
    protected function driverHint(Throwable $e): void
    {
        $message = $e->getMessage();
        $looksLikeFfi = str_contains($message, 'libvips does not seem to be installed')
            || str_contains($message, 'FFI')
            || str_contains($message, 'ffi.enable');

        if (! $looksLikeFfi) {
            return;
        }

        $this->statusLine(
            'Hint',
            'info',
            "intervention/image-driver-vips uses PHP FFI. Either set ffi.enable=true (loads bindings on demand), or set ffi.enable=preload AND preload the vips binding via an opcache.preload script — 'preload' alone without the binding script will still fail at runtime."
        );
    }

    /**
     * Probe each format declared in responsive_images.formats by attempting a
     * tiny encode. Catches the silent-failure modes (driver lacks codec,
     * libheif missing HEVC encoder plugin, etc.) before runtime hits them.
     */
    protected function probeResponsiveFormats(ImageManager $manager): void
    {
        $formats = config('mediaman.responsive_images.formats', []);

        if (! is_array($formats) || $formats === []) {
            return;
        }

        $map = [
            'webp' => Format::WEBP,
            'avif' => Format::AVIF,
            'heic' => Format::HEIC,
            'jpg' => Format::JPEG,
            'jpeg' => Format::JPEG,
            'png' => Format::PNG,
            'gif' => Format::GIF,
        ];

        foreach ($formats as $format) {
            $format = strtolower((string) $format);
            $label = "Format probe ({$format})";

            if (! isset($map[$format])) {
                $this->statusLine($label, 'warn', 'unrecognized — variant will be skipped at runtime');

                continue;
            }

            try {
                $bytes = (string) $manager->createImage(10, 10)->fill('ff0000')->encodeUsingFormat($map[$format]);

                if (strlen($bytes) === 0) {
                    $hint = $format === 'heic'
                        ? 'install libheif HEVC encoder plugin (e.g. libheif-plugin-x265)'
                        : 'driver lacks codec support for this format';

                    $this->statusLine($label, 'warn', "encoder returned zero bytes — {$hint}");
                } else {
                    $this->statusLine($label, 'ok', 'encodes ✓');
                }
            } catch (Throwable $e) {
                $this->statusLine($label, 'warn', $e->getMessage());
            }
        }
    }

    protected function checkQueue(): void
    {
        $this->section('Queue');

        $connection = config('mediaman.queue') ?? config('queue.default');
        $this->statusLine('Connection', 'info', "'$connection'");

        $autoGenerate = (bool) config('mediaman.responsive_images.auto_generate', false);
        $queued = (bool) config('mediaman.responsive_images.queue', true);

        if ($autoGenerate && $queued) {
            $this->statusLine('Auto-generate responsive', 'warn', 'enabled — ensure a queue worker is running');
        } elseif ($autoGenerate) {
            $this->statusLine('Auto-generate responsive', 'info', 'enabled (synchronous, no worker required)');
        } else {
            $this->statusLine('Auto-generate responsive', 'info', 'disabled');
        }
    }

    protected function checkConversions(): void
    {
        $this->section('Conversions');

        $registry = app(ConversionRegistry::class);

        $this->statusLine('Registered', 'info', (string) count($registry->all()));
    }

    protected function checkMediaInventory(): void
    {
        $this->section('Media inventory');

        try {
            $total = Media::query()->count();
            $bytes = (int) Media::query()->sum('size');
        } catch (Throwable $e) {
            $this->statusLine('Records', 'error', 'query failed: '.$e->getMessage());

            return;
        }

        $this->statusLine('Records', 'info', number_format($total));
        $this->statusLine('Total size', 'info', $this->formatBytes($bytes));

        if ($total === 0) {
            return;
        }

        // Count records that have a non-empty responsive_images custom_properties entry.
        // The custom_properties cast stores JSON; we check via LIKE on the raw column
        // for cross-driver compatibility (works on sqlite/mysql/pgsql without JSON
        // path operators).
        try {
            $withResponsive = Media::query()
                ->where('custom_properties', 'like', '%"responsive_images":%')
                ->count();

            $pct = (int) round($withResponsive / $total * 100);
            $this->statusLine(
                'Responsive coverage',
                'info',
                number_format($withResponsive).' / '.number_format($total)." ($pct%)"
            );
        } catch (Throwable $e) {
            $this->statusLine('Responsive coverage', 'warn', 'coverage query failed: '.$e->getMessage());
        }
    }
}
