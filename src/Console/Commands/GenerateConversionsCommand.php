<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaIds;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Events\ConversionCompleted;
use Emaia\MediaMan\Events\ConversionFailed;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class GenerateConversionsCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaIds;

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

        if ($total * $convCount > 100 && ! $this->confirm("Will process $total media item(s) × $convCount conversion(s) = ".($total * $convCount).' operations. Continue?')) {
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

    /** @param  Collection<int, Media>  $mediaItems */
    protected function processQueued(Collection $mediaItems, array $conversionNames): void
    {
        $count = $mediaItems->count();

        foreach ($mediaItems as $media) {
            PerformConversions::dispatch($media, $conversionNames);
        }

        $this->statusLine('Dispatched', 'ok', "$count (queued)");
    }

    /** @param  Collection<int, Media>  $mediaItems */
    protected function processInline(Collection $mediaItems, array $conversionNames, bool $force): void
    {
        $manipulator = app(ImageManipulator::class);
        $completed = 0;
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

            $skipped += count($existing);

            if (empty($needed)) {
                continue;
            }

            $report = $manipulator->manipulate($media, $needed, false);
            $completed += count($report['completed']);

            if (! empty($report['completed'])) {
                event(new ConversionCompleted($media, $report['completed']));
            }

            foreach ($report['failed'] as $failure) {
                Log::warning('MediaMan: Conversion failed', [
                    'mediaId' => $media->getKey(),
                    'conversion' => $failure['conversion'],
                    'error' => $failure['exception']->getMessage(),
                ]);

                event(new ConversionFailed($media, $failure['conversion'], $failure['exception']));
                $failures[] = [
                    'id' => $media->getKey(),
                    'name' => $media->name,
                    'conversion' => $failure['conversion'],
                    'error' => $failure['exception']->getMessage(),
                ];
            }
        }

        if ($completed > 0) {
            $this->statusLine('Completed conversions', 'ok', (string) $completed);
        }

        if ($skipped > 0) {
            $this->statusLine('Skipped (already exist)', 'warn', (string) $skipped);
        }

        if (! empty($failures)) {
            $this->statusLine('Failed conversions', 'error', (string) count($failures));

            foreach ($failures as $f) {
                $this->components->twoColumnDetail(
                    "  #{$f['id']} {$f['name']} ({$f['conversion']})",
                    "<fg=red>✗</> {$f['error']}"
                );
            }
        }

        if ($completed === 0 && $skipped === 0 && empty($failures)) {
            $this->statusLine('Result', 'info', 'nothing to do');
        }
    }
}
