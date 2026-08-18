<?php

namespace Emaia\MediaMan\Console\Commands;

use DateTimeImmutable;
use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaKeys;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationConfig;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\Ulid;
use Throwable;

class PruneResponsiveGenerationsCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaKeys;

    protected $signature = 'mediaman:prune-responsive-generations
                            {--older-than= : Delete inactive generations at least this many days old}
                            {--media= : Comma-separated media keys and bounded integer ranges}
                            {--collection= : Filter by collection name}
                            {--disk= : Scan this disk instead of the currently resolved disks}
                            {--force : Delete eligible generations; default is dry-run}';

    protected $description = 'Dry-run or prune inactive versioned responsive image generations';

    private int $candidates = 0;

    private int $deleted = 0;

    private int $protected = 0;

    private int $failures = 0;

    public function handle(): int
    {
        try {
            $config = ResponsiveGenerationConfig::fromConfig();
            $olderThan = $this->resolveOlderThan($config->retentionDays);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $modelClass = config('mediaman.models.media', Media::class);
        /** @var Media $model */
        $model = new $modelClass;
        $query = $model->newQuery();

        if ($mediaOption = $this->option('media')) {
            $keys = $this->parseMediaKeys((string) $mediaOption);

            if ($keys === []) {
                $this->error('Invalid --media value. Ranges must be positive integers and contain at most 10000 keys.');

                return self::FAILURE;
            }

            $query->whereKey($keys);
        }

        if ($collection = $this->option('collection')) {
            $query->whereHas('collections', fn ($relation) => $relation->where('name', $collection));
        }

        $dryRun = ! $this->option('force');
        $this->section('Prune responsive generations');
        $this->statusLine('Mode', $dryRun ? 'warn' : 'info', $dryRun ? 'dry run' : 'delete');
        $this->statusLine('Older than', 'info', $olderThan.' day(s)');

        foreach ($query->cursor() as $media) {
            $this->processMedia($media, $olderThan, $dryRun);
        }

        $this->newLine();
        $this->statusLine($dryRun ? 'Would delete' : 'Deleted', 'info', (string) ($dryRun ? $this->candidates : $this->deleted));
        $this->statusLine('Protected', 'info', (string) $this->protected);

        if ($this->failures > 0) {
            $this->statusLine('Failures', 'error', (string) $this->failures);
        }

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processMedia(Media $media, int $olderThan, bool $dryRun): void
    {
        $diskOverride = $this->option('disk');
        $persistedDisk = $media->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK);
        $disks = $diskOverride
            ? [(string) $diskOverride]
            : array_values(array_unique(array_filter([
                is_string($persistedDisk) && $persistedDisk !== '' ? $persistedDisk : null,
                $media->responsiveDisk(),
            ])));

        foreach ($disks as $disk) {
            try {
                $this->processMediaDisk($media, $disk, $olderThan, $dryRun);
            } catch (Throwable $e) {
                $this->failures++;
                $this->components->twoColumnDetail(
                    "  #{$media->getKey()} disk [$disk]",
                    '<fg=red>failed</> '.$e->getMessage(),
                );
            }
        }
    }

    private function processMediaDisk(Media $media, string $disk, int $olderThan, bool $dryRun): void
    {
        $filesystem = Storage::disk($disk);
        $base = rtrim(app(MediaResolver::class)->pathForResponsive($media), '/');
        $threshold = now()->subDays($olderThan);

        foreach ($filesystem->directories($base) as $directory) {
            $generation = basename($directory);

            if (! $this->isManagedGeneration($generation)) {
                continue;
            }

            $generatedAt = Ulid::fromString($generation)->getDateTime();

            if ($generatedAt > now()) {
                $this->protected++;
                $this->warn("  #{$media->getKey()} [$disk] $generation retained: future timestamp");

                continue;
            }

            if ($generatedAt > $threshold) {
                continue;
            }

            if ($this->hasFreshMarker($filesystem, $directory)) {
                $this->protected++;

                continue;
            }

            if ($this->isProtectedNow($media, $generation, $base)) {
                $this->protected++;

                continue;
            }

            $this->candidates++;
            $age = $generatedAt->diff(now())->days;
            $action = $dryRun ? 'would delete' : 'deleting';
            $this->line("  #{$media->getKey()} [$disk] $generation: $action ({$age}d, files/bytes n/a)");

            if (! $dryRun) {
                if (! $filesystem->deleteDirectory($directory)) {
                    throw new \RuntimeException("Failed to delete generation [$generation].");
                }

                $this->deleted++;
            }
        }
    }

    private function hasFreshMarker(Filesystem $filesystem, string $directory): bool
    {
        $path = $directory.'/'.ResponsiveImageGenerator::IN_PROGRESS_MARKER;

        if (! $filesystem->exists($path)) {
            return false;
        }

        try {
            $marker = json_decode($filesystem->get($path), true, flags: JSON_THROW_ON_ERROR);
            $startedAt = new DateTimeImmutable($marker['started_at'] ?? '');
            $timeout = ResponsiveGenerationConfig::fromConfig()->generationTimeoutMinutes;

            return $startedAt > now()->subMinutes($timeout)->toDateTimeImmutable();
        } catch (Throwable) {
            return true;
        }
    }

    private function isProtectedNow(Media $media, string $generation, string $expectedBase): bool
    {
        return DB::connection($media->getConnectionName())->transaction(function () use (
            $media,
            $generation,
            $expectedBase,
        ): bool {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return false;
            }

            if (rtrim(app(MediaResolver::class)->pathForResponsive($fresh), '/') !== $expectedBase) {
                return true;
            }

            return in_array($generation, $this->protectedGenerations($fresh, $expectedBase), true);
        });
    }

    /** @return string[] */
    private function protectedGenerations(Media $media, string $base): array
    {
        $protected = [];
        $explicit = $media->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION);

        if (is_string($explicit) && $this->isManagedGeneration($explicit)) {
            $protected[$explicit] = true;
        }

        $manifest = $media->getCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, []);

        if (is_array($manifest)) {
            foreach ($manifest as $item) {
                $path = is_array($item) ? ($item['path'] ?? null) : null;
                $prefix = $base.'/';

                if (! is_string($path) || ! str_starts_with($path, $prefix)) {
                    continue;
                }

                $segment = explode('/', substr($path, strlen($prefix)), 2)[0];

                if ($this->isManagedGeneration($segment)) {
                    $protected[$segment] = true;
                }
            }
        }

        return array_keys($protected);
    }

    private function isManagedGeneration(string $generation): bool
    {
        return $generation !== '00000000000000000000000000'
            && $generation !== '7ZZZZZZZZZZZZZZZZZZZZZZZZZ'
            && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $generation) === 1
            && Ulid::isValid($generation);
    }

    private function resolveOlderThan(int $default): int
    {
        $value = $this->option('older-than');

        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
            throw new \InvalidArgumentException('--older-than must be a non-negative integer.');
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (! is_int($normalized)) {
            throw new \InvalidArgumentException('--older-than must be a non-negative integer.');
        }

        return $normalized;
    }
}
