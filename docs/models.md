# Models & associations

[← Back to README](../README.md)

- [Setup](#setup)
- [Channels vs collections](#channels-vs-collections)
- [Attach media](#attach-media)
- [Check, retrieve, detach](#check-retrieve-detach)
- [`getFirst*` and `getLast*` helpers](#getfirst-and-getlast-helpers)
- [Sync media](#sync-media)
- [Ordering media](#ordering-media)
- [Channel fallbacks](#channel-fallbacks)
- [Channel conversions](#channel-conversions)
- [Cross-model operations](#cross-model-operations)

## Setup

The `HasMedia` trait wires the MediaMan polymorphic relationship into any Eloquent model.

```php
use Emaia\MediaMan\Traits\HasMedia;
use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use HasMedia;
}
```

## Channels vs collections

This is the most common source of confusion when starting out.

| Concept | Pivot table | Per-(model, channel) order? |
|---|---|---|
| **Collection** (virtual group, reusable across models) | `mediaman_collection_media` | No — collections are unordered groups |
| **Channel** (per-model tag, configured via `addMediaChannel`) | `mediaman_mediables` | Yes — has `order_column` |

A single Media can be **attached to many models in different channels** (post.gallery, user.avatar) AND **bundled into many collections** ("Photos", "Imports"). Each combination is an independent pivot row.

## Attach media

`attachMedia()` accepts a Media model, an id, a name, or an iterable of any of those:

```php
$post = Post::first();

$post->attachMedia($media);                          // default channel
$post->attachMedia($media, 'featured');              // custom channel
$post->attachMedia([1, 2, 3], 'gallery');            // by ids
$post->attachMedia(Media::all(), 'gallery');         // by collection
$post->attachMedia($media, 'gallery', [], 5);        // with explicit order_column
```

Returns the number of attached items (int) on success or null on failure.

## Check, retrieve, detach

```php
$post->hasMedia();                  // bool
$post->hasMedia('featured');        // bool, scoped to channel

$post->getMedia();                  // all media in default channel
$post->getMedia('featured');        // scoped to channel
$post->getMedia(null);              // across all channels (rarely useful)

$post->detachMedia($media);         // detach specific media
$post->detachMedia([1, 2]);         // by ids
$post->detachMedia();               // detach all media from all channels
$post->clearMediaChannel('gallery'); // detach all media in a specific channel
```

### Cache invalidation

`getMedia()` results are cached per-instance to avoid hitting the database on repeat reads inside the same request. The cache is invalidated automatically whenever the trait mutates the pivot — `attachMedia`, `syncMedia`, `detachMedia`, `setMediaOrder`, and `clearMediaChannel` all clear the relevant entries.

If something *outside* the trait changes the pivot — a queued job on the `sync` driver reusing the same model instance, a raw `DB::table()` insert, a sibling relation refresh — the cache stays stale until you tell it otherwise. `forgetMediaCache()` is the escape hatch:

```php
$post->forgetMediaCache('gallery');  // clear one channel
$post->forgetMediaCache();           // clear every channel
```

Clearing a specific channel also invalidates the all-channels (`getMedia(null)`) snapshot, since it includes that channel's media. Returns `$this` so it chains fluently.

## `getFirst*` and `getLast*` helpers

For the common single-image case (avatars, cover photos), prefer the dedicated helpers — they're shorter and read better.

```php
$post->getFirstMedia();                                 // Media|null
$post->getFirstMedia('featured');
$post->getFirstMediaUrl();                              // string, '' if missing (or fallback)
$post->getFirstMediaUrl('featured', 'thumb');           // with conversion
$post->getFirstMediaUrlWithFallback('featured', 'thumb'); // falls back to original on missing conversion
$post->getFirstMediaConversionUrl('featured', 'thumb'); // null if conversion missing
$post->hasMediaConversion('featured', 'thumb');         // bool
$post->getFirstMediaPath('featured');                   // absolute path (or fallback path)
```

Mirror set for the last attached item:

```php
$post->getLastMedia();
$post->getLastMediaUrl();
$post->getLastMediaUrl('featured', 'thumb');
$post->getLastMediaUrlWithFallback('featured', 'thumb');
$post->getLastMediaConversionUrl('featured', 'thumb');
$post->hasLastMediaConversion('featured', 'thumb');
$post->getLastMediaPath('featured');
```

## Sync media

```php
$post->syncMedia($media);                                  // remove others, attach this one
$post->syncMedia([1, 2, 3], 'gallery');                    // sync gallery to exactly these ids
$post->syncMedia([1, 2, 3], 'gallery', conversions: ['thumb']); // also dispatch conversions
$post->syncMedia([1, 2, 3], 'gallery', detaching: false);   // attach only, don't remove
$post->syncMedia([1, 2, 3], 'gallery', startOrder: 10);     // first new attach gets order_column = 10
```

Returns `['attached' => [...], 'detached' => [...], 'updated' => [...]]`.

> None of the attach/detach/sync methods delete files. Use `$media->delete()` for that.

## Ordering media

Each row in `mediaman_mediables` carries an `order_column`. `attachMedia()` without an explicit position assigns the next sequential slot (`max(order_column) + 1`). Reads via `getMedia()` are ordered by `order_column` ascending, with `NULL` rows sorted **last** (cross-DB).

```php
$post->attachMedia($m1);                          // 0
$post->attachMedia($m2);                          // 1
$post->attachMedia($m3, 'gallery', [], 10);       // explicit 10
$post->attachMedia([$m4, $m5], 'gallery', [], 20); // batch: 20, 21
```

Batch reorder:

```php
$post->setMediaOrder([$m3->id, $m1->id, $m2->id]);            // default channel
$post->setMediaOrder([$mB->id, $mA->id], 'gallery');           // scoped
```

`setMediaOrder()` runs inside a DB transaction. It throws `InvalidArgumentException` if any id is not currently attached to the model in the given channel.

### Realistic examples

**Blog gallery with drag-and-drop reorder**

```php
class Post extends Model
{
    use HasMedia;

    public function registerMediaChannels(): void
    {
        $this->addMediaChannel('cover')->performConversions('thumb');
        $this->addMediaChannel('gallery')->performConversions('thumb', 'large');
    }
}

$post->attachMedia($cover, 'cover');
$post->attachMedia([$p1, $p2, $p3, $p4], 'gallery'); // 0..3

// User drags photos in a new order; the frontend sends the id sequence
$post->setMediaOrder([$p3->id, $p1->id, $p4->id, $p2->id], 'gallery');

foreach ($post->getMedia('gallery') as $photo) {
    echo $photo->getUrl('large');
}
```

**Same image used in multiple posts, each with its own order**

```php
$hero = MediaUploader::source($request->file('hero'))->upload();

$postA->attachMedia($hero, 'gallery');                    // 0 in its channel
$postB->attachMedia([$x, $y, $hero], 'gallery');          // hero ends at 2
$postC->attachMedia($hero, 'gallery', [], 99);            // forced position 99
```

Three independent rows in `mediaman_mediables`, three independent orderings.

**Multi-channel reorder API endpoint**

```php
// PATCH /posts/{id}/media-order
// payload: ['cover' => [...], 'gallery' => [...]]

DB::transaction(function () use ($post, $payload) {
    foreach ($payload as $channel => $orderedIds) {
        $post->setMediaOrder($orderedIds, $channel);
    }
});
```

Each `setMediaOrder` call is scoped — no cross-channel contamination.

### What's *not* ordered

- **Collections** don't have an `order_column` — `MediaCollection::media()` returns rows in database order. If you need ordered galleries that span models, attach via channels on a parent model instead.
- `media()` without a channel filter (`$post->media`) does sort by `order_column`, but since orders are scoped per-channel the result can mix channels in confusing ways. Always pass a channel when you care about order.

## Channel fallbacks

Declare a fallback URL or path used when the channel has no media attached:

```php
public function registerMediaChannels(): void
{
    $this->addMediaChannel('avatar')
        ->useFallbackUrl('/img/default-avatar.png')
        ->useFallbackPath(public_path('img/default-avatar.png'));
}

$post->getFirstMediaUrl('avatar');   // '/img/default-avatar.png' when empty
$post->getFirstMediaPath('avatar');  // '/var/www/.../public/img/default-avatar.png'
```

Per-conversion override:

```php
$this->addMediaChannel('avatar')
    ->useFallbackUrl('/img/default-avatar.png')
    ->useFallbackUrl('/img/avatar-thumb.png', 'thumb');

$post->getFirstMediaUrl('avatar');          // '/img/default-avatar.png'
$post->getFirstMediaUrl('avatar', 'thumb'); // '/img/avatar-thumb.png'
```

Fallbacks apply to `getFirstMediaUrl`, `getFirstMediaUrlWithFallback`, `getFirstMediaPath` and the matching `getLastMedia*` helpers.

## Channel conversions

A channel can declare conversions to run on attached media. See [Conversions](conversions.md).

```php
public function registerMediaChannels(): void
{
    $this->addMediaChannel('gallery')
        ->performConversions('thumb', 'large');
}
```

## Channel validation rules

`acceptsFile()` registers closures that run at **attach time** (not at upload). Every rule must return truthy or the attach fails with `MediaNotAcceptedByChannel` — the Media itself stays in the library, so the caller can re-attach it somewhere else or retry without re-uploading.

```php
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Traits\HasMedia;

public function registerMediaChannels(): void
{
    $this->addMediaChannel('hero')
        ->acceptsFile('min-width', fn (Media $m) => $m->getImageWidth() >= 1920)
        ->acceptsFile('landscape', fn (Media $m) => $m->getImageWidth() > $m->getImageHeight());

    $this->addMediaChannel('gallery')
        ->acceptsFile('image-only', fn (Media $m) => $m->isOfType(MediaType::IMAGE))
        ->acceptsFile('max-5', fn (Media $m, HasMedia $model) =>
            $model->getMedia('gallery')->count() < 5
        );

    $this->addMediaChannel('uploads')
        ->acceptsFile('within-quota', fn (Media $m, HasMedia $model) =>
            $model->getMedia('uploads')->sum('size') + $m->size <= 100 * 1024 * 1024
        );
}
```

Rules stack with implicit AND — every registered closure must pass. Pass a string as the first argument to name a rule for error reporting; the exception's `$e->rule` carries that name back to the caller.

Closures receive the `Media` instance, and the owning model as a **second** parameter when the signature declares it. The package detects this with reflection once at registration, so per-item validation stays reflection-free.

### Property-only vs. aggregate rules

When every rule reads only the media (`mime_type`, dimensions, size, custom properties), the trait runs the **fast path**: it validates all incoming items up-front and attaches them in a single INSERT — same throughput as a channel without rules.

When any rule declares the second `$model` parameter, the trait switches to the **aggregate path**:

- The owning model row is locked (`lockForUpdate()`) at the start of a `DB::transaction` so concurrent batches against the same instance queue instead of racing past a `count() < N` cap.
- Each item is validated and attached one at a time, so aggregate rules see the count grow as the batch progresses.
- The batch is **all-or-nothing**: if any item is rejected, the whole transaction rolls back, so you never end up with a partial subset whose contents depend on input order. A thrown exception always means "nothing changed", which keeps controller `catch` blocks honest.

Rules run **before** the current item is attached, so `getMedia($channel)` reflects items already accepted earlier in the batch but not the candidate itself. A `count() < N` cap needs no compensation (the Nth accepted item makes the next read return `N`), but an aggregate **sum** must include the candidate explicitly — note the `+ $m->size` in the `within-quota` example above.

**Want best-effort instead?** For bulk ingestion where you'd rather keep the items that fit and skip the rest, attach per-item in a loop — atomic is the primitive, partial builds on top of it. See [Recipes → Best-effort (partial) attach](recipes.md#best-effort-partial-attach).

The concurrency guarantee depends on a driver with real row locks (MySQL/InnoDB, Postgres). SQLite serializes writes via its file lock and gets equivalent safety without emitting `FOR UPDATE`.

Keep rule closures cheap — they run per-item, and aggregate rules also run inside a transaction. Avoid HTTP calls and expensive filesystem reads in rules.

**Side effect on the `media` relation.** The aggregate path invalidates any eager-loaded `media` relation on the model so rules read fresh data inside the transaction. After the attach call returns, accessing `$product->media` triggers a new query. If you need the relation hot for follow-up reads, call `$product->load('media')` again — or just rely on `getMedia($channel)`, which now reflects the post-attach state.

**Soft-deleted media.** All three attach paths verify that every requested id exists by loading the Media row through the configured model. If your custom `Media` uses `SoftDeletes`, a trashed row is invisible to that query and the attach throws `InvalidArgumentException` reporting it as missing — which matches the intent of "cannot attach trashed media". Restore the row first (or use `Media::withTrashed()->find(...)` to surface it) before reattaching.

### Handling rejection in a controller

```php
use Emaia\MediaMan\Exceptions\MediaNotAcceptedByChannel;

try {
    $product->attachMedia($media, 'hero');
} catch (MediaNotAcceptedByChannel $e) {
    // Default: $media stays in the library available for re-attach.
    // To discard: $media->delete();

    return back()->with('error', match ($e->rule) {
        'min-width' => 'The hero image must be at least 1920px wide.',
        'landscape' => 'The hero image must be landscape.',
        default     => 'Image rejected by the hero channel.',
    });
}
```

`$e->channel`, `$e->rule` and `$e->mediaId` are public readonly props — pattern-match on them instead of parsing the exception message.

## Cross-model operations

When you want the same media on multiple models, use the helpers on the `Media` model rather than reattaching by id from each side. Both are documented under [Media → Copy and re-attach](media.md#copy-and-re-attach):

- `Media::copy($target, $channel)` — duplicates the record and file (plus conversions and responsive variants), then attaches the copy to `$target`.
- `Media::attachTo($target, $channel)` — re-attaches the same Media to another model without touching disk.
