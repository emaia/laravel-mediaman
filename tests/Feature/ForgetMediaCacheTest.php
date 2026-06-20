<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->subject = Subject::create();

    $this->mediaA = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();
    $this->mediaB = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    $this->subject->attachMedia($this->mediaA->getKey(), 'default');
    $this->subject->attachMedia($this->mediaB->getKey(), 'gallery');
});

it('exposes a fluent forgetMediaCache that returns the model', function () {
    expect($this->subject->forgetMediaCache())->toBe($this->subject)
        ->and($this->subject->forgetMediaCache('gallery'))->toBe($this->subject);
});

it('forces the next getMedia call to hit the database when called without args', function () {
    // Prime the cache.
    $this->subject->getMedia('default');
    $this->subject->getMedia('gallery');

    $this->subject->forgetMediaCache();

    DB::enableQueryLog();

    $this->subject->getMedia('default');
    $this->subject->getMedia('gallery');

    // Two channel reads → two SELECTs against the pivot, proving the cache
    // was effectively cleared for both keys.
    expect(DB::getQueryLog())->toHaveCount(2);

    DB::disableQueryLog();
});

it('forces a refetch only for the targeted channel when called with one', function () {
    // Prime caches for both channels.
    $this->subject->getMedia('default');
    $this->subject->getMedia('gallery');

    $this->subject->forgetMediaCache('gallery');

    DB::enableQueryLog();

    $this->subject->getMedia('default');  // still cached → no SELECT
    $this->subject->getMedia('gallery');  // cleared → 1 SELECT

    expect(DB::getQueryLog())->toHaveCount(1);

    DB::disableQueryLog();
});

it('surfaces fresh data after an external sync-driver job mutates the pivot', function () {
    // Prime: 1 item in gallery.
    expect($this->subject->getMedia('gallery'))->toHaveCount(1);

    // Simulate an external mutation (queued job on sync driver, another
    // process sharing the same Eloquent instance, manual DB write…). The
    // cache is now stale.
    $mediaC = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->upload();

    DB::table(config('mediaman.tables.mediables'))->insert([
        'mediable_type' => $this->subject->getMorphClass(),
        'mediable_id' => $this->subject->getKey(),
        'media_id' => $mediaC->getKey(),
        'channel' => 'gallery',
        'order_column' => 1,
    ]);

    // Without invalidation the cache returns the stale single-item snapshot.
    expect($this->subject->getMedia('gallery'))->toHaveCount(1);

    // After forgetMediaCache the next read sees the externally-inserted row.
    $this->subject->forgetMediaCache('gallery');

    expect($this->subject->getMedia('gallery'))->toHaveCount(2);
});
