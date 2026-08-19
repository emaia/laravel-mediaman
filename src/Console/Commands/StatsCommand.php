<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Conversions\ConversionGenerationConfig;
use Emaia\MediaMan\Conversions\ConversionMetadataQuery;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationConfig;
use Emaia\MediaMan\ResponsiveImages\ResponsiveMetadataQuery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class StatsCommand extends Command
{
    use CommandOutputStyle;

    protected $signature = 'mediaman:stats
                            {--responsive : Show detailed responsive images stats}
                            {--conversions : Show detailed conversion stats}';

    protected $description = 'Show media, conversion, and responsive image statistics';

    public function handle(): int
    {
        try {
            ResponsiveGenerationConfig::fromConfig();
            ConversionGenerationConfig::fromConfig();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $showResponsive = (bool) $this->option('responsive');
        $showConversions = (bool) $this->option('conversions');

        $this->showMediaInventory();
        $this->showConversionsSummary($showConversions);
        $this->showResponsiveSummary($showResponsive);

        return self::SUCCESS;
    }

    protected function showMediaInventory(): void
    {
        $total = $this->mediaQuery()->count();
        $bytes = (int) $this->mediaQuery()->sum('size');
        $images = $this->mediaQuery()->where('mime_type', 'like', 'image/%')->count();

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
        $config = ConversionGenerationConfig::fromConfig();
        $this->statusLine('Versioning', 'info', $config->isVersioned() ? 'generation' : 'disabled (legacy paths)');

        if (! $detailed) {
            if ($count > 0) {
                $this->statusLine('Names', 'info', implode(', ', $names));
            }

            return;
        }

        if ($count === 0) {
            $this->statusLine('Result', 'info', 'no conversions registered');
        } else {
            foreach ($names as $name) {
                $format = $registry->getFormat($name) ?? 'auto-detect';
                $this->statusLine("  $name", 'info', "<fg=gray>$format</>");
            }
        }

        $this->statusLine(
            'Versioned media',
            'info',
            number_format(ConversionMetadataQuery::whereHasManifest($this->mediaQuery())->count()),
        );
        $this->statusLine('Generation retention', 'info', $config->retentionDays.' day(s)');
        $this->statusLine('In-progress timeout', 'info', $config->generationTimeoutMinutes.' minute(s)');
    }

    protected function showResponsiveSummary(bool $detailed): void
    {
        $totalImages = $this->mediaQuery()->where('mime_type', 'like', 'image/%')->count();
        [$withResponsive, $legacy, $versioned, $inconsistent] = $this->responsiveManifestCounts();

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
        $this->statusLine('Legacy manifests', 'info', number_format($legacy));
        $this->statusLine('Versioned manifests', 'info', number_format($versioned));

        if ($inconsistent > 0) {
            $this->statusLine('Inconsistent metadata', 'warn', number_format($inconsistent));
        }

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
        $this->statusLine('Quality', 'info', $this->formatQuality(config('mediaman.responsive_images.quality', 85)));
        $this->statusLine('Formats', 'info', implode(', ', config('mediaman.responsive_images.formats', ['webp'])));
        $this->statusLine('Breakpoints', 'info', implode(', ', config('mediaman.responsive_images.breakpoints', [])));
        $this->statusLine('Width calculator', 'info', config('mediaman.responsive_images.width_calculator', 'breakpoint'));
        $generationConfig = ResponsiveGenerationConfig::fromConfig();
        $this->statusLine('Versioning', 'info', $generationConfig->isVersioned() ? 'generation' : 'disabled (legacy paths)');
        $this->statusLine('Generation retention', 'info', $generationConfig->retentionDays.' day(s)');
        $this->statusLine('In-progress timeout', 'info', $generationConfig->generationTimeoutMinutes.' minute(s)');
    }

    /** Query the configured media model rather than assuming the package default. */
    protected function mediaQuery(): Builder
    {
        $modelClass = config('mediaman.models.media', Media::class);

        return (new $modelClass)->newQuery();
    }

    /** @return array{int, int, int, int} */
    protected function responsiveManifestCounts(): array
    {
        $generationPath = 'custom_properties->'.Media::PROPERTY_RESPONSIVE_GENERATION;
        $images = $this->mediaQuery()->where('mime_type', 'like', 'image/%');
        $manifests = ResponsiveMetadataQuery::whereHasManifest(clone $images);
        $withResponsive = (clone $manifests)->count();
        $legacy = (clone $manifests)->whereNull($generationPath)->count();
        $versioned = ResponsiveMetadataQuery::whereHasManagedGeneration(clone $manifests)->count();
        $withGeneration = (clone $images)->whereNotNull($generationPath)->count();
        $inconsistent = max(0, $withGeneration - $versioned);

        return [$withResponsive, $legacy, $versioned, $inconsistent];
    }

    /** Stringify a scalar or per-format quality config for the stats line. */
    protected function formatQuality(int|array $quality): string
    {
        if (is_int($quality)) {
            return (string) $quality;
        }

        return collect($quality)
            ->map(fn ($value, $format) => "$format=$value")
            ->implode(', ');
    }
}
