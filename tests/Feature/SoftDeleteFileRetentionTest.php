<?php

use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Tests\Models\SoftDeletingMedia;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function useSoftDeletingMedia(): void
{
    Config::set('mediaman.models.media', SoftDeletingMedia::class);

    Schema::table(config('mediaman.tables.media'), function ($table) {
        $table->softDeletes();
    });
}

it('removes files on delete for media without soft deletes', function () {
    $media = MediaUploader::source($this->fileOne)
        ->useDisk('default')
        ->upload();

    expect(Storage::disk('default')->exists($media->getPath()))->toBeTrue();

    Event::fake([MediaDeleted::class]);

    $media->delete();

    Storage::disk('default')->assertMissing($media->getPath());
    Event::assertDispatched(MediaDeleted::class);
});

it('preserves files on soft delete', function () {
    useSoftDeletingMedia();

    $media = MediaUploader::source($this->fileOne)
        ->useDisk('default')
        ->upload();

    expect($media)->toBeInstanceOf(SoftDeletingMedia::class)
        ->and(Storage::disk('default')->exists($media->getPath()))->toBeTrue();

    Event::fake([MediaDeleted::class]);

    $media->delete();

    expect(Storage::disk('default')->exists($media->getPath()))->toBeTrue()
        ->and($media->trashed())->toBeTrue()
        ->and(SoftDeletingMedia::withTrashed()->find($media->id))->not->toBeNull();

    Event::assertNotDispatched(MediaDeleted::class);
});

it('removes files on force delete of soft deleting media', function () {
    useSoftDeletingMedia();

    $media = MediaUploader::source($this->fileOne)
        ->useDisk('default')
        ->upload();

    expect(Storage::disk('default')->exists($media->getPath()))->toBeTrue();

    Event::fake([MediaDeleted::class]);

    $media->forceDelete();

    Storage::disk('default')->assertMissing($media->getPath());

    expect(SoftDeletingMedia::withTrashed()->find($media->id))->toBeNull();
    Event::assertDispatched(MediaDeleted::class);
});

it('restores soft-deleted media with files still on disk', function () {
    useSoftDeletingMedia();

    $media = MediaUploader::source($this->fileOne)
        ->useDisk('default')
        ->upload();

    $path = $media->getPath();

    $media->delete();

    expect($media->trashed())->toBeTrue()
        ->and(Storage::disk('default')->exists($path))->toBeTrue();

    $media->restore();

    expect($media->trashed())->toBeFalse()
        ->and(Storage::disk('default')->exists($path))->toBeTrue()
        ->and(SoftDeletingMedia::find($media->id))->not->toBeNull();
});
