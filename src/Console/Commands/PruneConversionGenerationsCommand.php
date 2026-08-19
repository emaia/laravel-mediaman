<?php

namespace Emaia\MediaMan\Console\Commands;

use DateTimeImmutable;
use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaKeys;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Conversions\ConversionGeneration;
use Emaia\MediaMan\Conversions\ConversionGenerationConfig;
use Emaia\MediaMan\Conversions\ConversionPath;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\Traits\ResolvesModels;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\Ulid;
use Throwable;

class PruneConversionGenerationsCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaKeys;
    use ResolvesModels;

    protected $signature = 'mediaman:prune-conversion-generations
                            {--older-than= : Minimum inactive age in days}
                            {--media= : Comma-separated media keys and bounded integer ranges}
                            {--collection= : Filter by collection name}
                            {--conversion= : Comma-separated conversion names}
                            {--disk= : Scan one disk instead of known conversion disks}
                            {--force : Delete candidates; default is dry-run}';

    protected $description = 'Prune inactive versioned conversion generations';

    public function handle(): int
    {
        try {
            $config = ConversionGenerationConfig::fromConfig();
            $olderThan = $this->parseOlderThan($config->retentionDays);
            $conversions = $this->parseConversions();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $query = $this->mediaModel()::query();

        if ($this->option('media') !== null) {
            $keys = $this->parseMediaKeys((string) $this->option('media'));

            if ($keys === []) {
                $this->error('Invalid --media value.');

                return self::FAILURE;
            }

            $query->whereKey($keys);
        }

        if ($this->option('collection') !== null) {
            $collection = trim((string) $this->option('collection'));

            if ($collection === '') {
                $this->error('Invalid --collection value.');

                return self::FAILURE;
            }

            $query->whereHas('collections', fn ($related) => $related->where('name', $collection));
        }

        $diskOverride = $this->option('disk');

        if ($diskOverride !== null && trim((string) $diskOverride) === '') {
            $this->error('Invalid --disk value.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $cutoff = now()->subDays($olderThan)->toDateTimeImmutable();
        $candidates = 0;
        $deleted = 0;
        $protected = 0;
        $failures = 0;
        $this->section('Prune conversion generations');
        $this->statusLine('Mode', $force ? 'warn' : 'info', $force ? 'delete' : 'dry run');
        $this->statusLine('Retention', 'info', "$olderThan day(s)");

        $query->lazy(100)->each(function (Media $media) use (
            $config,
            $conversions,
            $diskOverride,
            $cutoff,
            $force,
            &$candidates,
            &$deleted,
            &$protected,
            &$failures,
        ): void {
            $disks = $diskOverride !== null
                ? [(string) $diskOverride]
                : array_values(array_unique(array_filter([
                    ...$media->getConversionDisks(),
                    config('mediaman.conversions.disk'),
                    $media->disk,
                ], fn ($disk) => is_string($disk) && $disk !== '')));
            $names = $this->conversionNames($media, $conversions, $disks);

            foreach ($names as $conversion) {
                $rawFiles = $media->getCustomProperty(Media::PROPERTY_CONVERSION_FILES, []);
                $hasRawEntry = is_array($rawFiles) && array_key_exists($conversion, $rawFiles);
                $active = $media->getConversionFile($conversion);

                if ($hasRawEntry && $active === null) {
                    $this->error("  Media {$media->getKey()} [$conversion]: malformed active metadata retained.");
                    $failures++;

                    continue;
                }

                try {
                    $base = ConversionPath::directory(
                        app(MediaResolver::class)->pathForConversion($media, $conversion),
                    );
                } catch (Throwable $e) {
                    $this->error("  Media {$media->getKey()} [$conversion]: {$e->getMessage()}");
                    $failures++;

                    continue;
                }

                foreach ($disks as $disk) {
                    try {
                        $filesystem = Storage::disk($disk);
                        $directories = $filesystem->directories($base);
                    } catch (Throwable $e) {
                        $this->error("  Media {$media->getKey()} [$conversion/$disk]: {$e->getMessage()}");
                        $failures++;

                        continue;
                    }

                    foreach ($directories as $directory) {
                        $generation = basename($directory);

                        if (! ConversionGeneration::isManaged($generation)) {
                            continue;
                        }

                        try {
                            $generatedAt = Ulid::fromString($generation)->getDateTime();
                        } catch (Throwable) {
                            continue;
                        }

                        if ($generatedAt > now()->toDateTimeImmutable()) {
                            $this->warn("  Media {$media->getKey()} [$conversion]: future generation $generation retained.");
                            $protected++;

                            continue;
                        }

                        if ($generatedAt > $cutoff) {
                            continue;
                        }

                        try {
                            if ($this->markerProtects($filesystem, $directory, $config)) {
                                $protected++;

                                continue;
                            }
                        } catch (Throwable $e) {
                            $this->error("  Media {$media->getKey()} [$conversion/$disk]: marker check failed: {$e->getMessage()}");
                            $failures++;

                            continue;
                        }

                        if (($active['generation'] ?? null) === $generation) {
                            $protected++;

                            continue;
                        }

                        $candidates++;
                        $this->line("  Media {$media->getKey()} [$conversion/$disk]: $generation");

                        if (! $force) {
                            continue;
                        }

                        try {
                            $token = $this->claim($media, $conversion, $generation, $base, $config);
                        } catch (Throwable $e) {
                            $this->error("  Media {$media->getKey()} [$conversion/$disk]: claim failed: {$e->getMessage()}");
                            $failures++;

                            continue;
                        }

                        if ($token === null) {
                            continue;
                        }

                        try {
                            if (! $filesystem->deleteDirectory($directory)) {
                                throw new \RuntimeException("Failed to delete conversion generation [$directory].");
                            }

                            $deleted++;
                        } catch (Throwable $e) {
                            $this->error("  Media {$media->getKey()} [$conversion/$disk]: {$e->getMessage()}");
                            $failures++;
                        } finally {
                            try {
                                $this->release($media, $conversion, $generation, $token);
                            } catch (Throwable $e) {
                                $this->error("  Media {$media->getKey()} [$conversion/$disk]: failed to release claim: {$e->getMessage()}");
                                $failures++;
                            }
                        }
                    }
                }
            }
        });

        $this->statusLine('Candidates', 'info', (string) $candidates);
        $this->statusLine('Protected', 'info', (string) $protected);

        if ($force) {
            $this->statusLine('Deleted', $failures === 0 ? 'ok' : 'warn', (string) $deleted);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function parseOlderThan(int $default): int
    {
        $value = $this->option('older-than');

        if ($value === null) {
            return $default;
        }

        if (! preg_match('/^(0|[1-9][0-9]*)$/', (string) $value)) {
            throw new \InvalidArgumentException('Invalid --older-than value.');
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if (! is_int($parsed)) {
            throw new \InvalidArgumentException('Invalid --older-than value.');
        }

        return $parsed;
    }

    /** @return string[]|null */
    private function parseConversions(): ?array
    {
        if ($this->option('conversion') === null) {
            return null;
        }

        $names = array_values(array_unique(array_map('trim', explode(',', (string) $this->option('conversion')))));

        if (array_filter(
            $names,
            fn ($name) => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) !== 1,
        ) !== []) {
            throw new \InvalidArgumentException('Invalid --conversion value.');
        }

        return $names;
    }

    /** @param string[]|null $filter @param string[] $disks @return string[] */
    private function conversionNames(Media $media, ?array $filter, array $disks): array
    {
        if ($filter !== null) {
            return $filter;
        }

        $registered = array_filter(
            array_keys(app(ConversionRegistry::class)->all()),
            fn (string $name) => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) === 1,
        );
        $names = [...$registered, ...array_keys($media->conversionFiles())];
        $legacyRoot = $media->getDirectory().'/'.Media::CONVERSIONS_DIR;

        foreach ($disks as $disk) {
            try {
                foreach (Storage::disk($disk)->directories($legacyRoot) as $directory) {
                    $name = basename($directory);

                    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) === 1) {
                        $names[] = $name;
                    }
                }
            } catch (Throwable) {
                // The main scan reports disk failures with conversion context.
            }
        }

        return array_values(array_unique($names));
    }

    private function markerProtects(
        Filesystem $filesystem,
        string $directory,
        ConversionGenerationConfig $config,
    ): bool {
        $markers = array_filter(
            $filesystem->files($directory),
            fn (string $path) => str_starts_with(basename($path), ImageManipulator::IN_PROGRESS_MARKER),
        );

        foreach ($markers as $path) {
            try {
                $marker = json_decode($filesystem->get($path), true, flags: JSON_THROW_ON_ERROR);
                $startedAt = new DateTimeImmutable($marker['started_at'] ?? '');

                if ($startedAt > now()->subMinutes($config->generationTimeoutMinutes)->toDateTimeImmutable()) {
                    return true;
                }
            } catch (Throwable $e) {
                throw new \RuntimeException("Malformed conversion generation marker [$path].", previous: $e);
            }
        }

        return false;
    }

    private function claim(
        Media $media,
        string $conversion,
        string $generation,
        string $expectedBase,
        ConversionGenerationConfig $config,
    ): ?string {
        return DB::connection($media->getConnectionName())->transaction(function () use (
            $media,
            $conversion,
            $generation,
            $expectedBase,
            $config,
        ): ?string {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)) {
                return null;
            }

            $clearing = $fresh->getCustomProperty(Media::PROPERTY_CONVERSION_CLEARING, []);

            if (is_array($clearing) && isset($clearing[$conversion])) {
                return null;
            }

            $currentBase = ConversionPath::directory(
                app(MediaResolver::class)->pathForConversion($fresh, $conversion),
            );

            if ($currentBase !== $expectedBase) {
                return null;
            }

            $active = $fresh->getConversionFile($conversion);
            $rawFiles = $fresh->getCustomProperty(Media::PROPERTY_CONVERSION_FILES, []);

            if (
                (is_array($rawFiles) && array_key_exists($conversion, $rawFiles) && $active === null)
                || ($active['generation'] ?? null) === $generation
            ) {
                return null;
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $pruning = $properties[Media::PROPERTY_CONVERSION_PRUNING] ?? [];
            $pruning = is_array($pruning) ? $pruning : [];
            $conversionPruning = $pruning[$conversion] ?? [];
            $conversionPruning = is_array($conversionPruning) ? $conversionPruning : [];

            $existing = $conversionPruning[$generation] ?? null;

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
            $conversionPruning[$generation] = [
                'token' => $token,
                'started_at' => now()->toIso8601String(),
            ];
            $pruning[$conversion] = $conversionPruning;
            $properties[Media::PROPERTY_CONVERSION_PRUNING] = $pruning;
            $fresh->custom_properties = $properties;
            $fresh->save();

            return $token;
        });
    }

    private function release(Media $media, string $conversion, string $generation, string $token): void
    {
        DB::connection($media->getConnectionName())->transaction(function () use (
            $media,
            $conversion,
            $generation,
            $token,
        ): void {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $pruning = $properties[Media::PROPERTY_CONVERSION_PRUNING] ?? [];
            $pruning = is_array($pruning) ? $pruning : [];
            $conversionPruning = $pruning[$conversion] ?? [];
            $conversionPruning = is_array($conversionPruning) ? $conversionPruning : [];

            if (($conversionPruning[$generation]['token'] ?? null) === $token) {
                unset($conversionPruning[$generation]);
            }

            if ($conversionPruning === []) {
                unset($pruning[$conversion]);
            } else {
                $pruning[$conversion] = $conversionPruning;
            }

            if ($pruning === []) {
                unset($properties[Media::PROPERTY_CONVERSION_PRUNING]);
            } else {
                $properties[Media::PROPERTY_CONVERSION_PRUNING] = $pruning;
            }

            $fresh->custom_properties = $properties;
            $fresh->save();
        });
    }
}
