<?php

use Emaia\MediaMan\Exceptions\InvalidCopyTarget;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->subject = Subject::create();
});

// ─── Ordering: attachMedia(..., $order) + pivot persistence ─────────

it('persists the order argument from attachMedia in the pivot table', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();

    $this->subject->attachMedia($media, 'default', [], 5);

    $pivot = $this->subject->media()->wherePivot('media_id', $media->id)->first()->pivot;

    expect((int) $pivot->order_column)->toEqual(5);
});

it('auto-assigns sequential order when no order argument is given', function () {
    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    $this->subject->attachMedia($m1);
    $this->subject->attachMedia($m2);

    $media = $this->subject->getMedia();

    expect($media)->toHaveCount(2)
        ->and((int) $media[0]->pivot->order_column)->toEqual(0)
        ->and((int) $media[1]->pivot->order_column)->toEqual(1);
});

it('returns media ordered by order_column after individual attaches', function () {
    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();
    $m3 = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->upload();

    $this->subject->attachMedia($m1, 'default', [], 2);
    $this->subject->attachMedia($m2, 'default', [], 0);
    $this->subject->attachMedia($m3, 'default', [], 1);

    $ids = $this->subject->getMedia()->pluck('id')->all();

    expect($ids)->toEqual([$m2->id, $m3->id, $m1->id]);
});

it('batch attach with order argument uses it as starting offset', function () {
    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    $this->subject->attachMedia([$m1, $m2], 'default', [], 10);

    $orders = $this->subject->getMedia()->pluck('pivot.order_column')->map(fn ($o) => (int) $o)->all();

    expect($orders)->toEqual([10, 11]);
});

it('setMediaOrder reorders media in a channel', function () {
    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();
    $m3 = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->upload();

    $this->subject->attachMedia([$m1, $m2, $m3]);

    $this->subject->setMediaOrder([$m3->id, $m1->id, $m2->id]);

    $ids = $this->subject->getMedia()->pluck('id')->all();

    expect($ids)->toEqual([$m3->id, $m1->id, $m2->id]);
});

it('setMediaOrder throws when given an id not attached in the channel', function () {
    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();
    $orphan = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->upload();

    $this->subject->attachMedia([$m1, $m2]);

    $this->subject->setMediaOrder([$m1->id, $orphan->id]);
})->throws(InvalidArgumentException::class);

it('setMediaOrder does not affect other channels', function () {
    $fa = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $fb = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();
    $ga = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->upload();

    $this->subject->attachMedia([$fa, $fb], 'featured');
    $this->subject->attachMedia($ga, 'gallery');

    $this->subject->setMediaOrder([$fb->id, $fa->id], 'featured');

    $fIds = $this->subject->getMedia('featured')->pluck('id')->all();
    $gIds = $this->subject->getMedia('gallery')->pluck('id')->all();

    expect($fIds)->toEqual([$fb->id, $fa->id])
        ->and($gIds)->toEqual([$ga->id]);
});

// ─── Fallback URLs / Paths ──────────────────────────────────────────

it('returns fallback URL when channel has no media', function () {
    $this->subject->addMediaChannel('avatar')
        ->useFallbackUrl('/img/default-avatar.png');

    $url = $this->subject->getFirstMediaUrl('avatar');

    expect($url)->toEqual('/img/default-avatar.png');
});

it('returns per-conversion fallback URL', function () {
    $this->subject->addMediaChannel('avatar')
        ->useFallbackUrl('/img/default-avatar.png')
        ->useFallbackUrl('/img/thumb.png', 'thumb');

    $url = $this->subject->getFirstMediaUrl('avatar', 'thumb');

    expect($url)->toEqual('/img/thumb.png');
});

it('returns empty string when no fallback is set and no media', function () {
    $url = $this->subject->getFirstMediaUrl('empty-channel');

    expect($url)->toEqual('');
});

it('returns media URL instead of fallback when media exists', function () {
    $this->subject->addMediaChannel('avatar')
        ->useFallbackUrl('/img/default-avatar.png');

    $media = MediaUploader::source(UploadedFile::fake()->image('avatar.jpg'))->upload();
    $this->subject->attachMedia($media, 'avatar');

    $url = $this->subject->getFirstMediaUrl('avatar');

    expect($url)->not->toEqual('/img/default-avatar.png');
});

it('getFirstMediaPath returns fallback path when channel empty', function () {
    $this->subject->addMediaChannel('avatar')
        ->useFallbackPath('/tmp/default-avatar.png');

    $path = $this->subject->getFirstMediaPath('avatar');

    expect($path)->toEqual('/tmp/default-avatar.png');
});

it('getLastMediaUrl uses fallback when channel empty', function () {
    $this->subject->addMediaChannel('avatar')
        ->useFallbackUrl('/img/last-avatar.png');

    $url = $this->subject->getLastMediaUrl('avatar');

    expect($url)->toEqual('/img/last-avatar.png');
});

it('getLastMediaUrlWithFallback uses channel fallback when empty', function () {
    $this->subject->addMediaChannel('avatar')
        ->useFallbackUrl('/img/any-avatar.png');

    $url = $this->subject->getLastMediaUrlWithFallback('avatar', 'thumb');

    expect($url)->toEqual('/img/any-avatar.png');
});

