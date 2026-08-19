<?php

use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Tests\Models\SoftDeletingMedia;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

it('does not mutate responsive state when another listener cancels deletion', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');
    $media = MediaUploader::source($this->fileOne)->upload();
    $eventDispatcher = clone Media::getEventDispatcher();
    $properties = $media->custom_properties;

    try {
        Media::deleting(fn () => false);

        expect($media->delete())->toBeFalse();
    } finally {
        Media::setEventDispatcher($eventDispatcher);
    }

    expect(Media::query()->find($media->getKey()))->not->toBeNull()
        ->and($media->fresh()->custom_properties)->toBe($properties);
});

it('does not update legacy media solely to coordinate deletion', function () {
    Config::set('mediaman.responsive_images.versioning', false);
    $media = MediaUploader::source($this->fileOne)->upload();
    $eventDispatcher = clone Media::getEventDispatcher();
    $updates = 0;

    try {
        Media::updated(function () use (&$updates): void {
            $updates++;
        });

        $media->delete();
    } finally {
        Media::setEventDispatcher($eventDispatcher);
    }

    expect($updates)->toBe(0);
});

it('refreshes deletion state before applying the legacy fast path', function () {
    Config::set('mediaman.responsive_images.versioning', false);
    Storage::fake('retained-responsive');
    $media = MediaUploader::source($this->fileOne)->upload();
    $stale = $media->fresh();
    $generation = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $responsivePath = $media->getDirectory()."/responsive/$generation/photo.jpg";
    Storage::disk('retained-responsive')->put($responsivePath, 'variant');
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION, $generation)
        ->setCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK, 'retained-responsive')
        ->setCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISKS, ['retained-responsive'])
        ->save();

    $stale->delete();

    expect(Storage::disk('retained-responsive')->exists($responsivePath))->toBeFalse();
});

it('fails closed when hard delete overlaps path rotation', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $path = $media->getPath();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING, [
        'token' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'started_at' => now()->toIso8601String(),
    ])->save();

    expect(fn () => $media->delete())
        ->toThrow(RuntimeException::class, 'is rotating paths');

    expect(Media::query()->find($media->getKey()))->not->toBeNull()
        ->and(Storage::disk($media->disk)->exists($path))->toBeTrue();
});

it('releases the deletion row lock before filesystem cleanup', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $baselineTransactionLevel = DB::connection()->transactionLevel();
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('deleteDirectory')
        ->once()
        ->with($media->getDirectory())
        ->andReturnUsing(function () use ($baselineTransactionLevel): bool {
            expect(DB::connection()->transactionLevel())->toBe($baselineTransactionLevel);

            return true;
        });
    Storage::set($media->disk, $filesystem);

    $media->delete();
});

it('does not remove files when an outer deletion transaction rolls back', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $mediaId = $media->getKey();
    $path = $media->getPath();
    Event::fake([MediaDeleted::class]);

    try {
        DB::transaction(function () use ($media, $path): void {
            $media->delete();
            expect(Storage::disk($media->disk)->exists($path))->toBeTrue();

            throw new RuntimeException('roll back delete');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('roll back delete');
    }

    expect(Media::query()->find($mediaId))->not->toBeNull()
        ->and(Storage::disk($media->disk)->exists($path))->toBeTrue();
    Event::assertNotDispatched(MediaDeleted::class);
});

it('coordinates deleteQuietly cleanup without dispatching MediaDeleted', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $path = $media->getPath();
    Event::fake([MediaDeleted::class]);

    $media->deleteQuietly();

    Storage::disk($media->disk)->assertMissing($path);
    Event::assertNotDispatched(MediaDeleted::class);
});

it('coordinates forceDeleteQuietly cleanup without dispatching MediaDeleted', function () {
    useSoftDeletingMedia();
    $media = MediaUploader::source($this->fileOne)->upload();
    $path = $media->getPath();
    Event::fake([MediaDeleted::class]);

    $media->forceDeleteQuietly();

    Storage::disk($media->disk)->assertMissing($path);
    expect(SoftDeletingMedia::withTrashed()->find($media->getKey()))->toBeNull();
    Event::assertNotDispatched(MediaDeleted::class);
});

it('cleans files before a later deleted observer can fail', function () {
    $media = MediaUploader::source($this->fileOne)->upload();
    $mediaId = $media->getKey();
    $path = $media->getPath();
    $eventDispatcher = clone Media::getEventDispatcher();

    try {
        Media::deleted(fn () => throw new RuntimeException('observer failed'));

        expect(fn () => $media->delete())
            ->toThrow(RuntimeException::class, 'observer failed');
    } finally {
        Media::setEventDispatcher($eventDispatcher);
    }

    expect(Media::query()->find($mediaId))->toBeNull();
    Storage::disk($media->disk)->assertMissing($path);
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
