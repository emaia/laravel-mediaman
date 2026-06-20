# Collections

[← Back to README](../README.md)

- [Create a collection](#create-a-collection)
- [Retrieve a collection](#retrieve-a-collection)
- [Update a collection](#update-a-collection)
- [Delete a collection](#delete-a-collection)
- [Bind, unbind, sync media](#bind-unbind-sync-media)
- [Validation and auto-prune](#validation-and-auto-prune)
- [Manual enforcement](#manual-enforcement)

A `MediaCollection` is a virtual group of media — useful for organizing assets independently of the models that consume them. Collections are stored in `mediaman_collections` and the many-to-many relationship lives in `mediaman_collection_media`.

> Collections are **not** the same as channels. Channels are per-model tags carried in `mediaman_mediables` and they have an order column; collections are reusable groups without ordering. See [Models → Channels vs collections](models.md#channels-vs-collections).

## Create a collection

Collections are created on the fly when uploading:

```php
$media = MediaUploader::source($request->file('file'))
    ->useCollection('My Collection')
    ->upload();
```

Or independently:

```php
MediaCollection::create(['name' => 'My Collection']);
```

## Retrieve a collection

```php
MediaCollection::find(1);
MediaCollection::findByName('My Collection');

// With media eager-loaded
MediaCollection::with('media')->find(1);
MediaCollection::with('media')->findByName('My Collection');
```

## Update a collection

```php
$collection = MediaCollection::findByName('My Collection');
$collection->name = 'Our Collection';
$collection->save();
```

## Delete a collection

```php
$collection = MediaCollection::find(1);
$collection->delete();
```

Deleting a collection removes its bindings; the media itself stays. (`deleteWithMedia()` is a conceptual feature not yet implemented — open an issue if you need it.)

## Bind, unbind, sync media

Both `MediaCollection` and `Media` expose mirror methods (`MediaCollection::attachMedia()` ↔ `Media::attachCollections()`).

```php
$collection = MediaCollection::first();

// Bind — accepts a Media model, id, name, or any iterable of those
$collection->attachMedia($media);
$collection->attachMedia([1, 2, 3]);
$collection->attachMedia(['photo.jpg', 'banner.png']);

// Unbind
$collection->detachMedia($media);
$collection->detachMedia([]);   // pass empty to detach all

// Sync (replaces the entire set)
$collection->syncMedia($media);
$collection->syncMedia(Media::all());
$collection->syncMedia([1, 2, 3, 4, 5]);
$collection->syncMedia([]);     // empty out
```

`attachMedia` returns the count attached (int), `detachMedia` returns the count detached (int), `syncMedia` returns the sync status array. All return `null` on failure.

## Validation and auto-prune

Collections can constrain accepted MIME types and cap the number of items they hold.

### Restrict accepted MIME types

```php
$collection = MediaCollection::create(['name' => 'avatars']);

$collection->acceptsMimeTypes(['image/png'])->save();             // single
$collection->acceptsMimeTypes(['image/png', 'image/jpeg'])->save(); // multiple
$collection->acceptsMimeTypes(['image/*'])->save();               // wildcard
```

Uploads or attaches with an unmatched MIME throw `Emaia\MediaMan\Exceptions\MediaNotAcceptedByCollection`.

An empty array (`acceptsMimeTypes([])`) or `null` means "accept anything".

### Auto-prune oldest

```php
// Keep at most N items, prune oldest on overflow
$collection = MediaCollection::create(['name' => 'gallery'])
    ->onlyKeepLatest(5);
$collection->save();

// Shortcut for onlyKeepLatest(1)
$avatar = MediaCollection::create(['name' => 'avatar'])
    ->singleFile();
$avatar->save();
```

When a new media pushes the collection above `max_items`, the **oldest** (by `created_at`, with id as tiebreaker) is **detached**. The Media record itself is **never deleted** — it may still belong to other models, channels, or collections.

Auto-prune fires from both upload (`MediaUploader::source()->useCollection(...)->upload()`) and direct attach (`$collection->attachMedia($media)`).

Every prune dispatches a `MediaPrunedFromCollection` event carrying the collection and the list of detached media ids — listen if you need an audit log, want to notify the owning user, or care about cleaning up something downstream:

```php
use Emaia\MediaMan\Events\MediaPrunedFromCollection;

Event::listen(function (MediaPrunedFromCollection $event) {
    Log::info("Pruned media from {$event->collection->name}", [
        'detached_ids' => $event->detachedMediaIds,
    ]);
});
```

### Fluent setters require `->save()`

The setters mutate the model in memory only — call `->save()` to persist (matches Eloquent convention):

```php
$collection->onlyKeepLatest(3)->acceptsMimeTypes(['image/jpeg'])->save();
```

### Manual enforcement

The validation and prune hooks fire automatically on `attachMedia()` and during uploads. If you need to enforce them on your own (e.g. after a bulk DB-level insert or when migrating data), call them directly:

```php
$collection->validateMedia($media);    // throws MediaNotAcceptedByCollection on MIME mismatch
$collection->enforceMaxItems();        // detach oldest media to honor `max_items`
```

`enforceMaxItems()` only detaches — it never deletes Media records.
