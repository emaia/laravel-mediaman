<?php

use Emaia\MediaMan\Events\MediaPrunedFromCollection;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\MediaCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;

it('dispatches MediaPrunedFromCollection when auto-prune detaches media', function () {
    Event::fake([MediaPrunedFromCollection::class]);

    $collection = MediaCollection::create(['name' => 'gallery']);
    $collection->onlyKeepLatest(2)->save();

    $first = MediaUploader::source(UploadedFile::fake()->image('1.jpg'))->upload();
    $second = MediaUploader::source(UploadedFile::fake()->image('2.jpg'))->upload();
    $third = MediaUploader::source(UploadedFile::fake()->image('3.jpg'))->upload();

    // Attach in chronological order. The third attach trips the cap and
    // prunes the oldest one (first).
    $collection->attachMedia([$first->getKey(), $second->getKey()]);

    Event::assertNotDispatched(MediaPrunedFromCollection::class);

    $collection->attachMedia($third->getKey());

    Event::assertDispatched(MediaPrunedFromCollection::class, function (MediaPrunedFromCollection $event) use ($collection, $first) {
        return $event->collection->is($collection)
            && $event->detachedMediaIds === [$first->getKey()];
    });
});

it('does not dispatch the event when no media gets pruned', function () {
    Event::fake([MediaPrunedFromCollection::class]);

    $collection = MediaCollection::create(['name' => 'gallery']);
    $collection->onlyKeepLatest(5)->save();

    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $collection->attachMedia($media);

    Event::assertNotDispatched(MediaPrunedFromCollection::class);
});

it('does not dispatch the event when the collection has no max_items', function () {
    Event::fake([MediaPrunedFromCollection::class]);

    $collection = MediaCollection::create(['name' => 'unlimited']);

    $a = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $b = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    $collection->attachMedia([$a->getKey(), $b->getKey()]);

    Event::assertNotDispatched(MediaPrunedFromCollection::class);
});

it('carries every detached id when the cap is exceeded by more than one item', function () {
    Event::fake([MediaPrunedFromCollection::class]);

    $collection = MediaCollection::create(['name' => 'gallery']);
    $collection->onlyKeepLatest(2)->save();

    $a = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $b = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();
    $c = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->upload();
    $d = MediaUploader::source(UploadedFile::fake()->image('d.jpg'))->upload();

    // Attach all four at once. The cap is 2, so the two oldest (a and b)
    // should be pruned in a single batch.
    $collection->attachMedia([$a->getKey(), $b->getKey(), $c->getKey(), $d->getKey()]);

    Event::assertDispatched(MediaPrunedFromCollection::class, function (MediaPrunedFromCollection $event) use ($a, $b) {
        return $event->detachedMediaIds === [$a->getKey(), $b->getKey()];
    });
});

it('does not delete the detached media records from the library', function () {
    Event::fake([MediaPrunedFromCollection::class]);

    $collection = MediaCollection::create(['name' => 'gallery']);
    $collection->onlyKeepLatest(1)->save();

    $first = MediaUploader::source(UploadedFile::fake()->image('1.jpg'))->upload();
    $second = MediaUploader::source(UploadedFile::fake()->image('2.jpg'))->upload();

    $collection->attachMedia($first);
    $collection->attachMedia($second);

    Event::assertDispatched(MediaPrunedFromCollection::class);

    // The pruned media is detached from the collection pivot but the row
    // still lives in the library — this is the long-standing auto-prune
    // contract, the event just makes it observable.
    expect($collection->media()->count())->toBe(1)
        ->and($first->fresh())->not->toBeNull();
});
