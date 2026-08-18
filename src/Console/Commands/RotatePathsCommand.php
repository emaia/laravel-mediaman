<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationConfig;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\Ulid;

class RotatePathsCommand extends Command
{
    protected $signature = 'mediaman:rotate-paths
                            {--old-key= : The previous APP_KEY (the value config(\'app.key\') returned before rotation)}
                            {--disk= : Limit to a specific disk}
                            {--media= : Limit to a single Media id}
                            {--force : Actually move files (default is dry-run)}';

    protected $description = 'Rename media directories on disk after rotating APP_KEY';

    public function handle(): int
    {
        $oldKey = $this->option('old-key');

        if (empty($oldKey)) {
            $this->error('--old-key is required. Pass the previous value of APP_KEY (the full string, including the "base64:" prefix when present).');

            return self::FAILURE;
        }

        $currentKey = config('app.key');

        if ($oldKey === $currentKey) {
            $this->warn('--old-key matches the current app.key. Nothing to rotate.');

            return self::SUCCESS;
        }

        try {
            if (ResponsiveGenerationConfig::fromConfig()->isVersioned()) {
                $this->error(
                    'Path rotation is blocked while responsive generation versioning is enabled. '
                    .'Disable versioning, clear retained responsive generations, then retry.'
                );

                return self::FAILURE;
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = ! $this->option('force');

        if ($dryRun) {
            $this->info('Dry run — no files will be moved. Re-run with --force to apply.');
        }

        $modelClass = config('mediaman.models.media', Media::class);
        /** @var Media $model */
        $model = new $modelClass;
        $query = $model->newQuery();

        if (($id = $this->option('media')) !== null) {
            if ($id === '') {
                $this->error('Invalid --media value.');

                return self::FAILURE;
            }

            $query->whereKey($id);
        }

        if (($disk = $this->option('disk')) !== null) {
            if ($disk === '') {
                $this->error('Invalid --disk value.');

                return self::FAILURE;
            }

            $query->where('disk', $disk);
        }

        $renamed = 0;
        $skippedAlreadyMigrated = 0;
        $skippedMissing = 0;
        $skippedConflict = 0;
        $blockedVersioned = 0;
        $moveFailures = 0;

        $query->lazy(100)->each(function (Media $media) use (
            $oldKey, $currentKey, $dryRun,
            &$renamed, &$skippedAlreadyMigrated, &$skippedMissing, &$skippedConflict, &$blockedVersioned, &$moveFailures
        ) {
            $rotationToken = $this->claimRotation($media, invalidateGeneration: ! $dryRun);

            if ($rotationToken === null) {
                $this->error(
                    "  Media {$media->getKey()}: responsive generation lifecycle state blocks path rotation. "
                    .'Restore revalidating cache headers, clear responsive images, rotate paths, then regenerate.'
                );
                $blockedVersioned++;

                return;
            }

            try {
                $oldDir = $media->getKey().'-'.md5($media->getKey().$oldKey);
                $newDir = $media->getKey().'-'.md5($media->getKey().$currentKey);

                if ($oldDir === $newDir) {
                    return;
                }

                $disks = $this->resolveMediaDisks($media);

                foreach ($disks as $diskName) {
                    try {
                        $markers = array_filter(
                            Storage::disk($diskName)->allFiles($oldDir.'/'.Media::RESPONSIVE_DIR),
                            fn (string $path) => str_starts_with(
                                basename($path),
                                ResponsiveImageGenerator::IN_PROGRESS_MARKER,
                            ),
                        );
                    } catch (\Throwable $e) {
                        $this->error("  Media {$media->getKey()}: cannot verify responsive markers on disk [$diskName]: {$e->getMessage()}");
                        $blockedVersioned++;

                        return;
                    }

                    if ($markers !== []) {
                        $this->error("  Media {$media->getKey()}: responsive generation or copy is in progress; path rotation blocked.");
                        $blockedVersioned++;

                        return;
                    }
                }

                foreach ($disks as $diskName) {
                    try {
                        $filesystem = Storage::disk($diskName);
                    } catch (\InvalidArgumentException $e) {
                        $this->warn("  Media {$media->getKey()}: disk [$diskName] not configured, skipping.");

                        continue;
                    }

                    $oldExists = $filesystem->exists($oldDir);
                    $newExists = $filesystem->exists($newDir);

                    if (! $oldExists) {
                        if ($newExists) {
                            $this->line("  <fg=blue>Media {$media->getKey()}</> disk [$diskName]: already at $newDir, skipping.");
                            $skippedAlreadyMigrated++;
                        } else {
                            $this->warn("  Media {$media->getKey()}: neither $oldDir nor $newDir exists on disk [$diskName].");
                            $skippedMissing++;
                        }

                        continue;
                    }

                    if ($newExists) {
                        $this->warn("  Media {$media->getKey()}: both $oldDir and $newDir exist on disk [$diskName]. Manual review required.");
                        $skippedConflict++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  <fg=yellow>Media {$media->getKey()}</> disk [$diskName]: would move $oldDir → $newDir");
                        $renamed++;

                        continue;
                    }

                    $files = $filesystem->allFiles($oldDir);

                    foreach ($files as $file) {
                        $relative = substr($file, strlen($oldDir) + 1);

                        if (! $filesystem->move($file, $newDir.'/'.$relative)) {
                            $this->error("  Media {$media->getKey()}: failed to move [$file] on disk [$diskName].");
                            $moveFailures++;

                            continue 2;
                        }
                    }

                    if (! $filesystem->deleteDirectory($oldDir)) {
                        $this->error("  Media {$media->getKey()}: failed to remove [$oldDir] on disk [$diskName].");
                        $moveFailures++;

                        continue;
                    }

                    $fileCount = count($files);
                    $this->line("  <fg=green>Media {$media->getKey()}</> disk [$diskName]: moved $oldDir → $newDir ($fileCount file(s))");
                    $renamed++;
                }
            } finally {
                $this->releaseRotation($media, $rotationToken);
            }
        });

        $this->newLine();
        $this->info(($dryRun ? 'Would rename' : 'Renamed').": $renamed");

        if ($skippedAlreadyMigrated > 0) {
            $this->line("Already migrated: $skippedAlreadyMigrated");
        }

        if ($skippedMissing > 0) {
            $this->line("Missing on disk:  $skippedMissing");
        }

        if ($skippedConflict > 0) {
            $this->warn("Conflicts (both old + new exist): $skippedConflict");
        }

        if ($blockedVersioned > 0) {
            $this->error("Blocked by active responsive generations: $blockedVersioned");
        }

        if ($moveFailures > 0) {
            $this->error("Move failures: $moveFailures");
        }

        if ($dryRun && $renamed > 0) {
            $this->newLine();
            $this->comment('Re-run with --force to apply the moves.');
        }

        return $blockedVersioned > 0 || $moveFailures > 0 || $skippedConflict > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function claimRotation(Media $media, bool $invalidateGeneration): ?string
    {
        return DB::connection($media->getConnectionName())->transaction(function () use ($media, $invalidateGeneration): ?string {
            /** @var Media|null $fresh */
            $fresh = $media->newQueryWithoutScopes()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return null;
            }

            if (
                $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION)
                || $fresh->responsiveGenerationDisks() !== []
                || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_CLEARING)
                || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_PRUNING)
                || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_DELETING)
            ) {
                return null;
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $existing = $properties[Media::PROPERTY_RESPONSIVE_ROTATING] ?? null;

            if (is_array($existing) && isset($existing['started_at'])) {
                try {
                    $startedAt = new \DateTimeImmutable($existing['started_at']);
                    $timeout = ResponsiveGenerationConfig::fromConfig()->generationTimeoutMinutes;

                    if ($startedAt > now()->subMinutes($timeout)->toDateTimeImmutable()) {
                        return null;
                    }
                } catch (\Throwable) {
                    return null;
                }
            }

            $token = strtoupper((string) new Ulid);
            $properties[Media::PROPERTY_RESPONSIVE_ROTATING] = [
                'token' => $token,
                'started_at' => now()->toIso8601String(),
            ];

            if ($invalidateGeneration) {
                $properties[Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH] =
                    ((int) ($properties[Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH] ?? 0)) + 1;
            }

            $fresh->custom_properties = $properties;
            $fresh->save();

            return $token;
        });
    }

    private function releaseRotation(Media $media, string $token): void
    {
        DB::connection($media->getConnectionName())->transaction(function () use ($media, $token): void {
            /** @var Media|null $fresh */
            $fresh = $media->newQueryWithoutScopes()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $state = $properties[Media::PROPERTY_RESPONSIVE_ROTATING] ?? null;

            if (! is_array($state) || ($state['token'] ?? null) !== $token) {
                return;
            }

            unset($properties[Media::PROPERTY_RESPONSIVE_ROTATING]);
            $fresh->custom_properties = $properties;
            $fresh->save();
        });
    }

    /**
     * Resolve every disk this media has files on: the primary disk, every
     * disk a conversion resolves to (explicit register or config default),
     * and the responsive variant disk.
     *
     * @return string[]
     */
    protected function resolveMediaDisks(Media $media): array
    {
        return array_values(array_unique([
            $media->disk,
            ...$media->getConversionDisks(),
            $media->responsiveDisk(),
        ]));
    }
}
