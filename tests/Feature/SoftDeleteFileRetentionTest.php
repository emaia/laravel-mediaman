<?php

use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
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

it('force deletes a soft-deleting copy when target attachment fails', function () {
    useSoftDeletingMedia();

    $media = MediaUploader::source($this->fileOne)
        ->useDisk('default')
        ->upload();
    $before = SoftDeletingMedia::withTrashed()->count();
    $target = new class
    {
        public function attachMedia(): void
        {
            throw new RuntimeException('attach failed');
        }
    };

    expect(fn () => $media->copy($target))->toThrow(RuntimeException::class, 'attach failed');

    expect(SoftDeletingMedia::withTrashed()->count())->toBe($before)
        ->and(Storage::disk('default')->exists($media->getPath()))->toBeTrue();
});

it('clears the responsive deletion claim when another listener cancels deletion', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $eventDispatcher = clone Media::getEventDispatcher();

    try {
        Media::deleting(fn () => false);

        expect($media->delete())->toBeFalse();
    } finally {
        Media::setEventDispatcher($eventDispatcher);
    }

    expect($media->fresh())->not->toBeNull()
        ->and($media->fresh()->hasCustomProperty(Media::PROPERTY_RESPONSIVE_DELETING))->toBeFalse();
});

it('does not clear a newer concurrent deletion claim', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $eventDispatcher = clone Media::getEventDispatcher();
    $newerToken = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    try {
        Media::deleting(function (Media $deleting) use ($newerToken): bool {
            $properties = is_array($deleting->fresh()->custom_properties)
                ? $deleting->fresh()->custom_properties
                : [];
            $properties[Media::PROPERTY_RESPONSIVE_DELETING] = [
                'token' => $newerToken,
                'started_at' => now()->toIso8601String(),
            ];
            $deleting->newQuery()->whereKey($deleting->getKey())->update([
                'custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            ]);

            return false;
        });

        expect($media->delete())->toBeFalse();
    } finally {
        Media::setEventDispatcher($eventDispatcher);
    }

    expect($media->fresh()->getCustomProperty(Media::PROPERTY_RESPONSIVE_DELETING.'.token'))->toBe($newerToken);
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
