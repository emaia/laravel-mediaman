<?php

use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->subject = Subject::create();
});

it('registers the media relationship', function () {
    expect($this->subject->media())->toBeInstanceOf(MorphToMany::class);
});

it('can attach media to the default channel', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media);

    $attachedMedia = $this->subject->media()->first();

    expect($media->id)->toEqual($attachedMedia->id);
    expect($attachedMedia->pivot->channel)->toEqual('default');
});

it('can attach media to a named channel', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media, $channel = 'custom');

    $attachedMedia = $this->subject->media()->first();

    expect($attachedMedia->id)->toEqual($media->id);
    expect($attachedMedia->pivot->channel)->toEqual($channel);
});

it('can attach a collection of media', function () {
    $media = factory(Media::class, 2)->create();

    $this->subject->attachMedia($media);

    $attachedMedia = $this->subject->media()->get();

    expect($attachedMedia)->toHaveCount(2);
    expect($media->diff($attachedMedia))->toBeEmpty();

    $attachedMedia->each(
        function ($media) {
            expect($media->pivot->channel)->toEqual('default');
        }
    );
});

it('returns number of attached media or null while associating', function () {
    $media = factory(Media::class)->create();

    $attachedCount = $this->subject->attachMedia($media, 'custom');

    expect($attachedCount)->toEqual(1);

    if (DB::connection() instanceof \Illuminate\Database\SQLiteConnection) {
        // SQLite doesn't enforce foreign key constraints by default, so this test won't fail as expected in an SQLite environment.
        // However, it should work as expected on other relational databases that enforce these constraints.
        $this->markTestSkipped('Skipping test for SQLite connection.');
    } else {
        // try attaching a non-existing media record
        $attached = $this->subject->attachMedia(5, 'custom');
        expect($attached)->toEqual(null);
    }
});

it('returns number of detached media or null while disassociating', function () {
    $media = factory(Media::class)->create();
    $this->subject->attachMedia($media, 'custom');

    $detached = $this->subject->detachMedia($media);

    expect($detached)->toEqual(1);

    // try detaching a non-existing media record
    $detached = $this->subject->detachMedia(100);
    expect($detached)->toEqual(null);
});

it('can_attach_media_and_returns_number_of_media_attached', function () {
    $media = factory(Media::class)->create();

    $attachedCount = $this->subject->attachMedia($media);

    expect($attachedCount)->toEqual(1);

    $attachedMedia = $this->subject->media()->first();
    expect($attachedMedia->id)->toEqual($media->id);
});

it('can detach media and returns number of media detached', function () {
    $media = factory(Media::class)->create();
    $this->subject->attachMedia($media);

    $detachedCount = $this->subject->detachMedia($media);

    expect($detachedCount)->toEqual(1);
    expect($this->subject->media()->first())->toBeNull();
});

it('can sync media and returns sync status', function () {
    $media1 = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->useCollection('default')
        ->useDisk('default')
        ->upload();
    $media2 = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->useCollection('default')
        ->useDisk('default')
        ->upload();

    // Initially attach media1
    $this->subject->attachMedia($media1);

    // Now, sync to media2
    $syncStatus = $this->subject->syncMedia($media2);

    expect($syncStatus)->toHaveKey('updated');
    expect($syncStatus)->toHaveKey('attached');
    expect($syncStatus)->toHaveKey('detached');

    expect($syncStatus['attached'])->toEqual([$media2->id]);
    expect($syncStatus['detached'])->toEqual([$media1->id]);

    $syncStatus = $this->subject->syncMedia([]);
    // should detach all
    expect(count($syncStatus['detached']))->toEqual(1);
});

it('can sync collections for a media instance', function () {
    $media = factory(Media::class)->create();
    $collections = ['collection1', 'collection2'];

    $syncStatus = $media->syncCollections($collections);

    expect($syncStatus)->toHaveKey('attached');
    expect($syncStatus)->toHaveKey('detached');
    expect($syncStatus)->toHaveKey('updated');
});

it('will perform the given conversions when media is attached', function () {
    Queue::fake();

    $media = factory(Media::class)->create();

    $conversions = ['conversion'];

    $this->subject->attachMedia($media, 'default', $conversions);

    Queue::assertPushed(
        PerformConversions::class,
        function ($job) use ($media, $conversions) {
            return $media->is($job->getMedia())
                && empty(array_diff($conversions, $job->getConversions()));
        }
    );
});

it('will perform the conversions registered by the channel when media is attached', function () {
    Queue::fake();

    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media, $channel = 'converted-images');

    Queue::assertPushed(
        PerformConversions::class,
        function ($job) use ($media, $channel) {
            $conversions = $this->subject
                ->getMediaChannel($channel)
                ->getConversions();

            return $media->is($job->getMedia())
                && empty(array_diff($conversions, $job->getConversions()));
        }
    );
});

