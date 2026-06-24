<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;

class StatsCommand extends Command
{
    use CommandOutputStyle;

    protected $signature = 'mediaman:stats
                            {--responsive : Show detailed responsive images stats}
                            {--conversions : Show detailed conversion stats}';

    protected $description = 'Show media, conversion, and responsive image statistics';

    public function handle(): int
    {
        $showResponsive = (bool) $this->option('responsive');
        $showConversions = (bool) $this->option('conversions');

        $this->showMediaInventory();
        $this->showConversionsSummary($showConversions);
        $this->showResponsiveSummary($showResponsive);

        return self::SUCCESS;
    }

    protected function showMediaInventory(): void
    {
        $total = Media::query()->count();
        $bytes = (int) Media::query()->sum('size');
        $images = Media::query()->where('mime_type', 'like', 'image/%')->count();

        $this->section('Media inventory');

        $this->statusLine('Records', 'info', number_format($total));
        $this->statusLine('Total size', 'info', $this->formatBytes($bytes));
        $this->statusLine('Image records', 'info', number_format($images));
    }

    protected function showConversionsSummary(bool $detailed): void
    {
        $registry = app(ConversionRegistry::class);
        $names = array_keys($registry->all());
        $count = count($names);

        $this->section('Conversions');

        $this->statusLine('Registered', 'info', (string) $count);

        if (! $detailed) {
            if ($count > 0) {
                $this->statusLine('Names', 'info', implode(', ', $names));
            }

            return;
        }

        if ($count === 0) {
            $this->statusLine('Result', 'info', 'no conversions registered');

            return;
        }

        foreach ($names as $name) {
            $format = $registry->getFormat($name) ?? 'auto-detect';
            $this->statusLine("  $name", 'info', "<fg=gray>$format</>");
        }
    }

    protected function showResponsiveSummary(bool $detailed): void
    {
        $totalImages = Media::where('mime_type', 'like', 'image/%')->count();
        $withResponsive = Media::where('mime_type', 'like', 'image/%')
            ->whereNotNull('custom_properties->responsive_images')
            ->count();

        $this->section('Responsive images');

        if (! $detailed) {
            $this->statusLine('Enabled', 'info', config('mediaman.responsive_images.enabled', true) ? 'Yes' : 'No');
            $this->statusLine('Auto generate', 'info', config('mediaman.responsive_images.auto_generate', false) ? 'Yes' : 'No');

            if ($totalImages > 0) {
                $percentage = (int) round(($withResponsive / $totalImages) * 100);
                $this->statusLine(
                    'Coverage',
                    'info',
                    number_format($withResponsive).' / '.number_format($totalImages)." ($percentage%)"
                );
            } else {
                $this->statusLine('Coverage', 'info', 'no image records');
            }

            return;
        }

        $this->statusLine('Total images', 'info', number_format($totalImages));
        $this->statusLine('With responsive', 'info', number_format($withResponsive));
        $this->statusLine('Without responsive', 'info', number_format($totalImages - $withResponsive));

        if ($totalImages > 0) {
            $percentage = (int) round(($withResponsive / $totalImages) * 100);
            $this->statusLine(
                'Coverage',
                'info',
                number_format($withResponsive).' / '.number_format($totalImages)." ($percentage%)"
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
    }
}
