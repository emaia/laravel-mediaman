<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->subject = Subject::create();

    $this->mediaA = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $this->mediaB = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();
    $this->mediaC = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->upload();

    $this->subject->attachMedia($this->mediaA->getKey(), 'default');
    $this->subject->attachMedia($this->mediaB->getKey(), 'gallery');
    $this->subject->attachMedia($this->mediaC->getKey(), 'gallery');
});

it('returns all media across channels when channel argument is null', function () {
    expect($this->subject->getMedia(null))->toHaveCount(3);
});

it('returns only default channel media when channel is "default"', function () {
    $defaultMedia = $this->subject->getMedia('default');

    expect($defaultMedia)->toHaveCount(1)
        ->and($defaultMedia->first()->getKey())->toBe($this->mediaA->getKey());
});

it('does not return all-channels result when getMedia(null) was cached first', function () {
    // Pre-fix bug: getMedia(null) cached its unfiltered result under the
    // 'default' key, so the next getMedia('default') call hit the cache and
    // returned media from every channel instead of just default.
    $all = $this->subject->getMedia(null);
    expect($all)->toHaveCount(3);

    $default = $this->subject->getMedia('default');

    expect($default)->toHaveCount(1)
        ->and($default->first()->getKey())->toBe($this->mediaA->getKey());
});

it('does not return default-only result when getMedia("default") was cached first', function () {
    // Mirror of the previous test: ensure caching the default-channel result
    // first does not pollute the subsequent all-channels lookup.
    $default = $this->subject->getMedia('default');
    expect($default)->toHaveCount(1);

    $all = $this->subject->getMedia(null);

    expect($all)->toHaveCount(3);
});

it('invalidates the all-channels cache when a single channel cache is cleared', function () {
    expect($this->subject->getMedia(null))->toHaveCount(3);

    // Detach via the public API used internally by attach/sync. After the
    // detach the cached all-channels view would otherwise stay stale (3 items)
    // even though only 2 remain on disk.
    $this->subject->detachMedia($this->mediaC);

    expect($this->subject->getMedia(null))->toHaveCount(2)
        ->and($this->subject->getMedia('gallery'))->toHaveCount(1);
});
