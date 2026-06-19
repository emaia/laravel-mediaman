<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;

class GenerateConversionsCommand extends Command
{
    protected $signature = 'mediaman:generate-conversions
                            {--conversion= : Required. Comma-separated conversion names (e.g. "thumb,cover")}
                            {--media= : Comma-separated IDs and/or ranges (e.g. "1,3,5..10")}
                            {--collection= : Filter by collection name}
                            {--force : Overwrite existing conversion files (default: skip if exists)}
                            {--queue : Dispatch as queued jobs}';

    protected $description = 'Generate image conversions for existing media';

    public function handle(): int
    {
        if (empty($this->option('conversion'))) {
            $this->error('The --conversion option is required.');

            return self::FAILURE;
        }

        $conversionNames = array_map('trim', explode(',', $this->option('conversion')));

        $registry = app(ConversionRegistry::class);
        $invalid = array_filter($conversionNames, fn ($name) => ! $registry->exists($name));

        if (! empty($invalid)) {
            $this->error('Unknown conversion(s): '.implode(', ', $invalid));

            return self::FAILURE;
        }

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

        $mediaItems = $query->get();

        if ($mediaItems->isEmpty()) {
            $this->info('No media items found to process.');

            return self::SUCCESS;
        }

        $total = $mediaItems->count();
        $convCount = count($conversionNames);

        if ($total * $convCount > 100 && ! $this->confirm("Will process {$total} media item(s) × {$convCount} conversion(s) = ".($total * $convCount).' operations. Continue?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $this->section('Generate conversions');

        $this->statusLine('Conversions', 'info', implode(', ', $conversionNames));
        $this->statusLine('Media items', 'info', (string) $total);
        $this->newLine();

        if ($this->option('queue')) {
            $this->processQueued($mediaItems, $conversionNames);

            return self::SUCCESS;
        }

        $this->processInline($mediaItems, $conversionNames, (bool) $this->option('force'));

        return self::SUCCESS;
    }

    protected function processQueued($mediaItems, array $conversionNames): void
    {
        $count = $mediaItems->count();

        foreach ($mediaItems as $media) {
            PerformConversions::dispatch($media, $conversionNames);
        }

        $this->statusLine('Dispatched', 'ok', "{$count} (queued)");
    }

    protected function processInline($mediaItems, array $conversionNames, bool $force): void
    {
        $manipulator = app(ImageManipulator::class);
        $processed = 0;
        $skipped = 0;
        $failures = [];

        foreach ($mediaItems as $media) {
            $existing = [];
            $needed = [];

            foreach ($conversionNames as $conv) {
                if (! $force && $media->hasConversion($conv)) {
                    $existing[] = $conv;
                } else {
                    $needed[] = $conv;
                }
            }

            $skippedHere = count($existing);
            $skipped += $skippedHere;

            if (empty($needed)) {
                continue;
            }

            try {
                $manipulator->manipulate($media, $needed, false);
                $processed++;
            } catch (\Exception $e) {
                $failures[] = ['id' => $media->getKey(), 'name' => $media->name, 'error' => $e->getMessage()];
            }
        }

        if ($processed > 0) {
            $this->statusLine('Processed', 'ok', (string) $processed);
        }

        if ($skipped > 0) {
            $this->statusLine('Skipped (already exist)', 'warn', (string) $skipped);
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
    }

    /**
     * Parse --media value into an array of IDs. Supports comma-separated
     * individual IDs ("1,3,5") and ranges ("1..10"), or a mix ("1,3..5").
     */
    protected function parseMediaIds(string $value): array
    {
        $ids = [];

        foreach (explode(',', $value) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (str_contains($part, '..')) {
                [$from, $to] = explode('..', $part);
                $from = (int) $from;
                $to = (int) $to;

                if ($from <= 0 || $to <= 0 || $from > $to) {
                    return [];
                }

                for ($i = $from; $i <= $to; $i++) {
                    $ids[] = $i;
                }
            } else {
                $id = (int) $part;

                if ($id <= 0) {
                    return [];
                }

                $ids[] = $id;
            }
        }

        return $ids;
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
