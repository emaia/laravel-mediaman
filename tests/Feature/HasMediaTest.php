<?php

use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->subject = Subject::create();
});

it('registers the media relationship', function () {
    expect($this->subject->media())->toBeInstanceOf(MorphToMany::class);
});

it('can attach media to the default channel', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media);

    $attachedMedia = $this->subject->media()->first();

    expect($media->id)->toEqual($attachedMedia->id)
        ->and($attachedMedia->pivot->channel)->toEqual('default');
});

it('can attach media to a named channel', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media, $channel = 'custom');

    $attachedMedia = $this->subject->media()->first();

    expect($attachedMedia->id)->toEqual($media->id)
        ->and($attachedMedia->pivot->channel)->toEqual($channel);
});

it('can attach a collection of media', function () {
    $media = Media::factory()->count(2)->create();

    $this->subject->attachMedia($media);

    $attachedMedia = $this->subject->media()->get();

    expect($attachedMedia)->toHaveCount(2)
        ->and($media->diff($attachedMedia))->toBeEmpty();

    $attachedMedia->each(
        function ($media) {
            expect($media->pivot->channel)->toEqual('default');
        }
    );
});

it('returns number of attached media when associating succeeds', function () {
    $media = Media::factory()->create();

    $attachedCount = $this->subject->attachMedia($media, 'custom');

    expect($attachedCount)->toEqual(1);
});

it('throws InvalidArgumentException when attaching a non-existent media id', function () {
    // syncMedia validates ids exist up-front in all three attach paths so
    // the error surfaces explicitly with the missing ids — no longer relies
    // on FK enforcement, which would silently create orphan pivot rows on
    // connections with foreign_key_constraints disabled.
    $this->subject->attachMedia(99999, 'custom');
})->throws(InvalidArgumentException::class);

it('returns number of detached media or null while disassociating', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media, 'custom');

    $detached = $this->subject->detachMedia($media);

    expect($detached)->toEqual(1);

    // try detaching a non-existing media record
    $detached = $this->subject->detachMedia(100);
    expect($detached)->toEqual(null);
});

it('can attach media and returns number of media attached', function () {
    $media = Media::factory()->create();

    $attachedCount = $this->subject->attachMedia($media);

    expect($attachedCount)->toEqual(1);

    $attachedMedia = $this->subject->media()->first();
    expect($attachedMedia->id)->toEqual($media->id);
});

it('can detach media and returns number of media detached', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    $detachedCount = $this->subject->detachMedia($media);

    expect($detachedCount)->toEqual(1)
        ->and($this->subject->media()->first())->toBeNull();
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

    expect($syncStatus)->toHaveKey('updated')
        ->and($syncStatus)->toHaveKey('attached')
        ->and($syncStatus)->toHaveKey('detached')
        ->and($syncStatus['attached'])->toEqual([$media2->id])
        ->and($syncStatus['detached'])->toEqual([$media1->id]);

    $syncStatus = $this->subject->syncMedia([]);
    // should detach all
    expect(count($syncStatus['detached']))->toEqual(1);
});

it('can sync collections for a media instance', function () {
    $media = Media::factory()->create();
    $collections = ['collection1', 'collection2'];

    $syncStatus = $media->syncCollections($collections);

    expect($syncStatus)->toHaveKey('attached')
        ->and($syncStatus)->toHaveKey('detached')
        ->and($syncStatus)->toHaveKey('updated');
});

it('will perform the given conversions when media is attached', function () {
    Queue::fake();

    $media = Media::factory()->create();

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

    $media = Media::factory()->create();

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
    $media = Media::factory()->count(2)->create();

    $this->subject->attachMedia($media);

    $defaultMedia = $this->subject->getMedia();

    expect($defaultMedia->count())->toEqual(2)
        ->and($media->diff($defaultMedia))->toBeEmpty();
});

it('can retrieve all the media from the specified channel', function () {
    $media = Media::factory()->count(2)->create();

    $this->subject->attachMedia($media, 'gallery');

    $galleryMedia = $this->subject->getMedia('gallery');

    expect($galleryMedia->count())->toEqual(2)
        ->and($media->diff($galleryMedia))->toBeEmpty();
});

it('can handle attempts to get media from an empty channel', function () {
    $media = $this->subject->getMedia();

    expect($media)->toBeInstanceOf(EloquentCollection::class)
        ->and($media->isEmpty())->toBeTrue();
});

it('can retrieve the first media from the default channel', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media);

    $firstMedia = $this->subject->getFirstMedia();

    expect($firstMedia)->toBeInstanceOf(Media::class)
        ->and($firstMedia->id)->toEqual($media->id);
});