it('can retrieve all the media from the default channel', function () {
    $media = factory(Media::class, 2)->create();

    $this->subject->attachMedia($media);

    $defaultMedia = $this->subject->getMedia();

    expect($defaultMedia->count())->toEqual(2);
    expect($media->diff($defaultMedia))->toBeEmpty();
});

it('can retrieve all the media from the specified channel', function () {
    $media = factory(Media::class, 2)->create();

    $this->subject->attachMedia($media, 'gallery');

    $galleryMedia = $this->subject->getMedia('gallery');

    expect($galleryMedia->count())->toEqual(2);
    expect($media->diff($galleryMedia))->toBeEmpty();
});

it('can handle attempts to get media from an empty channel', function () {
    $media = $this->subject->getMedia();

    expect($media)->toBeInstanceOf(EloquentCollection::class);
    expect($media->isEmpty())->toBeTrue();
});

it('can retrieve the first media from the default channel', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media);

    $firstMedia = $this->subject->getFirstMedia();

    expect($firstMedia)->toBeInstanceOf(Media::class);
    expect($firstMedia->id)->toEqual($media->id);
});

it('can retrieve the first media from the specified channel', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media, 'gallery');

    $firstMedia = $this->subject->getFirstMedia('gallery');

    expect($firstMedia)->toBeInstanceOf(Media::class);
    expect($firstMedia->id)->toEqual($media->id);
});

it('will only retrieve media from the specified channel', function () {
    $defaultMedia = factory(Media::class)->create();
    $galleryMedia = factory(Media::class)->create();

    // Attach media to the default channel...
    $this->subject->attachMedia($defaultMedia->id);

    // Attach media to the gallery channel...
    $this->subject->attachMedia($galleryMedia->id, 'gallery');

    $allDefaultMedia = $this->subject->getMedia();
    $allGalleryMedia = $this->subject->getMedia('gallery');
    $firstGalleryMedia = $this->subject->getFirstMedia('gallery');

    expect($allDefaultMedia)->toHaveCount(1);
    expect($allDefaultMedia->first()->id)->toEqual($defaultMedia->id);

    expect($allGalleryMedia)->toHaveCount(1);
    expect($allGalleryMedia->first()->id)->toEqual($galleryMedia->id);
    expect($firstGalleryMedia->id)->toEqual($galleryMedia->id);
});

it('can retrieve the url of the first media item from the default channel', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media);

    $url = $this->subject->getFirstMediaUrl();

    expect($url)->toEqual($media->getUrl());
});

it('can retrieve the url of the first media item from the specified channel', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media, 'gallery');

    $url = $this->subject->getFirstMediaUrl('gallery');

    expect($url)->toEqual($media->getUrl());
});

it('can retrieve the converted image url of the first media item from the specified group', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media, 'gallery');

    $url = $this->subject->getFirstMediaUrl('gallery', 'conversion-name');

    expect($url)->toEqual($media->getUrl('conversion-name'));
});

it('can determine if there is media in the default channel', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media);

    expect($this->subject->hasMedia())->toBeTrue();
    expect($this->subject->hasMedia('empty'))->toBeFalse();
});

it('can determine if there is any media in the specified group', function () {
    $media = factory(Media::class)->create();

    $this->subject->attachMedia($media, 'gallery');

    expect($this->subject->hasMedia('gallery'))->toBeTrue();
    expect($this->subject->hasMedia())->toBeFalse();
});

it('can detach all the media', function () {
    $mediaOne = factory(Media::class)->create();
    $mediaTwo = factory(Media::class)->create();

    $this->subject->attachMedia($mediaOne);
    $this->subject->attachMedia($mediaTwo, 'gallery');

    $this->subject->detachMedia();

    expect($this->subject->media()->exists())->toBeFalse();
});

it('can detach specific media items', function () {
    $mediaOne = factory(Media::class)->create();
    $mediaTwo = factory(Media::class)->create();

    $this->subject->attachMedia([
        $mediaOne->id, $mediaTwo->id,
    ]);

    $this->subject->detachMedia($mediaOne);

    expect($this->subject->getMedia())->toHaveCount(1);
    expect($this->subject->getFirstMedia()->id)->toEqual($mediaTwo->id);
});

it('can detach all the media in a specified channel', function () {
    $mediaOne = factory(Media::class)->create();
    $mediaTwo = factory(Media::class)->create();

    $this->subject->attachMedia($mediaOne, 'one');
    $this->subject->attachMedia($mediaTwo, 'two');

    $this->subject->clearMediaChannel('one');

    expect($this->subject->hasMedia('one'))->toBeFalse();
    expect($this->subject->getMedia('two'))->toHaveCount(1);
    expect($this->subject->getFirstMedia('two')->id)->toEqual($mediaTwo->id);
});
