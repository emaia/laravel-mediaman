<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->subject = Subject::create();

    $media1 = MediaUploader::source(UploadedFile::fake()->image('first.jpg'))->upload();
    $media2 = MediaUploader::source(UploadedFile::fake()->image('second.jpg'))->upload();
    $media3 = MediaUploader::source(UploadedFile::fake()->image('third.jpg'))->upload();

    $this->subject->syncMedia([$media1->id, $media2->id, $media3->id]);
});

// --- getLastMedia ---

it('returns the last media item', function () {
    $last = $this->subject->getLastMedia();

    expect($last)->not->toBeNull()
        ->and($last->name)->toEqual('third');
});

it('getLastMedia returns null for empty channel', function () {
    $last = $this->subject->getLastMedia('other');

    expect($last)->toBeNull();
});

// --- getLastMediaUrl ---

it('returns the URL for the last media', function () {
    $url = $this->subject->getLastMediaUrl();

    expect($url)->toContain('third');
});

it('getLastMediaUrl returns empty string for empty channel', function () {
    $url = $this->subject->getLastMediaUrl('other');

    expect($url)->toEqual('');
});

it('getLastMediaUrl passes conversion to getUrl', function () {
    $url = $this->subject->getLastMediaUrl('default', 'thumb');

    expect($url)->not->toBeEmpty();
});

// --- getLastMediaUrlWithFallback ---

it('returns fallback URL for last media', function () {
    $url = $this->subject->getLastMediaUrlWithFallback();

    expect($url)->not->toBeEmpty()
        ->toContain('third');
});

it('getLastMediaUrlWithFallback returns empty string for empty channel', function () {
    $url = $this->subject->getLastMediaUrlWithFallback('other');

    expect($url)->toEqual('');
});

// --- getLastMediaConversionUrl ---

it('returns null when conversion does not exist', function () {
    $url = $this->subject->getLastMediaConversionUrl('default', 'nonexistent');

    expect($url)->toBeNull();
});

it('getLastMediaConversionUrl returns null for empty channel', function () {
    $url = $this->subject->getLastMediaConversionUrl('other', 'thumb');

    expect($url)->toBeNull();
});

// --- hasLastMediaConversion ---

it('returns false when conversion does not exist', function () {
    $exists = $this->subject->hasLastMediaConversion('default', 'nonexistent');

    expect($exists)->toBeFalse();
});

it('hasLastMediaConversion returns false for empty channel', function () {
    $exists = $this->subject->hasLastMediaConversion('other', 'thumb');

    expect($exists)->toBeFalse();
});

// --- Channel isolation ---

it('getLastMedia respects channel isolation', function () {
    $this->subject->attachMedia(
        MediaUploader::source(UploadedFile::fake()->image('avatar.jpg'))->upload(),
        'avatar'
    );

    $lastDefault = $this->subject->getLastMedia('default');
    $lastAvatar = $this->subject->getLastMedia('avatar');

    expect($lastDefault->name)->toEqual('third')
        ->and($lastAvatar->name)->toEqual('avatar');
});
