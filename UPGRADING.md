# Upgrading

This file lists the breaking changes per release and the migration steps. Apps already on the immediately preceding minor only need the section for the target version; apps jumping multiple minors need every intervening section.

For non-breaking additions, see [CHANGELOG.md](CHANGELOG.md).

---

## From v2.18 to v3.0

v3.0 consolidates the API surface and trims debt accumulated since v2. The changes below are the only required code edits for an app already running v2.18.

### 1. `PathGenerator` / `UrlGenerator` / `FileNamer` consolidated into `MediaResolver`

The three pluggable interfaces (one per concern) collapsed into a single `MediaResolver` because most customizations touched all three together. Method names also drop the `get` prefix to match Laravel idiom.

**Config rename.** Replace the `mediaman.generators.*` block:

```diff
- 'generators' => [
-     'path'       => CustomPathGenerator::class,
-     'url'        => CustomUrlGenerator::class,
-     'file_namer' => CustomFileNamer::class,
- ],
+ 'resolver' => CustomMediaResolver::class,
```

**Custom implementations.** Combine the three classes into one extending `DefaultMediaResolver`, override only the methods you actually customized:

```php
use Emaia\MediaMan\Resolvers\DefaultMediaResolver;
use Emaia\MediaMan\Models\Media;

class CustomMediaResolver extends DefaultMediaResolver
{
    public function directory(Media $media): string         // was getDirectory()
    {
        return 'tenants/'.tenant()->id.'/'.parent::directory($media);
    }

    public function url(Media $media, ?string $conversion = null): string  // was getUrl()
    {
        // your custom URL logic
    }
}
```

Method renames: `getDirectory → directory`, `getUrl → url`, `getBaseName → baseName`, `getTemporaryUrl → temporaryUrl`, `getConversionFileName → conversionFileName`, `getResponsiveFileName → responsiveFileName`.

If your custom resolver produces a directory shape that **does not match** `DefaultMediaResolver`'s `{id}-{md5}` pattern, also override `isManagedDirectory()` so `mediaman:clean` recognizes your shape as eligible for orphan cleanup (see [Conversions → Conversion disk](docs/conversions.md) for the contract).

### 2. `HasMedia::attachMedia` / `syncMedia` exception type

When a requested media id doesn't exist, attach/sync now throws `InvalidArgumentException` **before** any DB write happens. Previously the same condition produced a `QueryException` (FK violation) when foreign keys were enforced, and **silent orphan pivot rows** when they weren't.

```diff
  try {
      $product->attachMedia($mediaIds, 'gallery');
- } catch (QueryException $e) {
+ } catch (InvalidArgumentException $e) {
      // handle missing media id
  }
```

Apps that wrapped attach in `catch (Throwable)` don't need code changes — the new exception is caught too.

### 3. `PerformConversions` dispatch timing

The job is now dispatched **after** the attach pipeline commits, for both the legacy and rule-aware attach paths. Previously the legacy path dispatched up-front (which orphaned queued jobs if the attach itself later failed), and the rule paths swallowed queue-connection failures in a `catch (Throwable)`.

No code edits required for typical setups. Edge cases that may notice:

- Apps with custom queue connection error handlers may now see exceptions where they previously saw a silent `null` return from `syncMedia()`.
- Listeners on `MediaUploaded` that depend on conversions already having been dispatched run unchanged — dispatch order from caller's perspective is the same.

### 4. `mediaman.url.version_query` → `mediaman.url.versioning`

The boolean cache-busting key became a string enum.

```diff
  'url' => [
-     'version_query' => true,
+     'versioning' => 'timestamp',
      'prefix' => 'https://cdn.example.com',
  ],
```

Supported values: `false` (default) and `'timestamp'` (appends `?v={updated_at}`). The legacy `version_query` key is **no longer read** — apps that set it must rename explicitly.

Subclasses overriding the protected method rename it too:

```diff
- protected function applyVersionQuery(string $url, Media $media): string
+ protected function applyVersioning(string $url, Media $media): string
```

### 5. `MediaResolver::isManagedDirectory()` interface addition

Custom resolvers that extend `DefaultMediaResolver` inherit the default implementation (matches the `{id}-{md5}` shape). Custom resolvers that implement `MediaResolver` from scratch must add the method:

```php
public function isManagedDirectory(string $segment): bool
{
    return /* true if `$segment` could have been produced by your `directory()`, false otherwise */;
}
```

Returning `false` always opts the resolver out of `mediaman:clean` orphan cleanup — safer than `true` if you're not sure your pattern catches every shape.

### 6. `ImageManipulator::manipulate()` signature: `void` → `array`

Per-conversion isolation introduced a return shape that callers can inspect — a failure on one conversion no longer cancels the rest of the batch.

Subclasses overriding the method must update the signature:

```diff
- public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): void
+ public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): array
  {
      // ...
+     return ['completed' => $completed, 'failed' => $failed];
  }
```

Return shape: `['completed' => string[], 'failed' => array<{conversion: string, exception: Throwable}>]`.

Callers that ignore the return value (most apps) need no change — the method still runs the conversions as before.

### 7. `ConversionFailed` event + `ConversionCompleted` semantic change

