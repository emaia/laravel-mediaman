<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

function getMediaPath($mediaId): string
{
    return $mediaId.'-'.md5($mediaId.config('app.key'));
}

it('can create a media record with media uploader', function () {
    // use api
    $mediaOne = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->useFileName('image.jpg')
        ->useCollection('one')
        ->useCollection('two')
        ->useDisk('default')
        ->useCustomProperties([
            'extraData' => 'extra data value',
            'additional_data' => 'additional data value',
            'something-else' => 'anything else?',
        ])
        ->upload();

    $fetch = Media::with('collections')->find($mediaOne->id);

    expect($mediaOne->id)->toEqual($fetch->id)
        ->and($mediaOne->name)->toEqual('image')
        ->and($mediaOne->file_name)->toEqual('image.jpg')
        ->and($mediaOne->disk)->toEqual('default')
        ->and($mediaOne->collections->first()->name)->toEqual('one')
        ->and($fetch->custom_properties['extraData'])->toEqual('extra data value')
        ->and($fetch->custom_properties['additional_data'])->toEqual('additional data value')
        ->and($fetch->custom_properties['something-else'])->toEqual('anything else?');

    // test disks

    // todo: support multiple disks
});

it('can update a media record', function () {
    $mediaOne = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->upload();

    expect($mediaOne->name)->toEqual('image');

    $mediaOne->name = 'new-name';
    $mediaOne->custom_properties = ['metadata' => 'file metadata'];
    $mediaOne->save();

    expect($mediaOne->name)->toEqual('new-name')
        ->and($mediaOne->custom_properties)->toEqual(['metadata' => 'file metadata']);

    // update data
    $mediaOne->custom_properties = [
        'metadata' => 'updated existing key data',
        'extra_data' => 'new extra data',
    ];
    $mediaOne->save();

    expect($mediaOne->custom_properties['extra_data'])->toEqual('new extra data')
        ->and($mediaOne->custom_properties['metadata'])->toEqual('updated existing key data');

    $mediaOne->custom_properties = [];
    $mediaOne->save();
    expect(count($mediaOne->custom_properties))->toEqual(0);

    // todo: make a fluent api like the following?
    // $mediaOne->rename('new-file')
    //     ->renameFile('new-file.ext')
    //     ->moveTo('disk')
    //     ->syncData(['new-data' => 'new new'])
    //     ->store();
});

it('can delete a media record', function () {
    $media = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->useDisk('default')
        ->upload();

    $mediaId = $media->id;
    $mediaFile = $media->file_name;
    $media->delete();

    expect(Media::find($mediaId))->toEqual(null)
        ->and(Storage::disk('default')->exists($mediaFile))->toEqual(false);
});

it('deletes media and related files from storage when media is deleted', function () {
    $media = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->useDisk('default')
        ->upload();
    $mediaFilePath = $media->getPath();

    $media->delete();

    expect(Media::find($media->id))->toBeNull();
    Storage::disk($media->disk)->assertMissing($mediaFilePath);
});

it('moves file to new disk on disk update', function () {
    $newDiskName = 'newValidDisk';
    Storage::fake($newDiskName);

    config()->set("filesystems.disks.$newDiskName", [
        'driver' => 'local',
        'root' => storage_path("app/$newDiskName"),
    ]);

    $media = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->useDisk('default')
        ->upload();

    $originalDisk = $media->disk;
    $originalPath = $media->getPath();

    $media->update(['disk' => $newDiskName]);

    Storage::disk($originalDisk)->assertMissing($originalPath);
    Storage::disk($newDiskName)->assertExists($media->getPath());
});

it('renames file in storage on filename update', function () {
    $media = MediaUploader::source($this->fileOne)
        ->useName('image')
        ->useDisk('default')
        ->upload();

    $oldPath = $media->getPath();
    $media->update(['file_name' => 'new_name']);
    $newPath = $media->getPath();

    Storage::disk($media->disk)->assertMissing($oldPath);
    Storage::disk($media->disk)->assertExists($newPath);
});

it('validates disk usability for valid disk', function () {
    Storage::fake('newValidDisk');

    config()->set('filesystems.disks.newValidDisk', [
        'driver' => 'local',
        'root' => storage_path('app/newValidDisk'),
    ]);

    expect(Media::ensureDiskUsability('newValidDisk'))->toBeNull();
});

it('throws exception for invalid disk in disk usability check', function () {
    $this->expectException(\InvalidArgumentException::class);

    Media::ensureDiskUsability('invalidDisk');
});

