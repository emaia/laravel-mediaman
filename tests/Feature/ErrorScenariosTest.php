<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// --- Media Model Errors ---

it('handles deleting media when file no longer exists on disk', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    // Manually delete the file from disk
    $media->filesystem()->deleteDirectory($media->getDirectory());

    // Deleting the model should not throw
    $media->delete();

    expect(Media::find($media->id))->toBeNull();
});

it('returns correct url even if file is missing on disk', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    // getUrl should not throw even if the file doesn't physically exist
    $url = $media->getUrl();
    expect($url)->toBeString();
});

it('returns null for detectConversionFormat with unregistered conversion', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    // hasConversion checks file existence, should return false for unregistered conversion
    expect($media->hasConversion('nonexistent-conversion'))->toBeFalse();
});

it('returns default value for missing custom property', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    expect($media->getCustomProperty('nonexistent'))->toBeNull()
        ->and($media->getCustomProperty('nonexistent', 'fallback'))->toEqual('fallback')
        ->and($media->hasCustomProperty('nonexistent'))->toBeFalse();
});

it('handles getConversionUrl for non-existent conversion', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    expect($media->getConversionUrl('nonexistent'))->toBeNull();
});

it('handles getUrlWithFallback for non-existent conversion', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    // Should fall back to original URL
    $url = $media->getUrlWithFallback('nonexistent');
    expect($url)->toEqual($media->getUrl());
});

// --- HasMedia Errors ---

it('handles attachMedia with empty array', function () {
    $subject = Subject::create();

    $result = $subject->attachMedia([]);
    expect($result)->toBeNull();
});

it('handles detachMedia when nothing is attached', function () {
    $subject = Subject::create();

    $result = $subject->detachMedia();
    expect($result)->toBeNull();
});

it('handles clearMediaChannel on empty channel', function () {
    $subject = Subject::create();

    // Should not throw
    $subject->clearMediaChannel('nonexistent');

    expect($subject->getMedia('nonexistent'))->toBeEmpty();
});

it('handles syncMedia with bool false to detach all', function () {
    $subject = Subject::create();
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    $subject->attachMedia($media);
    expect($subject->hasMedia())->toBeTrue();

    $result = $subject->syncMedia(false);
    expect($result['detached'])->not->toBeEmpty();
});

it('handles syncMedia with empty array to detach all', function () {
    $subject = Subject::create();
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    $subject->attachMedia($media);

    $result = $subject->syncMedia([]);
    expect($result['detached'])->not->toBeEmpty();
});

it('handles syncMedia with null to detach all', function () {
    $subject = Subject::create();
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    $subject->attachMedia($media);

    $result = $subject->syncMedia(null);
    expect($result['detached'])->not->toBeEmpty();
});

// --- Responsive Image Errors ---

it('returns empty string for getSrcset on non-image media', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    expect($media->getSrcset())->toEqual('');
});

it('returns empty string for getPictureHtml on non-image media', function () {
    $file = UploadedFile::fake()->create('doc.txt', 50, 'text/plain');
    $media = MediaUploader::source($file)->upload();

    expect($media->getPictureHtml())->toEqual('');
});

it('returns empty string for getSimpleImgHtml on non-image media', function () {
    $file = UploadedFile::fake()->create('doc.txt', 50, 'text/plain');
    $media = MediaUploader::source($file)->upload();

    expect($media->getSimpleImgHtml())->toEqual('');
});

it('returns zero for getImageWidth on non-image media', function () {
    $file = UploadedFile::fake()->create('doc.txt', 50, 'text/plain');
    $media = MediaUploader::source($file)->upload();

    expect($media->getImageWidth())->toEqual(0);
});

it('returns zero for getImageHeight on non-image media', function () {
    $file = UploadedFile::fake()->create('doc.txt', 50, 'text/plain');
    $media = MediaUploader::source($file)->upload();

    expect($media->getImageHeight())->toEqual(0);
});

it('returns original url for getResponsiveUrl on non-image', function () {
    $file = UploadedFile::fake()->create('doc.txt', 50, 'text/plain');
    $media = MediaUploader::source($file)->upload();

    expect($media->getResponsiveUrl())->toEqual($media->getUrl());
});

it('handles hasResponsiveFormat when no responsive images exist', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $media = MediaUploader::source($file)->upload();

    // 'original' is always in the format list
    expect($media->hasResponsiveFormat('webp'))->toBeFalse()
        ->and($media->hasResponsiveFormat('original'))->toBeTrue();
});

// --- Upload Edge Cases ---

it('uploads files with special characters in name', function () {
    $file = UploadedFile::fake()->image('file with spaces & symbols#1.jpg');
    $media = MediaUploader::source($file)->upload();

    expect($media->file_name)->not->toContain(' ')
        ->not->toContain('#');
});

it('handles uploading a non-image file correctly', function () {
    $file = UploadedFile::fake()->create('report.pdf', 200, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->mime_type)->toEqual('application/pdf')
        ->and($media->isOfType('image'))->toBeFalse();
});
