<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaKeys;
use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationConfig;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationResult;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Console\Command;

class GenerateResponsiveImagesCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaKeys;

    protected $signature = 'mediaman:generate-responsive
                            {--collection= : Generate for specific collection}
                            {--media= : Comma-separated IDs and/or ranges (e.g. "1,3,5..10")}
                            {--force : Force regeneration even if responsive images exist}
                            {--queue : Dispatch as queued jobs}';

    protected $description = 'Generate responsive images for existing media';

    public function handle(): int
    {
        try {
            ResponsiveGenerationConfig::fromConfig();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $modelClass = config('mediaman.models.media', Media::class);
        /** @var Media $model */
        $model = new $modelClass;
        $query = $model->newQuery()->raster();

        if (($mediaOption = $this->option('media')) !== null) {
            $ids = $this->parseMediaKeys((string) $mediaOption);

            if (empty($ids)) {
                $this->error('Invalid --media value.');

                return self::FAILURE;
            }

            $query->whereKey($ids);
        }

        if (($collection = $this->option('collection')) !== null) {
            if ($collection === '') {
                $this->error('Invalid --collection value.');

                return self::FAILURE;
            }

            $query->whereHas('collections', function ($q) use ($collection) {
                $q->where('name', $collection);
            });
        }

        if (! $this->option('force')) {
            $query->whereNull('custom_properties->responsive_images');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No media items found to process.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $this->section('Generate responsive');

            $this->statusLine('Media items', 'info', (string) $total);
            $this->statusLine('Mode', 'info', 'queue');
            $this->newLine();

            foreach ($query->lazy(100) as $media) {
                GenerateResponsiveImages::dispatch($media);
            }

            $this->statusLine('Dispatched', 'ok', "$total (queued)");

            return self::SUCCESS;
        }

        $this->section('Generate responsive');

        $this->statusLine('Media items', 'info', (string) $total);
        $this->statusLine('Mode', 'info', 'inline');
        $this->newLine();

        $processed = 0;
        $skipped = 0;
        $failures = [];
        $generator = app(ResponsiveImageGenerator::class);

        foreach ($query->lazy(100) as $media) {
            try {
                $result = $generator->generateResponsiveImages($media);
                $result instanceof ResponsiveGenerationResult && ! $result->wasPublished()
                    ? $skipped++
                    : $processed++;
            } catch (\Throwable $e) {
                $failures[] = ['id' => $media->getKey(), 'name' => $media->name, 'error' => $e->getMessage()];
            }
        }

        if ($processed > 0) {
            $this->statusLine('Processed', 'ok', (string) $processed);
        }

        if ($skipped > 0) {
            $this->statusLine('Skipped', 'info', (string) $skipped);
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

        if ($processed === 0 && $skipped === 0 && empty($failures)) {
            $this->statusLine('Result', 'info', 'nothing to do');
        }

        return empty($failures) ? self::SUCCESS : self::FAILURE;
    }
}
