<?php

use Emaia\MediaMan\Exceptions\MediaNotAcceptedByCollection;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Models\MediaCollection;
use Illuminate\Http\UploadedFile;

// ─── Fluent setters ────────────────────────────────────────────────

it('singleFile sets max_items to 1', function () {
    $collection = MediaCollection::create(['name' => 'avatars']);
    $collection->singleFile()->save();

    expect($collection->fresh()->max_items)->toEqual(1);
});

it('onlyKeepLatest sets max_items to the given count', function () {
    $collection = MediaCollection::create(['name' => 'gallery']);
    $collection->onlyKeepLatest(5)->save();

    expect($collection->fresh()->max_items)->toEqual(5);
});

it('acceptsMimeTypes sets allowed_mime_types', function () {
    $collection = MediaCollection::create(['name' => 'photos']);
    $collection->acceptsMimeTypes(['image/png', 'image/jpeg'])->save();

    expect($collection->fresh()->allowed_mime_types)->toEqual(['image/png', 'image/jpeg']);
});

// ─── MIME type validation (upload) ──────────────────────────────────

it('accepts a matching MIME type during upload', function () {
    MediaCollection::create(['name' => 'png-only'])
        ->acceptsMimeTypes(['image/png'])
        ->save();

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.png'))
        ->useCollection('png-only')
        ->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('rejects a non-matching MIME type during upload', function () {
    MediaCollection::create(['name' => 'png-only'])
        ->acceptsMimeTypes(['image/png'])
        ->save();

    MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->useCollection('png-only')
        ->upload();
})->throws(MediaNotAcceptedByCollection::class);

it('supports wildcard MIME type patterns in collection validation', function () {
    MediaCollection::create(['name' => 'images'])
        ->acceptsMimeTypes(['image/*'])
        ->save();

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->useCollection('images')
        ->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('empty allowed_mime_types accepts everything', function () {
    $collection = MediaCollection::create(['name' => 'open-collection']);
    $collection->acceptsMimeTypes([])->save();

    $media = MediaUploader::source(UploadedFile::fake()->image('anything.jpg'))
        ->useCollection('open-collection')
        ->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('null allowed_mime_types accepts everything', function () {
    $collection = MediaCollection::create(['name' => 'null-collection']);

    $media = MediaUploader::source(UploadedFile::fake()->image('anything.jpg'))
        ->useCollection('null-collection')
        ->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

// ─── MIME type validation (direct attach) ───────────────────────────

it('rejects direct attach of non-matching MIME type', function () {
    $collection = MediaCollection::create(['name' => 'png-gallery']);
    $collection->acceptsMimeTypes(['image/png'])->save();

    $jpgMedia = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->useCollection('different')
        ->upload();

    $collection->attachMedia($jpgMedia);
})->throws(MediaNotAcceptedByCollection::class);

it('accepts direct attach of matching MIME type', function () {
    $collection = MediaCollection::create(['name' => 'png-gallery']);
    $collection->acceptsMimeTypes(['image/png'])->save();

    $pngMedia = MediaUploader::source(UploadedFile::fake()->image('photo.png'))
        ->useCollection('different')
        ->upload();

    $result = $collection->attachMedia($pngMedia);

    expect($result)->toBeGreaterThan(0)
        ->and($collection->media()->count())->toEqual(1);
});

// ─── Auto-prune: onlyKeepLatest / singleFile ────────────────────────

it('onlyKeepLatest prunes oldest media exceeding the limit', function () {
    $collection = MediaCollection::create(['name' => 'limited'])
        ->onlyKeepLatest(3);
    $collection->save();

    $m1 = MediaUploader::source(UploadedFile::fake()->image('one.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('two.jpg'))->upload();
    $m3 = MediaUploader::source(UploadedFile::fake()->image('three.jpg'))->upload();
    $m4 = MediaUploader::source(UploadedFile::fake()->image('four.jpg'))->upload();
    $m5 = MediaUploader::source(UploadedFile::fake()->image('five.jpg'))->upload();

    $collection->attachMedia([$m1, $m2, $m3, $m4, $m5]);

    $attached = $collection->media()->get();

    expect($attached)->toHaveCount(3)
        ->and($attached->contains($m1))->toBeFalse()
        ->and($attached->contains($m2))->toBeFalse()
        ->and($attached->contains($m3))->toBeTrue()
        ->and($attached->contains($m4))->toBeTrue()
        ->and($attached->contains($m5))->toBeTrue();
});

it('singleFile keeps only the latest file', function () {
    $collection = MediaCollection::create(['name' => 'single'])
        ->singleFile();
    $collection->save();

    $first = MediaUploader::source(UploadedFile::fake()->image('first.jpg'))->upload();
    $second = MediaUploader::source(UploadedFile::fake()->image('second.jpg'))->upload();

    $collection->attachMedia([$first, $second]);

    $attached = $collection->media()->get();

    expect($attached)->toHaveCount(1)
        ->and($attached->first()->id)->toEqual($second->id);
});

it('skip auto-prune when max_items is null', function () {
    $collection = MediaCollection::create(['name' => 'unlimited']);

    $m1 = MediaUploader::source(UploadedFile::fake()->image('one.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('two.jpg'))->upload();

    $collection->attachMedia([$m1, $m2]);

    expect($collection->media()->count())->toEqual(2);
});

// ─── Auto-prune does NOT delete Media records ───────────────────────

it('auto-prune detaches but does not delete the Media record', function () {
    $collection = MediaCollection::create(['name' => 'pruned'])
        ->singleFile();
    $collection->save();

    $first = MediaUploader::source(UploadedFile::fake()->image('first.jpg'))->upload();
    $second = MediaUploader::source(UploadedFile::fake()->image('second.jpg'))->upload();

    $collection->attachMedia([$first, $second]);

    expect(Media::find($first->id))->not->toBeNull();
    expect(Media::find($second->id))->not->toBeNull();
    expect($collection->media()->count())->toEqual(1);
});

// ─── Upload with auto-prune ─────────────────────────────────────────

it('auto-prunes during upload when collection has max_items set', function () {
    $collection = MediaCollection::create(['name' => 'upload-limited'])
        ->onlyKeepLatest(2);
    $collection->save();

    MediaUploader::source(UploadedFile::fake()->image('a.jpg'))
        ->useCollection('upload-limited')->upload();
    MediaUploader::source(UploadedFile::fake()->image('b.jpg'))
        ->useCollection('upload-limited')->upload();
    MediaUploader::source(UploadedFile::fake()->image('c.jpg'))
        ->useCollection('upload-limited')->upload();

    expect($collection->fresh()->media()->count())->toEqual(2);
});

it('rejects upload when collection does not accept the MIME type', function () {
    MediaCollection::create(['name' => 'png-uploads'])
        ->acceptsMimeTypes(['image/png'])
        ->save();

    MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->useCollection('png-uploads')
        ->upload();
})->throws(MediaNotAcceptedByCollection::class);

it('auto-prunes after direct attach to a singleFile collection', function () {
    $collection = MediaCollection::create(['name' => 'direct-single'])
        ->singleFile();
    $collection->save();

    $first = MediaUploader::source(UploadedFile::fake()->image('first.jpg'))->upload();
    $second = MediaUploader::source(UploadedFile::fake()->image('second.jpg'))->upload();

    $collection->attachMedia($first);
    expect($collection->media()->count())->toEqual(1);

    $collection->attachMedia($second);
    expect($collection->media()->count())->toEqual(1)
        ->and($collection->media()->first()->id)->toEqual($second->id);
});

it('Media::collections returns the collections it belongs to', function () {
    $collection = MediaCollection::create(['name' => 'photos']);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->useCollection('photos')
        ->upload();

    $ids = $media->collections->pluck('id')->all();

    expect($ids)->toContain($collection->id);
});
