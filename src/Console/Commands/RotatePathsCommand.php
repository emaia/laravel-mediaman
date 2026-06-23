<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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

        $dryRun = ! $this->option('force');

        if ($dryRun) {
            $this->info('Dry run — no files will be moved. Re-run with --force to apply.');
        }

        $query = Media::query();

        if ($id = $this->option('media')) {
            $query->whereKey($id);
        }

        if ($disk = $this->option('disk')) {
            $query->where('disk', $disk);
        }

        $renamed = 0;
        $skippedAlreadyMigrated = 0;
        $skippedMissing = 0;
        $skippedConflict = 0;

        $query->cursor()->each(function (Media $media) use (
            $oldKey, $currentKey, $dryRun,
            &$renamed, &$skippedAlreadyMigrated, &$skippedMissing, &$skippedConflict
        ) {
            $oldDir = $media->getKey().'-'.md5($media->getKey().$oldKey);
            $newDir = $media->getKey().'-'.md5($media->getKey().$currentKey);

            if ($oldDir === $newDir) {
                return;
            }

            $disks = $this->resolveMediaDisks($media);

            foreach ($disks as $diskName) {
                try {
                    $filesystem = Storage::disk($diskName);
                } catch (\InvalidArgumentException $e) {
                    $this->warn("  Media {$media->getKey()}: disk [{$diskName}] not configured, skipping.");

                    continue;
                }

                $oldExists = $filesystem->exists($oldDir);
                $newExists = $filesystem->exists($newDir);

                if (! $oldExists) {
                    if ($newExists) {
                        $this->line("  <fg=blue>Media {$media->getKey()}</> disk [$diskName]: already at {$newDir}, skipping.");
                        $skippedAlreadyMigrated++;
                    } else {
                        $this->warn("  Media {$media->getKey()}: neither {$oldDir} nor {$newDir} exists on disk [{$diskName}].");
                        $skippedMissing++;
                    }

                    continue;
                }

                if ($newExists) {
                    $this->warn("  Media {$media->getKey()}: both {$oldDir} and {$newDir} exist on disk [{$diskName}]. Manual review required.");
                    $skippedConflict++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  <fg=yellow>Media {$media->getKey()}</> disk [$diskName]: would move {$oldDir} → {$newDir}");
                    $renamed++;

                    continue;
                }

                $files = $filesystem->allFiles($oldDir);

                foreach ($files as $file) {
                    $relative = substr($file, strlen($oldDir) + 1);
                    $filesystem->move($file, $newDir.'/'.$relative);
                }

                $filesystem->deleteDirectory($oldDir);

                $fileCount = count($files);
                $this->line("  <fg=green>Media {$media->getKey()}</> disk [$diskName]: moved {$oldDir} → {$newDir} ({$fileCount} file(s))");
                $renamed++;
            }
        });

        $this->newLine();
        $this->info(($dryRun ? 'Would rename' : 'Renamed').": {$renamed}");

        if ($skippedAlreadyMigrated > 0) {
            $this->line("Already migrated: {$skippedAlreadyMigrated}");
        }

        if ($skippedMissing > 0) {
            $this->line("Missing on disk:  {$skippedMissing}");
        }

        if ($skippedConflict > 0) {
            $this->warn("Conflicts (both old + new exist): {$skippedConflict}");
        }

        if ($dryRun && $renamed > 0) {
            $this->newLine();
            $this->comment('Re-run with --force to apply the moves.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve all disk names where this media has files (main disk + any
     * conversion disks that differ).
     *
     * @return string[]
     */
    protected function resolveMediaDisks(Media $media): array
    {
        $disks = [$media->disk];

        foreach (app(ConversionRegistry::class)->disks() as $conversionDisk) {
            if ($conversionDisk !== $media->disk) {
                $disks[] = $conversionDisk;
            }
        }

        return array_unique($disks);
    }
}
