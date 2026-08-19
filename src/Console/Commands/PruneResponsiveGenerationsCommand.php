<?php

namespace Emaia\MediaMan\Console\Commands;

use DateTimeImmutable;
use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaKeys;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGeneration;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationConfig;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
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

        if (($mediaOption = $this->option('media')) !== null) {
            $keys = $this->parseMediaKeys((string) $mediaOption);

            if ($keys === []) {
                $this->error('Invalid --media value. Ranges must be positive integers and contain at most 10000 keys.');

                return self::FAILURE;
            }

            $query->whereKey($keys);
        }

        if (($collection = $this->option('collection')) !== null) {
            if ($collection === '') {
                $this->error('Invalid --collection value.');

                return self::FAILURE;
            }

            $query->whereHas('collections', fn ($relation) => $relation->where('name', $collection));
        }

        if (($disk = $this->option('disk')) !== null) {
            if ($disk === '') {
                $this->error('Invalid --disk value.');

                return self::FAILURE;
            }

            try {
                Storage::disk((string) $disk);
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $dryRun = ! $this->option('force');
        $this->section('Prune responsive generations');
        $this->statusLine('Mode', $dryRun ? 'warn' : 'info', $dryRun ? 'dry run' : 'delete');
        $this->statusLine('Older than', 'info', $olderThan.' day(s)');

        foreach ($query->lazy(100) as $media) {
            $this->processMedia($media, $olderThan, $dryRun, $config);
        }

        $this->newLine();
        $this->statusLine($dryRun ? 'Would delete' : 'Deleted', 'info', (string) ($dryRun ? $this->candidates : $this->deleted));
        $this->statusLine('Protected', 'info', (string) $this->protected);

        if ($this->failures > 0) {
            $this->statusLine('Failures', 'error', (string) $this->failures);
        }

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processMedia(
        Media $media,
        int $olderThan,
        bool $dryRun,
        ResponsiveGenerationConfig $config,
    ): void {
        $diskOverride = $this->option('disk');
        $persistedDisk = $media->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK);
        $disks = $diskOverride !== null
            ? [(string) $diskOverride]
            : array_values(array_unique(array_filter([
                is_string($persistedDisk) && $persistedDisk !== '' ? $persistedDisk : null,
                $media->responsiveDisk(),
                ...$media->responsiveGenerationDisks(),
            ])));

        foreach ($disks as $disk) {
            try {
                $this->processMediaDisk($media, $disk, $olderThan, $dryRun, $config);
            } catch (Throwable $e) {
                $this->failures++;
                $this->components->twoColumnDetail(
                    "  #{$media->getKey()} disk [$disk]",
                    '<fg=red>failed</> '.$e->getMessage(),
                );
            }
        }
    }

    private function processMediaDisk(
        Media $media,
        string $disk,
        int $olderThan,
        bool $dryRun,
        ResponsiveGenerationConfig $config,
    ): void {
        $filesystem = Storage::disk($disk);
        $base = rtrim(app(MediaResolver::class)->pathForResponsive($media), '/');
        $threshold = now()->subDays($olderThan);

        foreach ($filesystem->directories($base) as $directory) {
            $generation = basename($directory);

            if (! ResponsiveGeneration::isManaged($generation)) {
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

            try {
                if ($this->hasFreshMarker($filesystem, $directory, $config)) {
                    $this->protected++;

                    continue;
                }
            } catch (Throwable $e) {
                $this->failures++;
                $this->protected++;
                $this->components->twoColumnDetail(
                    "  #{$media->getKey()} [$disk] $generation",
                    '<fg=red>failed</> '.$e->getMessage(),
                );

                continue;
            }

            if ($dryRun && $this->isProtectedNow($media, $generation, $base)) {
                $this->protected++;

                continue;
            }

            $claim = $dryRun ? null : $this->claimForPruning($media, $generation, $base, $config);

            if (! $dryRun && $claim === null) {
                $this->protected++;

                continue;
            }

            $this->candidates++;
            $age = $generatedAt->diff(now())->days;
            $action = $dryRun ? 'would delete' : 'deleting';
            $metrics = $this->generationMetrics($filesystem, $directory);
            $this->line("  #{$media->getKey()} [$disk] $generation: $action ({$age}d, $metrics)");

            if (! $dryRun) {
                try {
                    if (! $filesystem->deleteDirectory($directory)) {
                        throw new \RuntimeException("Failed to delete generation [$generation].");
                    }

                    $this->deleted++;
                } finally {
                    $this->releasePruningClaim($media, $generation, $claim);
                }
            }
        }
    }

    private function hasFreshMarker(
        Filesystem $filesystem,
        string $directory,
        ResponsiveGenerationConfig $config,
    ): bool {
        $paths = array_values(array_filter(
            $filesystem->files($directory),
            fn (string $path) => str_starts_with(basename($path), ResponsiveImageGenerator::IN_PROGRESS_MARKER),
        ));

        if ($paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            try {
                $marker = json_decode($filesystem->get($path), true, flags: JSON_THROW_ON_ERROR);
                $startedAt = new DateTimeImmutable($marker['started_at'] ?? '');

                if ($startedAt > now()->subMinutes($config->generationTimeoutMinutes)->toDateTimeImmutable()) {
                    return true;
                }
            } catch (Throwable $e) {
                throw new \RuntimeException("Invalid in-progress marker [$path]; refusing to prune.", previous: $e);
            }
        }

        return false;
    }

    private function isProtectedNow(Media $media, string $generation, string $expectedBase): bool
    {
        /** @var Media|null $fresh */
        $fresh = $media->newQuery()->useWritePdo()->whereKey($media->getKey())->first();

        if ($fresh === null) {
            return false;
        }

        if (rtrim(app(MediaResolver::class)->pathForResponsive($fresh), '/') !== $expectedBase) {
            return true;
        }

        return in_array($generation, $this->protectedGenerations($fresh, $expectedBase), true);
    }

    private function claimForPruning(
        Media $media,
        string $generation,
        string $expectedBase,
        ResponsiveGenerationConfig $config,
    ): ?string {
        return DB::connection($media->getConnectionName())->transaction(function () use (
            $media,
            $generation,
            $expectedBase,
            $config,
        ): ?string {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return null;
            }

            if (
                $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_CLEARING)
                || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)
            ) {
                return null;
            }

            if (rtrim(app(MediaResolver::class)->pathForResponsive($fresh), '/') !== $expectedBase) {
                return null;
            }

            if (in_array($generation, $this->protectedGenerations($fresh, $expectedBase), true)) {
                return null;
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $claims = $properties[Media::PROPERTY_RESPONSIVE_PRUNING] ?? [];
            $claims = is_array($claims) ? $claims : [];
            $existing = $claims[$generation] ?? null;

            if (is_array($existing) && isset($existing['started_at'])) {
                try {
                    $startedAt = new DateTimeImmutable($existing['started_at']);

                    if ($startedAt > now()->subMinutes($config->generationTimeoutMinutes)->toDateTimeImmutable()) {
                        return null;
                    }
                } catch (Throwable) {
                    return null;
                }
            }

            $token = strtoupper((string) new Ulid);
            $claims[$generation] = [
                'token' => $token,
                'started_at' => now()->toIso8601String(),
            ];
            $properties[Media::PROPERTY_RESPONSIVE_PRUNING] = $claims;
            $fresh->custom_properties = $properties;
            $fresh->save();

            return $token;
        });
    }

    private function releasePruningClaim(Media $media, string $generation, ?string $token): void
    {
        if ($token === null) {
            return;
        }

        DB::connection($media->getConnectionName())->transaction(function () use ($media, $generation, $token): void {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $claims = $properties[Media::PROPERTY_RESPONSIVE_PRUNING] ?? [];

            if (! is_array($claims) || ($claims[$generation]['token'] ?? null) !== $token) {
                return;
            }

            unset($claims[$generation]);

            if ($claims === []) {
                unset($properties[Media::PROPERTY_RESPONSIVE_PRUNING]);
            } else {
                $properties[Media::PROPERTY_RESPONSIVE_PRUNING] = $claims;
            }

            $fresh->custom_properties = $properties;
            $fresh->save();
        });
    }

    /** @return string[] */
    private function protectedGenerations(Media $media, string $base): array
    {
        $protected = [];
        $explicit = $media->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION);

        if ($explicit !== null) {
            if (! is_string($explicit) || ! ResponsiveGeneration::isManaged($explicit)) {
                throw new \RuntimeException('Active responsive generation metadata is invalid; refusing to prune.');
            }

            $protected[$explicit] = true;
        }

        $manifest = $media->getCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, []);

        if (! is_array($manifest)) {
            throw new \RuntimeException('Responsive manifest metadata is invalid; refusing to prune.');
        }

        foreach ($manifest as $item) {
            if (! is_array($item) || ! isset($item['path']) || ! is_string($item['path'])) {
                throw new \RuntimeException('Responsive manifest item is invalid; refusing to prune.');
            }

            $path = $item['path'];
            $prefix = $base.'/';

            if (! str_starts_with($path, $prefix)) {
                throw new \RuntimeException("Responsive manifest path [$path] is outside [$base]; refusing to prune.");
            }

            $segment = explode('/', substr($path, strlen($prefix)), 2)[0];

            if (ResponsiveGeneration::isManaged($segment)) {
                $protected[$segment] = true;
            }
        }

        return array_keys($protected);
    }

    private function generationMetrics(Filesystem $filesystem, string $directory): string
    {
        if (! $filesystem instanceof FilesystemAdapter) {
            return 'files/bytes n/a';
        }

        try {
            $files = 0;
            $bytes = 0;

            foreach ($filesystem->getDriver()->listContents($directory, true) as $attributes) {
                if (! $attributes instanceof FileAttributes) {
                    continue;
                }

                $size = $attributes->fileSize();

                if ($size === null) {
                    return 'files/bytes n/a';
                }

                $files++;
                $bytes += $size;
            }

            $label = $files === 1 ? 'file' : 'files';

            return "$files $label, ".$this->formatBytes($bytes);
        } catch (Throwable) {
            return 'files/bytes n/a';
        }
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
