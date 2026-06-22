<?php

namespace Emaia\MediaMan\Console\Commands;

use Emaia\MediaMan\Console\Concerns\CommandOutputStyle;
use Emaia\MediaMan\Console\Concerns\ParsesMediaIds;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Illuminate\Console\Command;

class ClearConversionsCommand extends Command
{
    use CommandOutputStyle;
    use ParsesMediaIds;

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

        if ($total * $convCount > 100 && ! $this->option('force') && ! $this->confirm("Will clear {$convCount} conversion(s) on {$total} media item(s). Continue?")) {
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
        $resolver = app(MediaResolver::class);

        foreach ($mediaItems as $media) {
            $filesystem = $media->filesystem();

            foreach ($conversionNames as $conv) {
                $conversionDir = $resolver->pathForConversion($media, $conv);

                try {
                    if (! $filesystem->exists($conversionDir)) {
                        $skipped++;

                        continue;
                    }

                    if ($filesystem->deleteDirectory($conversionDir)) {
                        $cleared++;
                    } else {
                        $failures[] = ['id' => $media->getKey(), 'name' => $media->name, 'error' => "failed to delete '{$conv}' directory"];
                    }
                } catch (\Exception $e) {
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

        return self::SUCCESS;
    }
}
