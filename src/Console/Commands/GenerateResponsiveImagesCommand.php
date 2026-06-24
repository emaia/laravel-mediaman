<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaIds;
use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Console\Command;

class GenerateResponsiveImagesCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaIds;

    protected $signature = 'mediaman:generate-responsive
                            {--collection= : Generate for specific collection}
                            {--media= : Comma-separated IDs and/or ranges (e.g. "1,3,5..10")}
                            {--force : Force regeneration even if responsive images exist}
                            {--queue : Dispatch as queued jobs}';

    protected $description = 'Generate responsive images for existing media';

    public function handle(): int
    {
        $query = Media::query()->where('mime_type', 'like', 'image/%');

        if ($mediaOption = $this->option('media')) {
            $ids = $this->parseMediaIds($mediaOption);

            if (empty($ids)) {
                $this->error('Invalid --media value.');

                return self::FAILURE;
            }

            $query->whereIn('id', $ids);
        }

        if ($collection = $this->option('collection')) {
            $query->whereHas('collections', function ($q) use ($collection) {
                $q->where('name', $collection);
            });
        }

        if (! $this->option('force')) {
            $query->whereNull('custom_properties->responsive_images');
        }

        $mediaItems = $query->get();

        if ($mediaItems->isEmpty()) {
            $this->info('No media items found to process.');

            return self::SUCCESS;
        }

        $total = $mediaItems->count();

        if ($this->option('queue')) {
            $this->section('Generate responsive');

            $this->statusLine('Media items', 'info', (string) $total);
            $this->statusLine('Mode', 'info', 'queue');
            $this->newLine();

            foreach ($mediaItems as $media) {
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
        $failures = [];
        $generator = app(ResponsiveImageGenerator::class);

        foreach ($mediaItems as $media) {
            try {
                $generator->generateResponsiveImages($media);
                $processed++;
            } catch (\Exception $e) {
                $failures[] = ['id' => $media->getKey(), 'name' => $media->name, 'error' => $e->getMessage()];
            }
        }

        if ($processed > 0) {
            $this->statusLine('Processed', 'ok', (string) $processed);
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

        if ($processed === 0 && empty($failures)) {
            $this->statusLine('Result', 'info', 'nothing to do');
        }

        return self::SUCCESS;
    }
}
