<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;

class ResponsiveImagesStatsCommand extends Command
{
    protected $signature = 'mediaman:responsive-stats';

    protected $description = 'Show statistics about responsive images';

    public function handle()
    {
        $totalImages = Media::where('mime_type', 'like', 'image/%')->count();
        $withResponsive = Media::where('mime_type', 'like', 'image/%')
            ->whereJsonContains('custom_properties->responsive_images')
            ->count();

        $this->info('Responsive Images Statistics');
        $this->line('================================');
        $this->line("Total image files: {$totalImages}");
        $this->line("With responsive images: {$withResponsive}");
        $this->line('Without responsive images: '.($totalImages - $withResponsive));

        if ($totalImages > 0) {
            $percentage = round(($withResponsive / $totalImages) * 100, 2);
            $this->line("Coverage: {$percentage}%");
        }

        // Show configuration
        $this->newLine();
        $this->info('Current Configuration');
        $this->line('=====================');
        $this->line('Enabled: '.(config('mediaman.responsive_images.enabled') ? 'Yes' : 'No'));
        $this->line('Auto generate: '.(config('mediaman.responsive_images.auto_generate') ? 'Yes' : 'No'));
        $this->line('Queue: '.(config('mediaman.responsive_images.queue') ? 'Yes' : 'No'));
        $this->line('Quality: '.config('mediaman.responsive_images.quality', 85));
        $this->line('Formats: '.implode(', ', config('mediaman.responsive_images.formats', ['webp', 'jpg'])));
        $this->line('Breakpoints: '.implode(', ', config('mediaman.responsive_images.breakpoints', [])));

        return 0;
    }
}
