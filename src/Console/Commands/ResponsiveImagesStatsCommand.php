<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;

class ResponsiveImagesStatsCommand extends Command
{
    protected $signature = 'mediaman:responsive-stats';

    protected $description = 'Show statistics about responsive images';

    public function handle(): int
    {
        $totalImages = Media::where('mime_type', 'like', 'image/%')->count();
        $withResponsive = Media::where('mime_type', 'like', 'image/%')
            ->whereNotNull('custom_properties->responsive_images')
            ->count();

        $this->section('Responsive images');

        $this->statusLine('Total images', 'info', number_format($totalImages));
        $this->statusLine('With responsive', 'info', number_format($withResponsive));
        $this->statusLine('Without responsive', 'info', number_format($totalImages - $withResponsive));

        if ($totalImages > 0) {
            $percentage = (int) round(($withResponsive / $totalImages) * 100);
            $this->statusLine(
                'Coverage',
                'info',
                number_format($withResponsive).' / '.number_format($totalImages)." ({$percentage}%)"
            );
        }

        $this->section('Configuration');

        $this->statusLine('Enabled', 'info', config('mediaman.responsive_images.enabled', true) ? 'Yes' : 'No');
        $this->statusLine('Auto generate', 'info', config('mediaman.responsive_images.auto_generate', false) ? 'Yes' : 'No');
        $this->statusLine('Queue', 'info', config('mediaman.responsive_images.queue', true) ? 'Yes' : 'No');
        $this->statusLine('Quality', 'info', (string) config('mediaman.responsive_images.quality', 85));
        $this->statusLine('Formats', 'info', implode(', ', config('mediaman.responsive_images.formats', ['webp'])));
        $this->statusLine('Breakpoints', 'info', implode(', ', config('mediaman.responsive_images.breakpoints', [])));
        $this->statusLine('Width calculator', 'info', config('mediaman.responsive_images.width_calculator', 'breakpoint'));

        return self::SUCCESS;
    }

    protected function section(string $title): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('  <fg=green;options=bold>'.$title.'</>');
    }

    protected function statusLine(string $label, string $level, string $value): void
    {
        $icon = match ($level) {
            'ok' => '<fg=green>✓</>',
            'warn' => '<fg=yellow>⚠</>',
            'error' => '<fg=red>✗</>',
            default => '<fg=gray>·</>',
        };

        $this->components->twoColumnDetail($label, "{$icon} {$value}");
    }
}
