<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;

it('can create a collection', function () {
    $collection = $this->mediaCollection::firstOrCreate([
        'name' => 'test-collection',
    ]);

    expect($collection->id)->toEqual(2)
        ->and($collection->name)->toEqual('test-collection');
});

it('can update a collection', function () {
    $collection = $this->mediaCollection::firstOrCreate([
        'name' => 'test-collection',
    ]);

    $collection->name = 'new name';
    $collection->save();
    expect($collection->name)->toEqual('new name');
});

it('can delete a collection with associated pivot table data', function () {
    $mediaOne = MediaUploader::source($this->fileOne)
        ->useName('images-1')
        ->useCollection('images')
        ->upload();
    $mediaTwo = MediaUploader::source($this->fileOne)
        ->useName('images-2')
        ->useCollection('images')
        ->upload();

    $collection = $this->mediaCollection::with('media')->findByName('images');
    expect($collection->media()->count())->toEqual(2);

    $isDeleted = $collection->delete();
    expect($isDeleted)->toEqual(true)
        ->and($mediaOne->collections()->count())->toEqual(0)
        ->and($mediaTwo->collections()->count())->toEqual(0);

});

it('can retrieve media of a collection', function () {
    MediaUploader::source($this->fileOne)
        ->useName('file-1')
        ->useCollection('images')
        ->upload();

    MediaUploader::source($this->fileTwo)
        ->useName('file-2')
        ->useCollection('images')
        ->upload();

    $imageCollection = $this->mediaCollection->findByName('images');

    $one = $imageCollection->media[0];
    $two = $imageCollection->media[1];

    expect($imageCollection->count())->toEqual(2)
        ->and($one->name)->toEqual('file-1')
        ->and($two->name)->toEqual('file-2');

});

it('can sync media of a collection', function () {
    $mediaOne = MediaUploader::source($this->fileOne)
        ->useName('video-1')
        ->useCollection('Videos')
        ->upload();

    $mediaTwo = MediaUploader::source($this->fileTwo)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();

    $mediaThree = MediaUploader::source($this->fileTwo)
        ->useName('image-2')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');
    expect($imageCollection->media()->count())->toEqual(2);

    // detach all media by boolean true
    $imageCollection->syncMedia(true);
    expect($imageCollection->media()->count())->toEqual(0);

    // sync media by media id, name or model object
    $imageCollection->syncMedia($mediaOne->id);
    expect($imageCollection->media()->count())->toEqual(1);
    $imageCollection->syncMedia($mediaTwo->name);
    expect($imageCollection->media()->count())->toEqual(1);
    $imageCollection->syncMedia($mediaThree);
    expect($imageCollection->media()->count())->toEqual(1);

    // detach all media by boolean false
    $imageCollection->syncMedia(false);
    expect($imageCollection->media()->count())->toEqual(0);

    // sync media by array of media id or name
    $imageCollection->syncMedia([$mediaTwo->id, $mediaThree->id]);
    expect($imageCollection->media()->count())->toEqual(2);
    $imageCollection->syncMedia([$mediaTwo->name, $mediaThree->name]);
    expect($imageCollection->media()->count())->toEqual(2);

    // sync media by collection of media models
    $allMedia = Media::all();
    $imageCollection->syncMedia($allMedia);
    expect($imageCollection->media()->count())->toEqual($allMedia->count());
    $imageCollection->syncMedia(collect([$mediaTwo, $mediaThree]));
    expect($imageCollection->media()->count())->toEqual(2);

    // detach all media by null value
    $imageCollection->syncMedia(null);
    expect($imageCollection->media()->count())->toEqual(0);

    $videoCollection = $this->mediaCollection->with('media')->findByName('Videos');
    expect($videoCollection->media()->count())->toEqual(1);

    // detach all media by empty-string
    $videoCollection->syncMedia('');
    expect($imageCollection->media()->count())->toEqual(0);

    // sync media with id
    $videoCollection->syncMedia($mediaOne->id);
    expect($videoCollection->media()->count())->toEqual(1);

    // detach all media by empty-array
    $videoCollection->syncMedia([]);
    expect($videoCollection->media()->count())->toEqual(0);
});

it('can attach media to a collection', function () {
    $mediaOne = MediaUploader::source($this->fileOne)
        ->useName('image-0')
        ->useCollection('Images')
        ->upload();

    $mediaTwo = MediaUploader::source($this->fileTwo)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();

    $mediaThree = MediaUploader::source($this->fileTwo)
        ->useName('image-2')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');
    expect($imageCollection->media()->count())->toEqual(3);
    $imageCollection->syncMedia([]);
    expect($imageCollection->media()->count())->toEqual(0);

    $imageCollection->attachMedia($mediaOne);
    $imageCollection->attachMedia($mediaTwo->id);
    $imageCollection->attachMedia($mediaThree->name);
    expect($imageCollection->media()->count())->toEqual(3);
    $imageCollection->syncMedia([]);
    expect($imageCollection->media()->count())->toEqual(0);

    $allMedia = Media::all();
    $imageCollection->attachMedia($allMedia);
    expect($imageCollection->media()->count())->toEqual($allMedia->count());

    $imageCollection->syncMedia([]);
    expect($imageCollection->media()->count())->toEqual(0);
    $imageCollection->attachMedia(collect([$mediaOne, $mediaTwo, $mediaThree]));
    expect($imageCollection->media()->count())->toEqual(3);

    $imageCollection->syncMedia([]);
    expect($imageCollection->media()->count())->toEqual(0);
    $imageCollection->attachMedia([$mediaOne->id]);
    $imageCollection->attachMedia([$mediaTwo->name]);
    expect($imageCollection->media()->count())->toEqual(2);
});

