# Events

[← Back to README](../README.md)

- [Available events](#available-events)
- [Register listeners](#register-listeners)
- [Conversion failures](#conversion-failures)

MediaMan dispatches Laravel events at key points in the media lifecycle. Listen with standard `Event::listen()` calls or via `EventServiceProvider`.

## Available events

| Event                         | Dispatched when                                                                | Payload                                       |
|-------------------------------|--------------------------------------------------------------------------------|-----------------------------------------------|
| `MediaUploaded`               | A file is uploaded via `MediaUploader` (after the transaction commits)         | `$event->media`                               |
| `MediaDeleted`                | A media record is deleted                                                      | `$event->media`                               |
| `MediaPrunedFromCollection`   | `enforceMaxItems()` auto-detaches older media from a capped collection         | `$event->collection`, `$event->detachedMediaIds` |
| `ConversionCompleted`         | At least one image conversion succeeds (queued job, partial-batch)             | `$event->media`, `$event->conversions` (the successful ones) |
| `ConversionFailed`            | A single image conversion fails — fires once per failure inside a batch        | `$event->media`, `$event->conversion`, `$event->exception` |
| `ResponsiveImagesGenerated`   | Responsive variants finish (queued job)                                        | `$event->media`, `$event->options`            |

All event classes live under `Emaia\MediaMan\Events`.

`MediaUploaded` is dispatched **after** the upload transaction commits — listeners can safely query the media row, dispatch jobs that touch it, or fan out to other services. Responsive variant generation runs immediately before the event fires; depending on `responsive_images.queue`, the variants may already be on disk (inline mode) or still queued in a worker job (queued mode, the default). Listeners that strictly need the variants to be present should check `$media->hasResponsiveImages()` and react when they appear, or hook into `ResponsiveImagesGenerated` instead.

## Register listeners

In `EventServiceProvider`:

```php
use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\Events\ConversionCompleted;
use Emaia\MediaMan\Events\ResponsiveImagesGenerated;

protected $listen = [
    MediaUploaded::class => [
        SendUploadNotification::class,
    ],
    MediaDeleted::class => [
        CleanupExternalCdn::class,
    ],
];
```

Or with a closure:

```php
use Emaia\MediaMan\Events\MediaUploaded;
use Illuminate\Support\Facades\Event;

Event::listen(function (MediaUploaded $event) {
    logger()->info("Media uploaded: {$event->media->file_name}");
});
```

## Conversion failures

`ConversionFailed` is a **final** signal — it fires only when the queue won't retry the conversion again. This contract makes listeners safe to act decisively (mark as broken, notify the user, reschedule with a different strategy) without racing the queue's own retry logic.

### When the event fires

| Scenario | When `ConversionFailed` fires | Why |
|---|---|---|
| Partial-batch (some completed, some failed) | Immediately, inside `handle()` | No retry happens — the job ends successfully with surviving conversions written |
| All-failed | Deferred to `PerformConversions::failed()` — fires once retries are exhausted | The queue will retry first; events would be premature |
| Inline `mediaman:generate-conversions` | Immediately per failure | CLI doesn't retry |

`PerformConversions` always writes a `Log::warning` per failure during `handle()` (`mediaId`/`conversion`/`error`) — every attempt, including intermediate retries — so observability never depends on the event timing.

### Listener patterns

**Observability is automatic.** Default Laravel log channel already carries each failure. No listener needed just to see what failed.

**React to permanent failures** (audit, notify, mark broken):

```php
Event::listen(function (ConversionFailed $event) {
    // Reached here = the queue gave up. Act with confidence.
    AuditLog::create([
        'media_id'   => $event->media->id,
        'conversion' => $event->conversion,
        'error'      => $event->exception->getMessage(),
    ]);
});
```

**Reschedule transient failures with the `reschedule()` helper:**

```php
Event::listen(function (ConversionFailed $event) {
    if ($event->exception instanceof TransientStorageException) {
        $event->reschedule(120);  // queue a new single-conversion job in 2 minutes
    }
});
```

`reschedule(int $delaySeconds = 60): PendingDispatch` builds a new `PerformConversions` job for just this conversion. The policy (which exceptions, what delay, hard caps) stays in your listener — the package doesn't auto-retry anything; it just hands you the primitive.

**Queued listeners and `Throwable` serialization.** If you implement a `ShouldQueue` listener, be aware: not every exception serializes cleanly (closures captured in the trace, resources, `PDOException`'s `PDOStatement`). For audit/log workflows that need async processing, extract the strings synchronously and queue your own DTO:

```php
class RecordConversionFailure  // not ShouldQueue — runs inline
{
    public function handle(ConversionFailed $event): void
    {
        QueuedAuditEntry::dispatch([            // your own job carries plain data
            'media_id'        => $event->media->id,
            'conversion'      => $event->conversion,
            'exception_class' => $event->exception::class,
            'exception_msg'   => $event->exception->getMessage(),
        ]);
    }
}
```

### Catastrophic batch failure

When every conversion in a batch fails, the job re-throws so the queue retries — covers transient errors (S3 blip, decoder memory pressure) that the per-conversion isolation would otherwise absorb. Listeners see `ConversionFailed` only after retries exhaust (via `failed()` hook); for the parallel "the whole job died" signal, hook into Laravel's standard `Illuminate\Queue\Events\JobFailed`.

### Sync queue caveat

When `QUEUE_CONNECTION=sync`, the rethrow on all-failed propagates the wrapped `RuntimeException` to whoever called `dispatch()` — directly or indirectly (e.g. `HasMedia::attachMedia()` dispatches `PerformConversions` under the hood). Events still fire before the throw via the `failed()` hook, so listeners see the outcome — but the request-cycle caller needs a `try/catch` if you don't want a 500. Async/queued workers absorb the throw naturally.

```php
try {
    $product->attachMedia($media, 'gallery');   // dispatches PerformConversions
} catch (\RuntimeException $e) {
    // sync queue + all-failed. Listeners already ran before this catch executes.
    return back()->withError('Image processing failed.');
}
```

The same applies to partial-batch failures, which never throw regardless of queue connection — the surviving conversions ship and `ConversionFailed` fires for each failure immediately.
