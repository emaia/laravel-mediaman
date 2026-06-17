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

## Cross-model operations

When you want the same media on multiple models, use the helpers on the `Media` model rather than reattaching by id from each side. Both are documented under [Media → Copy and re-attach](media.md#copy-and-re-attach):

- `Media::copy($target, $channel)` — duplicates the record and file (plus conversions and responsive variants), then attaches the copy to `$target`.
- `Media::attachTo($target, $channel)` — re-attaches the same Media to another model without touching disk.
