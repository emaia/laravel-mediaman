<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaKeys;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Conversions\ConversionClearer;
use Emaia\MediaMan\Traits\ResolvesModels;
use Illuminate\Console\Command;

class ClearConversionsCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaKeys;
    use ResolvesModels;

    protected $signature = 'mediaman:clear-conversions
                            {--conversion= : Required. Comma-separated conversion names (e.g. "thumb,cover")}
                            {--media= : Comma-separated IDs and/or ranges (e.g. "1,3,5..10")}
                            {--collection= : Filter by collection name}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Clear image conversion files for existing media';

    public function handle(): int
    {
        if (empty($this->option('conversion'))) {
            $this->error('The --conversion option is required.');

            return self::FAILURE;
        }

        $conversionNames = array_map('trim', explode(',', $this->option('conversion')));

        $unsafe = array_filter(
            $conversionNames,
            fn ($name) => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) !== 1,
        );

        if ($unsafe !== []) {
            $this->error('Invalid conversion name(s): '.implode(', ', $unsafe));

            return self::FAILURE;
        }

        $query = $this->mediaModel()::query();

        if ($this->option('media') !== null) {
            $ids = $this->parseMediaKeys((string) $this->option('media'));

            if (empty($ids)) {
                $this->error('Invalid --media value.');

                return self::FAILURE;
            }

            $query->whereKey($ids);
        }

        if ($collection = $this->option('collection')) {
            $query->whereHas('collections', function ($q) use ($collection) {
                $q->where('name', $collection);
            });
        }

        $mediaItems = $query->get();

        $registry = app(ConversionRegistry::class);
        $invalid = array_filter($conversionNames, function ($name) use ($registry, $mediaItems): bool {
            if ($registry->exists($name)) {
                return false;
            }

            return ! $mediaItems->contains(function ($media) use ($name): bool {
                $files = $media->getCustomProperty($media::PROPERTY_CONVERSION_FILES, []);
                $clearing = $media->getCustomProperty($media::PROPERTY_CONVERSION_CLEARING, []);

                return (is_array($files) && array_key_exists($name, $files))
                    || (is_array($clearing) && array_key_exists($name, $clearing));
            });
        });

        if ($invalid !== []) {
            $this->error('Unknown conversion(s): '.implode(', ', $invalid));

            return self::FAILURE;
        }

        if ($mediaItems->isEmpty()) {
            $this->info('No media items found to process.');

            return self::SUCCESS;
        }

        $total = $mediaItems->count();
        $convCount = count($conversionNames);

        if ($total * $convCount > 100 && ! $this->option('force') && ! $this->confirm("Will clear $convCount conversion(s) on $total media item(s). Continue?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $this->section('Clear conversions');

        $this->statusLine('Conversions', 'info', implode(', ', $conversionNames));
        $this->statusLine('Media items', 'info', (string) $total);
        $this->newLine();

        $cleared = 0;
        $skipped = 0;
        $failures = [];
        $clearer = app(ConversionClearer::class);

        foreach ($mediaItems as $media) {
            foreach ($conversionNames as $conv) {
                try {
                    if (! $clearer->clear($media, $conv)) {
                        $skipped++;

                        continue;
                    }

                    $cleared++;
                } catch (\Throwable $e) {
                    $failures[] = ['id' => $media->getKey(), 'name' => $media->name, 'error' => $e->getMessage()];
                }
            }
        }

        if ($cleared > 0) {
            $this->statusLine('Cleared', 'ok', (string) $cleared);
        }

        if ($skipped > 0) {
            $this->statusLine('Skipped (not found)', 'warn', (string) $skipped);
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

        if ($cleared === 0 && $skipped === 0 && empty($failures)) {
            $this->statusLine('Result', 'info', 'nothing to do');
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
