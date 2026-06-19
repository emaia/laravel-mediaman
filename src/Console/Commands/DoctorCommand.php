<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'mediaman:doctor';

    protected $description = 'Run a health check against the MediaMan pipeline (schema, disk, image driver, queue, conversions, inventory). Read-only.';

    /**
     * Tracks whether any check produced an error, used as the exit code.
     */
    protected bool $hasErrors = false;

    public function handle(): int
    {
        $this->checkSchema();
        $this->checkConfig();
        $this->checkDisk();
        $this->checkSymlink();
        $this->checkImageDriver();
        $this->checkQueue();
        $this->checkConversions();
        $this->checkMediaInventory();

        $this->newLine();

        return $this->hasErrors ? self::FAILURE : self::SUCCESS;
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
            $this->statusLine('Published', 'ok', "at {$relative}");
        } else {
            $this->statusLine('Published', 'info', 'no (using package defaults — run `php artisan mediaman:publish-config` to customize)');
        }
    }

    protected function checkDisk(): void
    {
        $this->section('Disk');

        $configured = config('mediaman.disk');
        $effective = $configured ?? config('filesystems.default');

        $this->statusLine('Configured', 'info', $configured === null ? "null (fallback to filesystems.default = '{$effective}')" : "'{$effective}'");

        try {
            $disk = Storage::disk($effective);
        } catch (Throwable $e) {
            $this->statusLine('Probe (write/read/delete)', 'error', "disk '{$effective}' is not defined in filesystems.php");

            return;
        }

        $probeFile = 'mediaman-doctor-probe-'.bin2hex(random_bytes(6)).'.txt';
        $probeContent = 'mediaman-doctor-'.microtime(true);

        try {
            $disk->put($probeFile, $probeContent);
            $readBack = $disk->get($probeFile);
            $disk->delete($probeFile);

            if ($readBack !== $probeContent) {
                $this->statusLine('Probe (write/read/delete)', 'error', 'read-back content did not match (disk integrity issue)');

                return;
            }

            $this->statusLine('Probe (write/read/delete)', 'ok', 'OK');
        } catch (Throwable $e) {
            $this->statusLine('Probe (write/read/delete)', 'error', $e->getMessage());

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
        $diskConfig = config("filesystems.disks.{$diskName}");

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
                "no entry in filesystems.links targets '{$root}' — disk may be private, or run `artisan storage:link` after adding one"
            );

            return;
        }

        foreach ($matching as $linkPath) {
            if (is_link($linkPath)) {
                $actual = readlink($linkPath) ?: '';
                if ($this->normalizePath($actual) === $rootNorm) {
                    $this->statusLine('Symlink', 'ok', "{$linkPath} → {$root}");
                } else {
                    $this->statusLine('Symlink', 'warn', "{$linkPath} exists but points to '{$actual}' (expected '{$root}')");
                }
            } elseif (file_exists($linkPath)) {
                $this->statusLine('Symlink', 'error', "{$linkPath} exists but is not a symlink (something is squatting on the path)");
            } else {
                $this->statusLine('Symlink', 'warn', "{$linkPath} missing — run `php artisan storage:link`");
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
        }
    }

    protected function checkQueue(): void
    {
        $this->section('Queue');

        $connection = config('mediaman.queue') ?? config('queue.default');
        $this->statusLine('Connection', 'info', "'{$connection}'");

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
                number_format($withResponsive).' / '.number_format($total)." ({$pct}%)"
            );
        } catch (Throwable $e) {
            $this->statusLine('Responsive coverage', 'warn', 'coverage query failed: '.$e->getMessage());
        }
    }

    /**
     * Print a section header — matches Laravel's `about` command style.
     */
    protected function section(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('  <fg=green;options=bold>'.$title.'</>');
    }

    /**
     * Print a labeled detail line with an icon based on level.
     *
     * Levels: ok (✓ green), warn (⚠ yellow), error (✗ red), info (· dim).
     */
    protected function statusLine(string $label, string $level, string $value): void
    {
        $icon = match ($level) {
            'ok' => '<fg=green>✓</>',
            'warn' => '<fg=yellow>⚠</>',
            'error' => '<fg=red>✗</>',
            default => '<fg=gray>·</>',
        };

        if ($level === 'error') {
            $this->hasErrors = true;
        }

        $this->components->twoColumnDetail($label, "{$icon} {$value}");
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return sprintf('%.1f %s', $value, $units[$unitIndex]);
    }
}