Apps that monitored `failed_jobs` as a proxy for "some conversion in a batch failed" must migrate to a `ConversionFailed` listener. `failed_jobs` now only catches the catastrophic all-failed case (the job re-throws to trigger Laravel's retry; only after retries exhaust does it land in `failed_jobs`).

```php
use Emaia\MediaMan\Events\ConversionFailed;

Event::listen(function (ConversionFailed $event) {
    // Reached here = the queue gave up. Act with confidence.
    AuditLog::create([
        'media_id'   => $event->media->id,
        'conversion' => $event->conversion,
        'error'      => $event->exception->getMessage(),
    ]);
});
```

`ConversionFailed` is a **final** signal — it fires only when the queue won't retry the conversion again (partial-batch failures fire immediately, all-failed defers to `failed()` after retries exhaust). Listeners can act decisively without racing the queue's own retry logic. See [Events → Conversion failures](docs/events.md#conversion-failures) for the firing-semantics table, sync queue caveat, and `reschedule()` helper.

`ConversionCompleted` now carries only the **successful** conversions (previously it carried the requested list verbatim). The event is omitted entirely when no conversion completes.

```diff
  Event::listen(function (ConversionCompleted $event) {
-     // $event->conversions was the requested list — could include silently-failed names
+     // $event->conversions is now strictly the conversions that actually wrote to disk
      foreach ($event->conversions as $name) {
          // ...
      }
  });
```

### 8. `media_url` / `media_uri` accessors route through `getUrl()`

The two appended attributes used to call `$filesystem->url($path)` directly, bypassing the configured `MediaResolver`. They now route through `getUrl()`, so they pick up `url.prefix` and `url.versioning` consistently with everything else (`$media->getUrl()`, `getPictureHtml()`, mail attachments).

```php
// On a media with url.prefix = 'https://cdn.example.com' and url.versioning = 'timestamp':
$media->media_uri;   // 'https://cdn.example.com/4-abc.../photo.jpg?v=1735574400'
$media->media_url;   // asset() applied — same when getUrl() is already absolute
```

**BC:** API responses and JSON payloads that surface either attribute (via `$media->toArray()`, Eloquent Resources, model serialization) now include the prefix and the version query when those features are configured. Consumers that previously got the raw disk URL must accept the prefixed form, or stop relying on the appended attribute and read `$media->getOriginalPath()` or `$media->disk` directly if they need the unprefixed value.

No migration step required when `url.prefix` and `url.versioning` are unset (defaults) — the accessor values are byte-for-byte the same as before.

### 9. Storage write failures throw instead of being swallowed

`MediaUploader::upload()` is now atomic: row + file write + collection attach run in a single `DB::transaction`. The previously-silent case where `Filesystem::putFileAs()` returned `false` (S3 permission denies, full disk, etc.) now raises `Emaia\MediaMan\Exceptions\MediaFileWriteFailed` and the transaction rolls back.

**BC:** apps that uploaded against a misconfigured disk used to end up with an orphan media row pointing at a file that was never written — silent corruption. Same code path now throws:

```php
try {
    $media = MediaUploader::source($request->file('file'))->upload();
} catch (\Emaia\MediaMan\Exceptions\MediaFileWriteFailed $e) {
    // Storage layer rejected the write. $e->path and $e->disk are public readonly.
    return back()->withError("Could not write to {$e->disk}.");
}
```

Inline responsive-image generation failures (`responsive_images.queue = false`) used to propagate; they now log via `Log::warning` and the upload returns the durable media. Apps that wrapped the upload in `try/catch` to handle responsive failures should switch to listening on the upload outcome (`MediaUploaded` event + check `$media->hasResponsiveImages()` later) or use the queued path so failures surface via `failed_jobs`.

---

## v2.13 – v2.17 catch-up (skip if already on v2.18)

If you're upgrading from v2.12 or earlier directly to v3.0, apply these additional changes that shipped between v2.13 and v2.17.

### Console command class renames (v2.17.0)

Console class names dropped the redundant `Mediaman` prefix. The CLI signatures (`mediaman:clean`, `mediaman:doctor`, etc.) are unchanged — this only matters for code that references the FQCN:

```diff
- use Emaia\MediaMan\Console\Commands\MediamanCleanCommand;
+ use Emaia\MediaMan\Console\Commands\CleanCommand;
```

Affected classes: `MediamanCleanCommand`, `MediamanDoctorCommand`, `MediamanPublishCommand`, `MediamanPublishConfigCommand`, `MediamanPublishMigrationCommand`, `MediamanRotatePathsCommand`.

### `mediaman:generate-responsive --queue` flag flip (v2.17.0)

`--queue` is now an explicit boolean flag — passing it queues, omitting it processes inline. The previous fallthrough to `mediaman.responsive_images.queue` config is removed.

```diff
- php artisan mediaman:generate-responsive             # used the config default (often `true`)
+ php artisan mediaman:generate-responsive --queue     # explicit queue
+ php artisan mediaman:generate-responsive             # explicit inline
```

Scripts that passed `--queue=false` to force inline must drop the value (the flag is boolean now).

### `mediaman:clear-responsive --confirm` → `--force` (v2.17.0)

The skip-confirmation flag renamed to match Laravel's convention.

```diff
- php artisan mediaman:clear-responsive --confirm
+ php artisan mediaman:clear-responsive --force
```

### Soft-delete-aware file deletion (v2.17.1)

The `Media` model's `deleted` observer now skips file removal on a **soft delete** and only deletes on `forceDelete()` (or on a model without `SoftDeletes`). This only affects apps using a custom `Media` subclass with the `SoftDeletes` trait — previously, calling `$media->delete()` on a soft-deleted record wiped the underlying file, leaving `restore()` useless.

No migration required if you didn't subclass with `SoftDeletes`. If you did and **want** the old behavior, override the observer in your subclass.
