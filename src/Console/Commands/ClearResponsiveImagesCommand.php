<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaKeys;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Console\Command;

class ClearResponsiveImagesCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaKeys;

    protected $signature = 'mediaman:clear-responsive
                            {--collection= : Clear for specific collection}
                            {--media= : Comma-separated IDs and/or ranges (e.g. "1,3,5..10")}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Clear responsive images for media items';

    public function handle(): int
    {
        $modelClass = config('mediaman.models.media', Media::class);
        /** @var Media $model */
        $model = new $modelClass;
        $query = $model->newQuery();

        if ($collection = $this->option('collection')) {
            $query->whereHas('collections', function ($q) use ($collection) {
                $q->where('name', $collection);
            });
        }

        if ($mediaOption = $this->option('media')) {
            $ids = $this->parseMediaKeys((string) $mediaOption);

            if (empty($ids)) {
                $this->error('Invalid --media value.');

                return self::FAILURE;
            }

            $query->whereKey($ids);
        }

        $query->where(function ($query) {
            $query->whereNotNull('custom_properties->responsive_images')
                ->orWhereNotNull('custom_properties->responsive_clearing');
        });

        $mediaItems = $query->get();

        if ($mediaItems->isEmpty()) {
            $this->info('No media items with responsive images found.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("This will clear responsive images for {$mediaItems->count()} media items. Continue?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        $this->section('Clear responsive');

        $total = $mediaItems->count();
        $this->statusLine('Media items', 'info', (string) $total);
        $this->newLine();

        $cleared = 0;
        $failures = [];
        $generator = app(ResponsiveImageGenerator::class);

        foreach ($mediaItems as $media) {
            try {
                $generator->clearResponsiveImages($media);
                $cleared++;
            } catch (\Throwable $e) {
                $failures[] = ['id' => $media->getKey(), 'name' => $media->name, 'error' => $e->getMessage()];
            }
        }

        if ($cleared > 0) {
            $this->statusLine('Cleared', 'ok', (string) $cleared);
        }

        if (! empty($failures)) {
            $this->statusLine('Failed', 'error', (string) count($failures));

            foreach ($failures as $f) {
                $this->components->twoColumnDetail(
                    "  #{$f['id']} {$f['name']}",
                    "<fg=red>✗</> {$f['error']}"
                );
            }
        }

        if ($cleared === 0 && empty($failures)) {
            $this->statusLine('Result', 'info', 'nothing to do');
        }

        return empty($failures) ? self::SUCCESS : self::FAILURE;
    }
}
