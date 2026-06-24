<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class CleanCommand extends Command
{
    protected $signature = 'mediaman:clean
                            {--force : Actually delete orphaned files on disk (DB records are never auto-deleted)}
                            {--disk= : Specific disk to scan (when omitted, all used disks are scanned)}';

    protected $description = 'Detect orphaned files on disk and records with missing files.
Orphan detection uses top-level media directories — stale conversion
or responsive files within a valid media directory are not flagged.';

    public function handle(): int
    {
        $dryRun = ! $this->option('force');

        $disksToScan = $this->resolveDisksToScan();

        if (empty($disksToScan)) {
            $this->info('No disks to scan.');

            return 0;
        }

        $this->info($dryRun ? 'Dry run — no files will be deleted. Run with --force to apply.' : 'Force mode — orphaned files will be deleted.');
        $this->newLine();

        $resolver = app(MediaResolver::class);

        // Build known directories from every Media record, regardless of disk.
        // A conversion-only disk carries files for media whose primary disk is
        // elsewhere — those directories are valid and must not be flagged.
        $allMedia = Media::all();
        $allKnownDirs = [];

        foreach ($allMedia as $media) {
            $allKnownDirs[$media->getDirectory()] = true;
        }

        $totalOrphaned = 0;
        $totalReverse = 0;
        $totalDeleted = 0;
        $exitCode = 0;

        foreach ($disksToScan as $diskName) {
            $this->info("Disk: $diskName");

            try {
                $filesystem = Storage::disk($diskName);
            } catch (InvalidArgumentException $e) {
                $this->error("  Disk [$diskName] is not defined in the filesystems configuration.");
                $exitCode = 1;

                continue;
            }

            // 1. Find orphaned files — directories the resolver claims it could
            //    have produced but that match no known Media record. Foreign
            //    files (.gitignore, other apps) are skipped entirely.
            $allFiles = $filesystem->allFiles();
            $orphanedFiles = [];

            foreach ($allFiles as $file) {
                $parts = explode('/', $file);
                $topDir = $parts[0];

                if (! $resolver->isManagedDirectory($topDir)) {
                    continue;
                }

                if (! isset($allKnownDirs[$topDir])) {
                    $orphanedFiles[] = $file;
                }
            }

            // 2. Find reverse orphans — only meaningful for media whose
            //    primary file should live on this disk. Conversion files on
            //    other disks are never checked for presence here.
            $mediaOnThisDisk = $allMedia->where('disk', $diskName);
            $reverseOrphans = [];

            foreach ($mediaOnThisDisk as $media) {
                if (! $filesystem->exists($media->getPath())) {
                    $reverseOrphans[] = $media;
                }
            }

            $this->info('  Media records whose primary disk is here: '.$mediaOnThisDisk->count());

            // Report orphaned files
            $orphanedCount = count($orphanedFiles);
            $totalOrphaned += $orphanedCount;

            if ($orphanedCount === 0) {
                $this->info('  No orphaned files found.');
            } else {
                $this->warn("  Found $orphanedCount orphaned file(s):");

                foreach ($orphanedFiles as $file) {
                    $this->line("    <fg=red>FILE</> $file");
                }

                if (! $dryRun) {
                    $deleted = 0;

                    foreach ($orphanedFiles as $file) {
                        $filesystem->delete($file);
                        $deleted++;
                    }

                    $totalDeleted += $deleted;
                    $this->info("  Deleted $deleted orphaned file(s).");
                } else {
                    $this->comment('  Run with --force to delete orphaned files.');
                }
            }

            // Report reverse orphans
            $reverseCount = count($reverseOrphans);
            $totalReverse += $reverseCount;

            if ($reverseCount === 0) {
                $this->info('  No reverse orphans found.');
            } else {
                $this->warn("  Found $reverseCount record(s) with missing files:");

                foreach ($reverseOrphans as $media) {
                    $this->line("    <fg=yellow>ID $media->id</> \"$media->name\" → {$media->getPath()}");
                }

                $this->comment('  These records reference files that do not exist on disk.');
                $this->comment('  Review manually and delete the records if the files are truly lost.');
            }

            $this->newLine();
        }

        // Summary
        $this->info(sprintf(
            'Summary: %d orphaned file(s) across %d disk(s), %d reverse orphan(s).',
            $totalOrphaned,
            count($disksToScan),
            $totalReverse
        ));

        if (! $dryRun && $totalDeleted > 0) {
            $this->info("Deleted $totalDeleted file(s) total.");
        }

        return $exitCode;
    }

    /**
     * Resolve which disks to scan: the `--disk` override when set, or the union
     * of every disk referenced by a Media record, every disk explicitly registered
     * for a conversion, and the config-level defaults for conversions and
     * responsive variants. Returns an empty list when no media exists yet.
     *
     * @return string[]
     */
    protected function resolveDisksToScan(): array
    {
        if ($disk = $this->option('disk')) {
            return [$disk];
        }

        $disks = Media::query()->distinct()->pluck('disk')->all();

        if (empty($disks)) {
            return [];
        }

        $extra = array_filter([
            ...app(ConversionRegistry::class)->disks(),
            config('mediaman.conversions.disk'),
            config('mediaman.responsive_images.disk'),
        ]);

        return array_values(array_unique(array_merge($disks, $extra)));
    }
}