it('has an extension accessor', function () {
    $image = new Media;
    $image->file_name = 'image.png';

    $video = new Media;
    $video->file_name = 'video.mov';

    expect($image->extension)->toEqual('png')
        ->and($video->extension)->toEqual('mov');
});

it('has a type accessor', function () {
    $image = new Media;
    $image->mime_type = 'image/png';

    $video = new Media;
    $video->mime_type = 'video/mov';

    expect($image->type)->toEqual('image')
        ->and($video->type)->toEqual('video');
});

it('can determine its type', function () {
    $media = new Media;
    $media->mime_type = 'image/png';

    expect($media->isOfType('image'))->toBeTrue()
        ->and($media->isOfType('video'))->toBeFalse();
});

it('can get the path on disk to the file', function () {
    $media = new Media;
    $media->id = 1;
    $media->file_name = 'image.jpg';

    $path = getMediaPath($media->id);
    expect($media->getPath())->toEqual($path.'/image.jpg');
});

it('can get the path on disk to a converted image', function () {
    $media = new Media;
    $media->id = 1;
    $media->file_name = 'image.jpg';

    $path = getMediaPath($media->id);
    expect($media->getPath('thumbnail'))->toEqual($path.'/conversions/thumbnail/image.jpg');
});

it('can get the full path to the file', function () {
    $media = Mockery::mock(Media::class)->makePartial();

    $filesystem = Mockery::mock(Filesystem::class)->makePartial();

    // Assert filesystem calls a path with the correct path on disk...
    $filesystem->shouldReceive('path')->with($media->getPath())->once()->andReturn('path');

    $media->shouldReceive('filesystem')->once()->andReturn($filesystem);

    expect($media->getFullPath())->toEqual('path');
});

it('can get the full path to a converted image', function () {
    $media = Mockery::mock(Media::class)->makePartial();

    $filesystem = Mockery::mock(Filesystem::class)->makePartial();

    // Assert filesystem calls a path with the correct path on disk...
    $filesystem->shouldReceive('path')->with($media->getPath('thumbnail'))->once()->andReturn('path');

    $media->shouldReceive('filesystem')->once()->andReturn($filesystem);

    expect($media->getFullPath('thumbnail'))->toEqual('path');
});

it('can get the url to the file', function () {
    $media = Mockery::mock(Media::class)->makePartial();

    $filesystem = Mockery::mock(Filesystem::class)->makePartial();

    // Assert filesystem calls url with the correct path on disk...
    $filesystem->shouldReceive('url')->with($media->getPath())->once()->andReturn('url');

    $media->shouldReceive('filesystem')->once()->andReturn($filesystem);

    expect($media->getUrl())->toEqual('url');
});

it('can get the url to a converted image', function () {
    $media = Mockery::mock(Media::class)->makePartial();

    $filesystem = Mockery::mock(Filesystem::class)->makePartial();

    // Assert filesystem calls url with the correct path on disk...
    $filesystem->shouldReceive('url')->with($media->getPath('thumbnail'))->once()->andReturn('url');

    $media->shouldReceive('filesystem')->once()->andReturn($filesystem);

    expect($media->getUrl('thumbnail'))->toEqual('url');
});

it('can sync a collection by id', function () {
    $collection = $this->mediaCollection::firstOrCreate([
        'name' => 'Test Collection',
    ]);

    $media = $this->media;
    $media->id = 1;
    $media->syncCollections($collection->id);

    expect($media->collections()->count())->toEqual(1)
        ->and($media->collections[0]->name)->toEqual($collection->name);
});

it('can sync a collection by name', function () {
    $collection = $this->mediaCollection::firstOrCreate([
        'name' => 'Test Collection',
    ]);

    $media = $this->media;
    $media->id = 1;
    $media->syncCollections($collection->name);

    expect($media->collections()->count())->toEqual(1)
        ->and($media->collections[0]->name)->toEqual($collection->name);
});

it('can sync multiple collections by name', function () {
    $this->mediaCollection::firstOrCreate([
        'name' => 'Test Collection',
    ]);

    $media = $this->media;
    $media->id = 1;
    $media->syncCollections(['Default', 'Test Collection']);

    expect($media->collections()->count())->toEqual(2)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('Test Collection');
});

it('can sync multiple collections by id', function () {
    $this->mediaCollection::firstOrCreate([
        'name' => 'Test Collection',
    ]);

    $media = $this->media;
    $media->id = 1;
    $media->syncCollections([1, 2]);

    expect($media->collections()->count())->toEqual(2)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('Test Collection');
});

