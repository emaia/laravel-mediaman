<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class CleanCommand extends Command
{
    protected $signature = 'mediaman:clean
                            {--force : Actually delete orphaned files on disk (DB records are never auto-deleted)}
                            {--disk= : Specific disk to scan}';

    protected $description = 'Detect orphaned files on disk and records with missing files.
Orphan detection uses top-level media directories — stale conversion
or responsive files within a valid media directory are not flagged.';

    public function handle(): int
    {
        $diskName = $this->option('disk')
            ?? config('mediaman.disk')
            ?? config('filesystems.default');
        $dryRun = ! $this->option('force');

        try {
            $filesystem = Storage::disk($diskName);
        } catch (InvalidArgumentException $e) {
            $this->error("Disk [$diskName] is not defined in the filesystems configuration.");

            return 1;
        }

        $mediaRecords = Media::where('disk', $diskName)->get();

        $knownDirs = [];

        foreach ($mediaRecords as $media) {
            $knownDirs[$media->getDirectory()] = true;
        }

        // 1. Find orphaned files (files on disk without a matching Media record)
        $allFiles = $filesystem->allFiles();
        $orphanedFiles = [];

        foreach ($allFiles as $file) {
            $parts = explode('/', $file);
            $topDir = $parts[0];

            if (! isset($knownDirs[$topDir])) {
                $orphanedFiles[] = $file;
            }
        }

        // 2. Find reverse orphans (Media records with missing primary file)
        $reverseOrphans = [];

        foreach ($mediaRecords as $media) {
            if (! $filesystem->exists($media->getPath())) {
                $reverseOrphans[] = $media;
            }
        }

        $this->info("Disk: $diskName");
        $this->info('Media records on this disk: '.$mediaRecords->count());
        $this->newLine();

        // Report orphaned files
        $orphanedCount = count($orphanedFiles);

        if ($orphanedCount === 0) {
            $this->info('No orphaned files found.');
        } else {
            $this->warn("Found $orphanedCount orphaned file(s):");

            foreach ($orphanedFiles as $file) {
                $this->line("  <fg=red>FILE</> $file");
            }

            if (! $dryRun) {
                $deleted = 0;

                foreach ($orphanedFiles as $file) {
                    $filesystem->delete($file);
                    $deleted++;
                }

                $this->info("Deleted $deleted orphaned file(s).");
            } else {
                $this->comment('Run with --force to delete orphaned files.');
            }
        }

        $this->newLine();

        // Report reverse orphans
        $reverseCount = count($reverseOrphans);

        if ($reverseCount === 0) {
            $this->info('No reverse orphans found (all records have their files on disk).');
        } else {
            $this->warn("Found $reverseCount record(s) with missing files:");

            foreach ($reverseOrphans as $media) {
                $this->line("  <fg=yellow>ID $media->id</> \"$media->name\" → {$media->getPath()}");
            }

            $this->comment('These records reference files that do not exist on disk.');
            $this->comment('Review manually and delete the records if the files are truly lost.');
        }

        return 0;
    }
}