it('can retrieve the first media from the specified channel', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media, 'gallery');

    $firstMedia = $this->subject->getFirstMedia('gallery');

    expect($firstMedia)->toBeInstanceOf(Media::class)
        ->and($firstMedia->id)->toEqual($media->id);
});

it('will only retrieve media from the specified channel', function () {
    $defaultMedia = Media::factory()->create();
    $galleryMedia = Media::factory()->create();

    // Attach media to the default channel...
    $this->subject->attachMedia($defaultMedia->id);

    // Attach media to the gallery channel...
    $this->subject->attachMedia($galleryMedia->id, 'gallery');

    $allDefaultMedia = $this->subject->getMedia();
    $allGalleryMedia = $this->subject->getMedia('gallery');
    $firstGalleryMedia = $this->subject->getFirstMedia('gallery');

    expect($allDefaultMedia)->toHaveCount(1)
        ->and($allDefaultMedia->first()->id)->toEqual($defaultMedia->id)
        ->and($allGalleryMedia)->toHaveCount(1)
        ->and($allGalleryMedia->first()->id)->toEqual($galleryMedia->id)
        ->and($firstGalleryMedia->id)->toEqual($galleryMedia->id);

});

it('can retrieve the url of the first media item from the default channel', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media);

    $url = $this->subject->getFirstMediaUrl();

    expect($url)->toEqual($media->getUrl());
});

it('can retrieve the url of the first media item from the specified channel', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media, 'gallery');

    $url = $this->subject->getFirstMediaUrl('gallery');

    expect($url)->toEqual($media->getUrl());
});

it('can retrieve the converted image url of the first media item from the specified group', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media, 'gallery');

    $url = $this->subject->getFirstMediaUrl('gallery', 'conversion-name');

    expect($url)->toEqual($media->getUrl('conversion-name'));
});

it('can determine if there is media in the default channel', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media);

    expect($this->subject->hasMedia())->toBeTrue()
        ->and($this->subject->hasMedia('empty'))->toBeFalse();
});

it('can determine if there is any media in the specified group', function () {
    $media = Media::factory()->create();

    $this->subject->attachMedia($media, 'gallery');

    expect($this->subject->hasMedia('gallery'))->toBeTrue()
        ->and($this->subject->hasMedia())->toBeFalse();
});

it('can detach all the media', function () {
    $mediaOne = Media::factory()->create();
    $mediaTwo = Media::factory()->create();

    $this->subject->attachMedia($mediaOne);
    $this->subject->attachMedia($mediaTwo, 'gallery');

    $this->subject->detachMedia();

    expect($this->subject->media()->exists())->toBeFalse();
});

it('can detach specific media items', function () {
    $mediaOne = Media::factory()->create();
    $mediaTwo = Media::factory()->create();

    $this->subject->attachMedia([
        $mediaOne->id, $mediaTwo->id,
    ]);

    $this->subject->detachMedia($mediaOne);

    expect($this->subject->getMedia())->toHaveCount(1)
        ->and($this->subject->getFirstMedia()->id)->toEqual($mediaTwo->id);
});

it('can detach all the media in a specified channel', function () {
    $mediaOne = Media::factory()->create();
    $mediaTwo = Media::factory()->create();

    $this->subject->attachMedia($mediaOne, 'one');
    $this->subject->attachMedia($mediaTwo, 'two');

    $this->subject->clearMediaChannel('one');

    expect($this->subject->hasMedia('one'))->toBeFalse()
        ->and($this->subject->getMedia('two'))->toHaveCount(1)
        ->and($this->subject->getFirstMedia('two')->id)->toEqual($mediaTwo->id);
});

it('syncMedia only affects the specified channel and preserves other channels', function () {
    $featuredMedia = Media::factory()->create();
    $galleryMedia1 = Media::factory()->create();
    $galleryMedia2 = Media::factory()->create();
    $newFeaturedMedia = Media::factory()->create();

    $this->subject->attachMedia($featuredMedia, 'featured-image');
    $this->subject->attachMedia([$galleryMedia1, $galleryMedia2], 'gallery');

    $syncResult = $this->subject->syncMedia($newFeaturedMedia, 'featured-image');

    expect($syncResult['attached'])->toEqual([$newFeaturedMedia->id])
        ->and($syncResult['detached'])->toEqual([$featuredMedia->id])
        ->and($this->subject->getMedia('featured-image'))->toHaveCount(1)
        ->and($this->subject->getFirstMedia('featured-image')->id)->toEqual($newFeaturedMedia->id)
        ->and($this->subject->getMedia('gallery'))->toHaveCount(2)
        ->and($this->subject->getMedia('gallery')->pluck('id')->toArray())
        ->toContain($galleryMedia1->id)
        ->toContain($galleryMedia2->id);

});