it('can attach a media to a collection using collection id', function () {
    $collection = $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $collectionTwo = $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);

    $media = MediaUploader::source($this->fileOne)->upload();

    $media->attachCollections($collection->id);
    $media->attachCollections($collectionTwo->id);

    expect($media->collections()->count())->toEqual(3)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('my-collection')
        ->and($media->collections[2]->name)->toEqual('another-collection');
});

it('can attach a media to a collection using collection name', function () {
    $collection = $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $collectionTwo = $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);

    $media = MediaUploader::source($this->fileOne)->upload();

    $media->attachCollections($collection->name);
    $media->attachCollections($collectionTwo->name);

    expect($media->collections()->count())->toEqual(3)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('my-collection')
        ->and($media->collections[2]->name)->toEqual('another-collection');
});

it('can attach a media to a collection using collection object', function () {
    $collection = $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $collectionTwo = $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);

    $media = MediaUploader::source($this->fileOne)->upload();

    $media->attachCollections($collection);
    $media->attachCollections($collectionTwo);

    expect($media->collections()->count())->toEqual(3)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('my-collection')
        ->and($media->collections[2]->name)->toEqual('another-collection');
});

it('can attach a media to multiple collections using collection ids', function () {
    $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);
    $collections = $this->mediaCollection->all();

    $media = MediaUploader::source($this->fileOne)->upload();

    $media->attachCollections([$collections[1]->id, $collections[2]->id]);

    expect($media->collections()->count())->toEqual(3)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('my-collection')
        ->and($media->collections[2]->name)->toEqual('another-collection');
});

it('can attach a media to multiple collections using collection names', function () {
    $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);
    $collections = $this->mediaCollection->all();

    $media = MediaUploader::source($this->fileOne)->upload();

    $media->attachCollections([$collections[1]->name, $collections[2]->name]);

    expect($media->collections()->count())->toEqual(3)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('my-collection')
        ->and($media->collections[2]->name)->toEqual('another-collection');
});

it('can attach a media to multiple collections using collection object', function () {
    $media = MediaUploader::source($this->fileOne)->upload();

    // detach all collections
    $media->syncCollections([]);
    expect($media->collections()->count())->toEqual(0);

    // create collections
    $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);

    // retrieve all collections
    $collections = $this->mediaCollection->all();
    expect($collections->count())->toEqual(3);

    // attach all collections
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3)
        ->and($media->collections[0]->name)->toEqual('Default')
        ->and($media->collections[1]->name)->toEqual('my-collection')
        ->and($media->collections[2]->name)->toEqual('another-collection');
});

it('can detach a media to a collection using collection id', function () {
    $collection = $this->mediaCollection::first();

    // default collection
    $media = MediaUploader::source($this->fileOne)->upload();
    // added to the default collection
    expect($media->collections()->count())->toEqual(1);

    $media->detachCollections($collection->id);
    expect($media->collections()->count())->toEqual(0);
});

it('can detach a collection from a media using collection name', function () {
    $collection = $this->mediaCollection::first();

    // default collection
    $media = MediaUploader::source($this->fileOne)->upload();
    // added to the default collection
    expect($media->collections()->count())->toEqual(1);

    $media->detachCollections($collection->name);
    expect($media->collections()->count())->toEqual(0);
});

it('can detach a collection from a media using collection object', function () {
    $collection = $this->mediaCollection::first();

    // default collection
    $media = MediaUploader::source($this->fileOne)->upload();
    // added to the default collection
    expect($media->collections()->count())->toEqual(1);

    $media->detachCollections($collection);
    expect($media->collections()->count())->toEqual(0);
});

it('can detach multiple collections from a media using collection ids', function () {
    $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);
    $collections = $this->mediaCollection->all();

    $media = MediaUploader::source($this->fileOne)->upload();

    $media->attachCollections([$collections[1]->id, $collections[2]->id]);
    expect($media->collections()->count())->toEqual(3);

    $media->detachCollections([$collections[0]->id, $collections[1]->id, $collections[2]->id]);
    expect($media->collections()->count())->toEqual(0);
});

it('can detach multiple collections from a media using collection names', function () {
    $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);

    $collections = $this->mediaCollection->all();
    expect($collections->count())->toEqual(3);

    // default collection
    $media = MediaUploader::source($this->fileOne)->upload();
    expect($media->collections()->count())->toEqual(1);

    // add to more collections
    $media->attachCollections([$collections[1]->name, $collections[2]->name]);
    expect($media->collections()->count())->toEqual(3);

    // detach from all collections
    $media->detachCollections([$collections[0]->id, $collections[1]->id, $collections[2]->id]);
    expect($media->collections()->count())->toEqual(0);
});

