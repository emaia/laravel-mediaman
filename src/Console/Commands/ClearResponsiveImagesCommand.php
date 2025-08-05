<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;

class ClearResponsiveImagesCommand extends Command
{
    protected $signature = 'mediaman:clear-responsive 
                            {--collection= : Clear for specific collection}
                            {--media= : Clear for specific media ID}
                            {--confirm : Skip confirmation prompt}';

    protected $description = 'Clear responsive images for media items';

    public function handle()
    {
        $query = Media::query()->where('mime_type', 'like', 'image/%');

        // Filter by collection if specified
        if ($collection = $this->option('collection')) {
            $query->whereHas('collections', function ($q) use ($collection) {
                $q->where('name', $collection);
            });
        }

        // Filter by specific media ID if specified
        if ($mediaId = $this->option('media')) {
            $query->where('id', $mediaId);
        }

        // Only get items that have responsive images
        $query->whereJsonContains('custom_properties->responsive_images');

        $mediaItems = $query->get();

        if ($mediaItems->isEmpty()) {
            $this->info('No media items with responsive images found.');
            return 0;
        }

        if (!$this->option('confirm')) {
            if (!$this->confirm("This will clear responsive images for {$mediaItems->count()} media items. Continue?")) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->info("Clearing responsive images for {$mediaItems->count()} media items...");

        $progressBar = $this->output->createProgressBar($mediaItems->count());
        $progressBar->start();

        $generator = app(ResponsiveImageGenerator::class);

        foreach ($mediaItems as $media) {
            try {
                $generator->clearResponsiveImages($media);
                $this->line(" Cleared: {$media->name}");
            } catch (\Exception $e) {
                $this->error(" Failed: {$media->name} - {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->info('Responsive images clearing completed.');

        return 0;
    }
}