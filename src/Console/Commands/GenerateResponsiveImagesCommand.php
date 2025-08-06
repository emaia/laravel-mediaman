<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Console\Command;

class GenerateResponsiveImagesCommand extends Command
{
    protected $signature = 'mediaman:generate-responsive 
                            {--collection= : Generate for specific collection}
                            {--media= : Generate for specific media ID}
                            {--force : Force regeneration even if responsive images exist}
                            {--queue : Queue the generation jobs}';

    protected $description = 'Generate responsive images for existing media';

    public function handle()
    {
        $query = Media::query()->where('mime_type', 'like', 'image/%');

        // Filter by collection if specified
        if ($collection = $this->option('collection')) {
            $query->whereHas('collections', function($q) use ($collection) {
                $q->where('name', $collection);
            });
        }

        // Filter by specific media ID if specified
        if ($mediaId = $this->option('media')) {
            $query->where('id', $mediaId);
        }

        // Filter out items that already have responsive images unless forced
        if (!$this->option('force')) {
            $query->whereJsonDoesntContain('custom_properties->responsive_images', []);
        }

        $mediaItems = $query->get();

        if ($mediaItems->isEmpty()) {
            $this->info('No media items found to process.');
            return 0;
        }

        $this->info("Processing {$mediaItems->count()} media items...");

        $progressBar = $this->output->createProgressBar($mediaItems->count());
        $progressBar->start();

        $generator = app(ResponsiveImageGenerator::class);
        $useQueue = $this->option('queue') || config('mediaman.responsive_images.queue', true);

        foreach ($mediaItems as $media) {
            try {
                if ($useQueue) {
                    GenerateResponsiveImages::dispatch($media);
                    $this->line(" Queued: {$media->name}");
                } else {
                    $generator->generateResponsiveImages($media);
                    $this->line(" Processed: {$media->name}");
                }
            } catch (\Exception $e) {
                $this->error(" Failed: {$media->name} - {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($useQueue) {
            $this->info('Responsive image generation jobs have been queued.');
        } else {
            $this->info('Responsive images generation completed.');
        }

        return 0;
    }
}

