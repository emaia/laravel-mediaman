<?php

namespace Emaia\MediaMan\Events;

use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\Models\Media;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Final, per-conversion failure. Fires from `PerformConversions::failed()`
 * after retries exhaust (all-failed path) or immediately from `handle()` for
 * partial-batch failures — never while the queue still plans to retry.
 *
 * `ShouldQueue` listeners should extract `$exception` data synchronously —
 * not every `Throwable` serializes cleanly. See docs/events.md → Conversion failures.
 */
class ConversionFailed
{
    use SerializesModels;

    public function __construct(
        public Media $media,
        public string $conversion,
        public Throwable $exception,
    ) {}

    /**
     * Dispatch a new single-conversion `PerformConversions` job, optionally
     * delayed. Convenience for listeners that decide to retry transient failures;
     * the policy (which exceptions, what delay, hard caps) stays in the listener.
     */
    public function reschedule(int $delaySeconds = 60): PendingDispatch
    {
        $dispatch = PerformConversions::dispatch($this->media, [$this->conversion]);

        if ($delaySeconds > 0) {
            $dispatch->delay(now()->addSeconds($delaySeconds));
        }

        return $dispatch;
    }
}