it('can remove collections if its bool null empty string or empty array with sync collection', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $collection = $this->mediaCollection->first();

    // sync with bool true resets back to zero collection
    $media->syncCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach a collection
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // sync with bool false resets back to zero collection
    $media->syncCollections(false);
    expect($media->collections()->count())->toEqual(0);

    // attach a collection again
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // sync with null resets back to zero collection
    $media->syncCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach a collection again
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // sync with empty-string resets back to zero collection
    $media->syncCollections('');
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // sync with empty-array resets back to zero collection
    $media->syncCollections([]);
    expect($media->collections()->count())->toEqual(0);
});

it('can detach multiple collections from a media using collection object', function () {
    $collections = $this->mediaCollection->all();
    expect($collections->count())->toEqual(1);

    // default collection
    $media = MediaUploader::source($this->fileOne)->upload();
    expect($media->collections()->count())->toEqual(1);

    // detach from all collections
    $media->detachCollections($collections);
    expect($media->collections()->count())->toEqual(0);
});

it('can remove collections if its bool null empty string or empty array with sync', function () {
    $media = MediaUploader::source($this->fileOne)->upload();

    // create collections
    $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);

    // retrieve all collections
    $collections = $this->mediaCollection->all();
    expect($collections->count())->toEqual(3)
        ->and($media->collections()->count())->toEqual(1);

    // sync with bool true resets back to zero collection
    $media->syncCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach all collections
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // sync with bool false resets back to zero collection
    $media->syncCollections(false);
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // sync with null resets back to zero collection
    $media->syncCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // sync with empty-string resets back to zero collection
    $media->syncCollections('');
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // sync with empty-array resets back to zero collection
    $media->syncCollections([]);
    expect($media->collections()->count())->toEqual(0);
});

it('can remove collections if its bool null empty string or empty array with detach collection', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $collection = $this->mediaCollection->first();

    // detach with bool true resets back to zero collection
    $media->detachCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach a collection
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // detach with bool false resets back to zero collection
    $media->detachCollections(false);
    expect($media->collections()->count())->toEqual(0);

    // attach a collection again
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // detach with null resets back to zero collection
    $media->detachCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach a collection again
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // detach with empty-string resets back to zero collection
    $media->detachCollections('');
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collection);
    expect($media->collections()->count())->toEqual(1);

    // detach with empty-array resets back to zero collection
    $media->detachCollections([]);
    expect($media->collections()->count())->toEqual(0);
});

it('can remove collections if its bool null empty string or empty array with detach collections', function () {
    $media = MediaUploader::source($this->fileOne)->upload();

    // create collections
    $this->mediaCollection::firstOrCreate(['name' => 'my-collection']);
    $this->mediaCollection::firstOrCreate(['name' => 'another-collection']);

    // retrieve all collections
    $collections = $this->mediaCollection->all();
    expect($collections->count())->toEqual(3)
        ->and($media->collections()->count())->toEqual(1);

    // detach with bool true resets back to zero collection
    $media->detachCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach all collections
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // detach with bool false resets back to zero collection
    $media->detachCollections(false);
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // detach with null resets back to zero collection
    $media->detachCollections(true);
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // detach with empty-string resets back to zero collection
    $media->detachCollections('');
    expect($media->collections()->count())->toEqual(0);

    // attach all collections again
    $media->attachCollections($collections);
    expect($media->collections()->count())->toEqual(3);

    // detach with empty-array resets back to zero collection
    $media->detachCollections([]);
    expect($media->collections()->count())->toEqual(0);
});

it('can check custom data existence', function () {
    $media = factory(Media::class)->create();
    expect($media->hasCustomProperty('color'))->tobeFalse();
});

it('can set custom data', function () {
    $media = factory(Media::class)->create();
    $media->setCustomProperty('color', 'blue');
    expect($media->hasCustomProperty('color'))->toBeTrue()
        ->and($media->getCustomProperty('color'))->toEqual('blue');
});

it('can forget custom data', function () {
    $media = factory(Media::class)->create();
    $media->setCustomProperty('color', 'blue');
    $media->setCustomProperty('size', 'small');
    $media->forgetCustomProperty('color');
    expect($media->hasCustomProperty('color'))->toBeFalse()
        ->and($media->hasCustomProperty('size'))->toBeTrue()
        ->and($media->getCustomProperty('size'))->toEqual('small');
});