it('can detach media from a collection', function () {
    $mediaOne = MediaUploader::source($this->fileOne)
        ->useName('image-0')
        ->useCollection('Images')
        ->upload();

    $mediaTwo = MediaUploader::source($this->fileTwo)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();

    MediaUploader::source($this->fileTwo)
        ->useName('image-2')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');
    expect($imageCollection->media()->count())->toEqual(3);

    // detach all media by boolean true
    $imageCollection->detachMedia(true);
    expect($imageCollection->media()->count())->toEqual(0);

    $imageCollection->attachMedia($mediaOne);
    expect($imageCollection->media()->count())->toEqual(1);
    $imageCollection->detachMedia($mediaOne->id);
    expect($imageCollection->media()->count())->toEqual(0);

    $imageCollection->attachMedia([$mediaOne->id, $mediaTwo->id]);
    expect($imageCollection->media()->count())->toEqual(2);
    $imageCollection->detachMedia([$mediaOne->id, $mediaTwo->id]);
    expect($imageCollection->media()->count())->toEqual(0);

    $imageCollection->attachMedia([$mediaOne->name, $mediaTwo->name]);
    expect($imageCollection->media()->count())->toEqual(2);
    $imageCollection->detachMedia([$mediaOne->name, $mediaTwo->name]);
    expect($imageCollection->media()->count())->toEqual(0);

    $imageCollection->attachMedia(collect([$mediaOne, $mediaTwo]));
    expect($imageCollection->media()->count())->toEqual(2);
    $imageCollection->detachMedia(collect([$mediaOne, $mediaTwo]));
    expect($imageCollection->media()->count())->toEqual(0);

    $allMedia = Media::all();
    $imageCollection->attachMedia($allMedia);
    expect($imageCollection->media()->count())->toEqual($allMedia->count());
    $imageCollection->detachMedia($allMedia);
    expect($imageCollection->media()->count())->toEqual(0);
});

it('returns false for non existing or already attached media when attaching', function () {
    MediaUploader::source($this->fileOne)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');

    $a1 = $imageCollection->attachMedia(5);
    expect($a1)->toEqual(false);
    $a2 = $imageCollection->attachMedia([1, 7]);
    expect($a2)->toEqual(false);
});

it('returns number of attached media if at least one of these is existing media and not already attached when attaching', function () {
    MediaUploader::source($this->fileOne)
        ->useName('others-1')
        ->useCollection('Others')
        ->upload();
    MediaUploader::source($this->fileOne)
        ->useName('others-2')
        ->useCollection('Others')
        ->upload();
    MediaUploader::source($this->fileOne)
        ->useName('others-3')
        ->useCollection('Others')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')
        ->firstOrCreate(['name' => 'Images']);

    $a1 = $imageCollection->attachMedia(1);
    expect($a1)->toEqual(1);
    $a2 = $imageCollection->attachMedia([2, 3]);
    expect($a2)->toEqual(2);

    $imageCollection->syncMedia([1]);
    expect($imageCollection->media()->count())->toEqual(1);
    $a3 = $imageCollection->attachMedia([1, 2, 3]);
    expect($a3)->toEqual(2);
});

it('returns false if all are non existing or already detached media when detaching', function () {
    MediaUploader::source($this->fileOne)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');

    // if all are non-existing media, it will return false
    $b1 = $imageCollection->detachMedia(5);
    expect($b1)->toEqual(false);
    $b2 = $imageCollection->detachMedia([10, 15]);
    expect($b2)->toEqual(false);
});

it('returns number of detached media if at least one of these is existing attached media and not already detached when detaching', function () {
    MediaUploader::source($this->fileOne)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();
    MediaUploader::source($this->fileOne)
        ->useName('image-2')
        ->useCollection('Images')
        ->upload();
    MediaUploader::source($this->fileOne)
        ->useName('image-3')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');

    $b1 = $imageCollection->detachMedia(1);
    expect($b1)->toEqual(1);
    $b2 = $imageCollection->detachMedia([1, 2, 3, 10]);
    expect($b2)->toEqual(2);
});

it('returns false if it is a non existing media when synchronizing', function () {
    MediaUploader::source($this->fileOne)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');

    // if all are non-existing media, it will return false
    $b1 = $imageCollection->syncMedia(5);
    expect($b1)->toEqual(false);
});

it('returns detailed array when synchronizing with existing non existing and already attached media array', function () {
    MediaUploader::source($this->fileOne)
        ->useName('image-1')
        ->useCollection('Images')
        ->upload();

    $imageCollection = $this->mediaCollection->with('media')->findByName('Images');

    // all are non-existing
    $a1 = $imageCollection->syncMedia([10, 15]);
    $b1 = [
        'attached' => [],
        'detached' => [1],
        'updated' => [],
    ];
    expect($b1)->toEqual($a1);

    // existing / already attached media
    $a1 = $imageCollection->syncMedia([1, 2]);
    $b1 = [
        'attached' => [1],
        'detached' => [],
        'updated' => [],
    ];
    expect($a1)->toEqual($b1);
});
