# Events

[← Back to README](../README.md)

- [Available events](#available-events)
- [Register listeners](#register-listeners)

MediaMan dispatches Laravel events at key points in the media lifecycle. Listen with standard `Event::listen()` calls or via `EventServiceProvider`.

## Available events

| Event                         | Dispatched when                          | Payload                                  |
|-------------------------------|------------------------------------------|------------------------------------------|
| `MediaUploaded`               | A file is uploaded via `MediaUploader`   | `$event->media`                          |
| `MediaDeleted`                | A media record is deleted                | `$event->media`                          |
| `ConversionCompleted`         | Image conversions finish (queued job)    | `$event->media`, `$event->conversions`   |
| `ResponsiveImagesGenerated`   | Responsive variants finish (queued job)  | `$event->media`, `$event->options`       |

All event classes live under `Emaia\MediaMan\Events`.

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