it('getLastMediaPath returns fallback path when channel empty', function () {
    $this->subject->addMediaChannel('avatar')
        ->useFallbackPath('/tmp/last.png');

    $path = $this->subject->getLastMediaPath('avatar');

    expect($path)->toEqual('/tmp/last.png');
});

// ─── Media::copy() ───────────────────────────────────────────────────

it('Media::copy creates a new Media record with a new ID', function () {
    $original = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $copy = $original->copy($this->subject);

    expect($copy->id)->not->toEqual($original->id)
        ->and($copy->name)->toEqual($original->name)
        ->and($copy->file_name)->toEqual($original->file_name)
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($copy->getPath()))->toBeTrue();
});

it('Media::copy attaches the copy to the target model', function () {
    $original = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $copy = $original->copy($this->subject, 'featured');

    expect($this->subject->getMedia('featured'))->toHaveCount(1)
        ->and($this->subject->getFirstMedia('featured')->id)->toEqual($copy->id);
});

it('Media::copy preserves the original file', function () {
    $original = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $original->copy($this->subject);

    expect(Storage::disk(self::DEFAULT_DISK)->exists($original->getPath()))->toBeTrue();
});

it('Media::copy rebuilds a versioned responsive manifest for the target', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $original = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    app(ResponsiveImageGenerator::class)->generateResponsiveImages($original, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);
    $original->refresh();
    $generation = $original->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION);
    $sourcePath = $original->getResponsiveImages()->first()->path;
    $inactiveGeneration = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    Storage::disk($original->responsiveDisk())->put(
        $original->getDirectory()."/responsive/$inactiveGeneration/orphan.jpg",
        'orphan',
    );

    $copy = $original->copy($this->subject);
    $targetPath = $copy->getResponsiveImages()->first()->path;

    expect($copy->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION))->toBe($generation)
        ->and($copy->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK))->toBe($copy->responsiveDisk())
        ->and($targetPath)->toContain($copy->getDirectory()."/responsive/$generation/")
        ->and($targetPath)->not->toBe($sourcePath)
        ->and(Storage::disk($copy->responsiveDisk())->exists($targetPath))->toBeTrue()
        ->and(Storage::disk($copy->responsiveDisk())->exists(
            $copy->getDirectory()."/responsive/$inactiveGeneration/orphan.jpg"
        ))->toBeFalse();
});

it('Media::copy merges responsive metadata into freshly persisted target properties', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $original = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    app(ResponsiveImageGenerator::class)->generateResponsiveImages($original, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);
    $original->refresh();

    $eventDispatcher = clone Media::getEventDispatcher();

    try {
        Media::created(function (Media $created) use ($original): void {
            if ($created->getKey() === $original->getKey()) {
                return;
            }

            $properties = is_array($created->custom_properties) ? $created->custom_properties : [];
            $properties['application_state'] = ['current' => true];
            $created->newQuery()->whereKey($created->getKey())->update([
                'custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            ]);
        });

        $copy = $original->copy($this->subject);
    } finally {
        Media::setEventDispatcher($eventDispatcher);
    }

    expect($copy->getCustomProperty('application_state'))->toBe(['current' => true])
        ->and($copy->hasResponsiveImages())->toBeTrue();
});

it('Media::copy rolls back a malformed responsive manifest', function () {
    $original = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $original->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [[
        'width' => 320,
        'height' => 240,
        'format' => 'jpg',
        'path' => 'outside/responsive.jpg',
        'url' => '/outside/responsive.jpg',
        'size' => 1,
    ]])->save();
    $before = Media::query()->count();

    expect(fn () => $original->copy($this->subject))
        ->toThrow(RuntimeException::class, 'outside');

    expect(Media::query()->count())->toBe($before);
});

// ─── Media::attachTo() ───────────────────────────────────────────────

it('Media::attachTo attaches to target without duplicating', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $otherSubject = Subject::create();

    $media->attachTo($this->subject, 'gallery');

    expect($this->subject->getMedia('gallery'))->toHaveCount(1)
        ->and($this->subject->getFirstMedia('gallery')->id)->toEqual($media->id);
});

it('Media::attachTo can be chained', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $result = $media->attachTo($this->subject);

    expect($result)->toBe($media);
});

it('Media::copy copies to a different disk via fallback', function () {
    $original = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $copy = $original->copy($this->subject);

    expect(Storage::disk(self::DEFAULT_DISK)->exists($copy->getPath()))->toBeTrue()
        ->and($copy->disk)->toEqual(self::DEFAULT_DISK);
});

it('Media::copy throws InvalidCopyTarget when the target does not use HasMedia', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $media->copy(new stdClass);
})->throws(InvalidCopyTarget::class, 'Target model must use the HasMedia trait.');

it('Media::attachTo throws InvalidCopyTarget when the target does not use HasMedia', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $media->attachTo(new stdClass);
})->throws(InvalidCopyTarget::class, 'Target model must use the HasMedia trait.');
