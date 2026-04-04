<?php

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;

it('returns false for hasResponsiveImages when none generated', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->hasResponsiveImages())->toBeFalse();
});

it('returns empty collection for getResponsiveImages when none generated', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->getResponsiveImages())->toBeEmpty();
});

it('returns original for getAvailableResponsiveFormats when none generated', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->getAvailableResponsiveFormats())->toEqual(['original']);
});

it('returns true for hasResponsiveImages when data exists', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'test/640.webp', 'url' => '/test/640.webp', 'size' => 5000],
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'test/320.webp', 'url' => '/test/320.webp', 'size' => 2500],
    ]);
    $media->save();

    expect($media->hasResponsiveImages())->toBeTrue();
});

it('lists available formats', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 5000],
        ['width' => 640, 'height' => 480, 'format' => 'avif', 'path' => 'p', 'url' => '/p', 'size' => 3000],
    ]);
    $media->save();

    $formats = $media->getAvailableResponsiveFormats();
    expect($formats)->toContain('webp')
        ->toContain('avif');
});

it('prioritizes best responsive format correctly', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 5000],
        ['width' => 640, 'height' => 480, 'format' => 'jpg', 'path' => 'p', 'url' => '/p', 'size' => 8000],
    ]);
    $media->save();

    expect($media->getBestResponsiveFormat())->toEqual('webp');
});

it('prioritizes avif over webp', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 5000],
        ['width' => 640, 'height' => 480, 'format' => 'avif', 'path' => 'p', 'url' => '/p', 'size' => 3000],
    ]);
    $media->save();

    expect($media->getBestResponsiveFormat())->toEqual('avif');
});

it('returns original when no responsive images for getBestResponsiveFormat', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->getBestResponsiveFormat())->toEqual('original');
});

it('generates srcset string', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/test/640.webp', 'size' => 5000],
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/test/320.webp', 'size' => 2500],
    ]);
    $media->save();

    $srcset = $media->getSrcset('webp');
    expect($srcset)->toContain('640w')
        ->toContain('320w');
});

it('generates picture HTML with source elements', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/test/640.webp', 'size' => 5000],
        ['width' => 640, 'height' => 480, 'format' => 'jpg', 'path' => 'p', 'url' => '/test/640.jpg', 'size' => 8000],
    ]);
    $media->save();

    $html = $media->getPictureHtml();
    expect($html)->toContain('<picture>')
        ->toContain('<source')
        ->toContain('</picture>')
        ->toContain('<img');
});

it('generates simple img HTML', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $html = $media->getSimpleImgHtml();
    expect($html)->toContain('<img')
        ->toContain('src=');
});

it('returns empty string for picture HTML on non-image', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    expect($media->getPictureHtml())->toEqual('');
});

it('finds responsive image for width', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/test/640.webp', 'size' => 5000],
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/test/320.webp', 'size' => 2500],
        ['width' => 1024, 'height' => 768, 'format' => 'webp', 'path' => 'p', 'url' => '/test/1024.webp', 'size' => 10000],
    ]);
    $media->save();

    $image = $media->getResponsiveImageForWidth(500, 'webp');
    expect($image)->not->toBeNull()
        ->and($image->width)->toEqual(640);
});

it('returns null for getResponsiveImageForWidth when none generated', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->getResponsiveImageForWidth(500))->toBeNull();
});

it('checks hasResponsiveFormat correctly', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 5000],
    ]);
    $media->save();

    expect($media->hasResponsiveFormat('webp'))->toBeTrue()
        ->and($media->hasResponsiveFormat('avif'))->toBeFalse();
});

it('groups responsive images by format', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 5000],
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 2500],
        ['width' => 640, 'height' => 480, 'format' => 'avif', 'path' => 'p', 'url' => '/p', 'size' => 3000],
    ]);
    $media->save();

    $grouped = $media->getResponsiveImagesByFormatGrouped();
    expect($grouped)->toHaveKey('webp')
        ->toHaveKey('avif')
        ->and($grouped['webp'])->toHaveCount(2)
        ->and($grouped['avif'])->toHaveCount(1);
});

it('returns empty array for getResponsiveImagesByFormatGrouped when none exist', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->getResponsiveImagesByFormatGrouped())->toEqual([]);
});

it('converts format to mime type correctly', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // formatToMimeType is protected, test indirectly via getPictureHtml
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 5000],
        ['width' => 640, 'height' => 480, 'format' => 'jpg', 'path' => 'p', 'url' => '/p', 'size' => 8000],
    ]);
    $media->save();

    $html = $media->getPictureHtml();
    expect($html)->toContain('image/webp');
});

it('gets responsive url with fallback to original', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // Without responsive images, should return original URL
    $url = $media->getResponsiveUrl();
    expect($url)->toEqual($media->getUrl());
});

it('returns empty srcset for non-image media', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    expect($media->getSrcset())->toEqual('');
});