it('syncMedia with empty media only clears the specified channel', function () {
    $featuredMedia = Media::factory()->create();
    $galleryMedia = Media::factory()->create();

    $this->subject->attachMedia($featuredMedia, 'featured-image');
    $this->subject->attachMedia($galleryMedia, 'gallery');

    $this->subject->syncMedia([], 'featured-image');

    expect($this->subject->hasMedia('featured-image'))->toBeFalse()
        ->and($this->subject->hasMedia('gallery'))->toBeTrue()
        ->and($this->subject->getFirstMedia('gallery')->id)->toEqual($galleryMedia->id);

});

it('syncMedia treats a false value as detach-all', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    $result = $this->subject->syncMedia(false);

    expect($result['detached'])->toEqual([$media->id])
        ->and($this->subject->hasMedia())->toBeFalse();
});

it('syncMedia treats an empty collection as detach-all', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    $result = $this->subject->syncMedia(new EloquentCollection);

    expect($result['detached'])->toEqual([$media->id])
        ->and($this->subject->hasMedia())->toBeFalse();
});

it('syncMedia reports already-attached media in the updated key', function () {
    $existing = Media::factory()->create();
    $newMedia = Media::factory()->create();

    $this->subject->attachMedia($existing);

    $result = $this->subject->syncMedia([$existing->id, $newMedia->id]);

    expect($result['updated'])->toEqual([$existing->id])
        ->and($result['attached'])->toEqual([$newMedia->id])
        ->and($result['detached'])->toEqual([]);
});

it('returns null from attachMedia when nothing new was attached', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    // Re-attaching the same media should not count as new
    $result = $this->subject->attachMedia($media);

    expect($result)->toBeNull();
});

it('returns empty string from getFirstMediaUrl when no media is attached', function () {
    expect($this->subject->getFirstMediaUrl())->toEqual('')
        ->and($this->subject->getFirstMediaUrl('gallery'))->toEqual('');
});

it('returns empty string from getFirstMediaUrlWithFallback when no media', function () {
    expect($this->subject->getFirstMediaUrlWithFallback())->toEqual('');
});

it('returns the original url from getFirstMediaUrlWithFallback when conversion missing', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    $url = $this->subject->getFirstMediaUrlWithFallback(Media::DEFAULT_CHANNEL, 'thumbnail');

    expect($url)->toEqual($media->getUrl());
});

it('returns null from getFirstMediaConversionUrl when no media is attached', function () {
    expect($this->subject->getFirstMediaConversionUrl())->toBeNull();
});

it('returns null from getFirstMediaConversionUrl when conversion does not exist', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    expect($this->subject->getFirstMediaConversionUrl(Media::DEFAULT_CHANNEL, 'missing'))->toBeNull();
});

it('returns false from hasMediaConversion when no media is attached', function () {
    expect($this->subject->hasMediaConversion())->toBeFalse();
});

it('returns false from hasMediaConversion when conversion missing', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    expect($this->subject->hasMediaConversion(Media::DEFAULT_CHANNEL, 'never-registered'))->toBeFalse();
});

it('reads from a preloaded media relation when getMedia is called', function () {
    $mediaOne = Media::factory()->create();
    $mediaTwo = Media::factory()->create();

    $this->subject->attachMedia($mediaOne);
    $this->subject->attachMedia($mediaTwo, 'gallery');

    // Reload from the DB without the trait's in-memory cache
    $subject = $this->subject->fresh(['media']);

    // Trigger filter-by-channel path on a preloaded relation
    expect($subject->getMedia('default'))->toHaveCount(1)
        ->and($subject->getMedia('default')->first()->id)->toEqual($mediaOne->id)
        ->and($subject->getMedia('gallery'))->toHaveCount(1);
});

it('returns all media when getMedia is called with null channel on preloaded relation', function () {
    $mediaOne = Media::factory()->create();
    $mediaTwo = Media::factory()->create();

    $this->subject->attachMedia($mediaOne);
    $this->subject->attachMedia($mediaTwo, 'gallery');

    $subject = $this->subject->fresh(['media']);

    expect($subject->getMedia(null))->toHaveCount(2);
});

it('returns all channels media when getMedia is called with null channel without preload', function () {
    $mediaOne = Media::factory()->create();
    $mediaTwo = Media::factory()->create();

    $this->subject->attachMedia($mediaOne);
    $this->subject->attachMedia($mediaTwo, 'gallery');

    expect($this->subject->getMedia(null))->toHaveCount(2);
});
