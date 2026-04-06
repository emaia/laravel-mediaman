<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Tests\Models\CustomMedia;
use Emaia\MediaMan\Tests\Models\CustomMediaCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('mediaman.models.media', CustomMedia::class);
    Config::set('mediaman.models.collection', CustomMediaCollection::class);
});

it('uploads media using custom media model', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(CustomMedia::class)
        ->and($media->getCustomFlag())->toBe('custom-media');
});

it('creates collections using custom collection model when uploading', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)
        ->useCollection('custom-test')
        ->upload();

    $collection = $media->collections->first();

    expect($collection)->toBeInstanceOf(CustomMediaCollection::class)
        ->and($collection->name)->toBe('custom-test')
        ->and($collection->getCustomFlag())->toBe('custom-collection');
});

it('resolves custom model in collections relationship', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)
        ->useCollection('test-collection')
        ->upload();

    $collections = $media->collections;

    expect($collections->first())->toBeInstanceOf(CustomMediaCollection::class);
});

it('resolves custom model in media relationship from collection', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)
        ->useCollection('test-collection')
        ->upload();

    $collection = CustomMediaCollection::where('name', 'test-collection')->first();
    $collectionMedia = $collection->media;

    expect($collectionMedia->first())->toBeInstanceOf(CustomMedia::class);
});

it('attaches and detaches collections with custom models', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $collection = CustomMediaCollection::firstOrCreate(['name' => 'attach-test']);
    $media->attachCollections($collection);

    expect($media->collections()->count())->toBeGreaterThanOrEqual(1);

    $media->detachCollections($collection);
});

it('syncs media on custom collection model', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $media = MediaUploader::source($file)->upload();

    $collection = CustomMediaCollection::firstOrCreate(['name' => 'sync-test']);
    $collection->syncMedia($media);

    expect($collection->media()->count())->toBe(1);

    $collection->syncMedia([]);

    expect($collection->media()->count())->toBe(0);
});
