<?php

use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    // Placeholder is opt-in; enable it explicitly for these tests.
    Config::set('mediaman.placeholder.enabled', true);
});

it('generates a placeholder data URI for image uploads', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $placeholder = $media->getPlaceholder();

    expect($placeholder)->not->toBeNull()
        ->and($placeholder)->toStartWith('data:image/jpeg;base64,');
});

it('does not generate a placeholder for non-image uploads', function () {
    $media = MediaUploader::source(UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))->upload();

    expect($media->getPlaceholder())->toBeNull();
});

it('skips placeholder generation when disabled in config', function () {
    Config::set('mediaman.placeholder.enabled', false);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect($media->getPlaceholder())->toBeNull();
});

it('stores the placeholder under custom_properties', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect($media->custom_properties)->toHaveKey('placeholder')
        ->and($media->custom_properties['placeholder'])->toStartWith('data:image/jpeg;base64,');
});

it('preserves user-provided custom properties alongside placeholder', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->withCustomProperties(['author' => 'Alice'])
        ->upload();

    expect($media->custom_properties)->toHaveKey('author')
        ->and($media->custom_properties['author'])->toEqual('Alice')
        ->and($media->custom_properties)->toHaveKey('placeholder');
});

// --- getUrlOrPlaceholder ---

it('getUrlOrPlaceholder returns the conversion URL when the file exists', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    // Without a conversion arg, returns the original URL
    $url = $media->getUrlOrPlaceholder();

    expect($url)->not->toStartWith('data:');
});

it('getUrlOrPlaceholder returns the placeholder when the conversion file is missing', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    // 'thumb' conversion never ran, file doesn't exist on disk
    $url = $media->getUrlOrPlaceholder('thumb');

    expect($url)->toStartWith('data:image/jpeg;base64,');
});

it('getUrlOrPlaceholder falls back to the URL when placeholder is also missing', function () {
    Config::set('mediaman.placeholder.enabled', false);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $url = $media->getUrlOrPlaceholder('thumb');

    expect($url)->not->toStartWith('data:');
});

it('placeholder size stays within a reasonable budget (< 4 KB)', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 2000, 2000))->upload();

    $placeholder = $media->getPlaceholder();

    expect(strlen($placeholder))->toBeLessThan(4096);
});

// --- getPictureHtml / getSimpleImgHtml integration ---

it('getSimpleImgHtml injects the placeholder as background-image when available', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml();

    expect($html)->toContain('background-image:url(')
        ->and($html)->toContain('data:image/jpeg;base64,');
});

it('getSimpleImgHtml omits the background when placeholder is disabled globally', function () {
    Config::set('mediaman.placeholder.enabled', false);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml();

    expect($html)->not->toContain('background-image:url(');
});

it('getSimpleImgHtml omits the background when opted out per-call', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml(['placeholder' => false]);

    expect($html)->not->toContain('background-image:url(')
        ->and($html)->not->toContain('placeholder');
});

it('getSimpleImgHtml merges placeholder into a user-provided style', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml(['style' => 'border-radius:8px']);

    expect($html)->toContain('border-radius:8px')
        ->and($html)->toContain('background-image:url(');
});

it('getPictureHtml injects the placeholder on the inner img tag', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getPictureHtml();

    expect($html)->toContain('background-image:url(')
        ->and($html)->toContain('data:image/jpeg;base64,');
});

it('getPictureHtml honors the placeholder=false opt-out', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getPictureHtml(['placeholder' => false]);

    expect($html)->not->toContain('background-image:url(');
});
