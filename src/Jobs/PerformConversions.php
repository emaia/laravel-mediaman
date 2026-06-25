<?php

namespace Emaia\MediaMan\Jobs;

use Emaia\MediaMan\Events\ConversionCompleted;
use Emaia\MediaMan\Events\ConversionFailed;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PerformConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Media $media;

    protected array $conversions;

    /**
     * Failures from the most recent `handle()` invocation that triggered an
     * all-failed throw. Consumed by `failed()` after Laravel exhausts retries.
     *
     * @var array<int, array{conversion: string, exception: Throwable}>
     */
    protected array $deferredFailures = [];

    public function __construct(Media $media, array $conversions)
    {
        $this->media = $media;

        $this->conversions = $conversions;
    }

    public function handle(ImageManipulator $manipulator): void
    {
        if (! $this->media->isRasterImage()) {
            return;
        }

        $report = $manipulator->manipulate($this->media, $this->conversions);

        foreach ($report['failed'] as $failure) {
            Log::warning('MediaMan: Conversion failed', [
                'mediaId' => $this->media->id,
                'conversion' => $failure['conversion'],
                'error' => $failure['exception']->getMessage(),
            ]);
        }

        // Partial-batch: at least one conversion succeeded → no retry will run.
        // Fire events now so listeners can act on a final outcome.
        if (! empty($report['completed'])) {
            event(new ConversionCompleted($this->media, $report['completed']));

            foreach ($report['failed'] as $failure) {
                event(new ConversionFailed(
                    $this->media,
                    $failure['conversion'],
                    $failure['exception'],
                ));
            }

            return;
        }

        // All-failed: defer `ConversionFailed` to the `failed()` hook so
        // listeners only see the event after Laravel exhausts retries —
        // guarantees "if you got the event, the queue gave up."
        if (! empty($report['failed'])) {
            $this->deferredFailures = $report['failed'];

            throw new RuntimeException(
                sprintf(
                    'PerformConversions: all %d conversion(s) failed for media #%d',
                    count($report['failed']),
                    $this->media->id,
                ),
                previous: $report['failed'][0]['exception'],
            );
        }
    }

    /**
     * Called by the queue worker once retries are exhausted. Surfaces the
     * deferred per-conversion failures so listeners can react to definitive
     * outcomes (no risk of acting while the queue is still retrying).
     */
    public function failed(Throwable $exception): void
    {
        foreach ($this->deferredFailures as $failure) {
            event(new ConversionFailed(
                $this->media,
                $failure['conversion'],
                $failure['exception'],
            ));
        }
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getConversions(): array
    {
        return $this->conversions;
    }
}
