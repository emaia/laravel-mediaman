<?php

use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

function buildMediaWithResponsiveData(array $responsive): Media
{
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, $responsive);
    $media->save();

    return $media;
}

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

it('dispatches the job when responsive_images.queue is true', function () {
    config(['mediaman.responsive_images.queue' => true]);
    Queue::fake();

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->generateResponsiveImages(['quality' => 80]);

    Queue::assertPushed(GenerateResponsiveImages::class);
});

it('generates synchronously when responsive_images.queue is false', function () {
    config(['mediaman.responsive_images.queue' => false]);

    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('generateResponsiveImages')->once();
    app()->instance(ResponsiveImageGenerator::class, $generator);

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->generateResponsiveImages();
});

it('clears responsive images via the trait', function () {
    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('clearResponsiveImages')->once();
    app()->instance(ResponsiveImageGenerator::class, $generator);

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->clearResponsiveImages())->toBe($media);
});

it('returns simple img html when only original format is available', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $html = $media->getPictureHtml();
    expect($html)->toStartWith('<img ')
        ->not->toContain('<picture>');
});

it('escapes html attributes when building img tag', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();
    $media->name = 'Smith & "Co"';
    $media->save();

    $html = $media->getSimpleImgHtml();
    expect($html)->toContain('alt="Smith &amp; &quot;Co&quot;"');
});

it('returns empty string for getSimpleImgHtml on non-image', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    expect($media->getSimpleImgHtml())->toEqual('');
});

it('builds default img attributes with sizes auto', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/640.webp', 'size' => 1],
    ]);

    $attrs = $media->setDefaultImgAttributes('auto');

    expect($attrs)->toHaveKeys(['width', 'height', 'sizes'])
        ->and($attrs['width'])->toEqual(320)
        ->and($attrs['height'])->toEqual(240)
        ->and($attrs['sizes'])->toContain('px');
});

it('passes through explicit sizes string in setDefaultImgAttributes', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
    ]);

    $attrs = $media->setDefaultImgAttributes('(min-width: 800px) 50vw, 100vw');

    expect($attrs['sizes'])->toEqual('(min-width: 800px) 50vw, 100vw');
});

it('uses sizes attribute in picture source tags', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
        ['width' => 320, 'height' => 240, 'format' => 'jpg', 'path' => 'p', 'url' => '/320.jpg', 'size' => 1],
    ]);

    $html = $media->getPictureHtml([], '(min-width: 800px) 50vw, 100vw');

    expect($html)->toContain('sizes="(min-width: 800px) 50vw, 100vw"');
});

it('returns srcset with original width when no responsive images exist', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $srcset = $media->getSrcset('original');

    expect($srcset)->toContain($media->getUrl());
});

it('falls back to original srcset for format with no images', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
    ]);

    $srcset = $media->getSrcset('avif');

    expect($srcset)->toContain($media->getUrl());
});

it('selects empty format default in getSrcset', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
    ]);

    $srcset = $media->getSrcset();
    expect($srcset)->toContain('320w');
});

it('returns image width from custom property when responsive images absent', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();
    $media->setCustomProperty('width', 1280);
    $media->save();

    expect($media->getImageWidth())->toEqual(1280);
});

it('returns image height from custom property when responsive images absent', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();
    $media->setCustomProperty('height', 720);
    $media->save();

    expect($media->getImageHeight())->toEqual(720);
});

it('returns 0 for image dimensions on non-image media', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    expect($media->getImageWidth())->toEqual(0)
        ->and($media->getImageHeight())->toEqual(0);
});

it('returns image dimensions from responsive data', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
        ['width' => 800, 'height' => 600, 'format' => 'webp', 'path' => 'p', 'url' => '/800.webp', 'size' => 1],
    ]);

    expect($media->getImageWidth())->toEqual(800)
        ->and($media->getImageHeight())->toEqual(600);
});

it('reads dimensions from original file as fallback', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->getImageWidth())->toBeGreaterThan(0);
});

it('converts the tif alias to image/tiff', function () {
    // tif and an alphabetically-later unknown format ensure tif appears as a <source>
    // (sources = all sorted formats except the last, which becomes the <img> fallback)
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'tif', 'path' => 'p', 'url' => '/320.tif', 'size' => 1],
        ['width' => 320, 'height' => 240, 'format' => 'xyz', 'path' => 'p', 'url' => '/320.xyz', 'size' => 1],
    ]);

    $html = $media->getPictureHtml();

    expect($html)->toContain('image/tiff');
});

it('returns original url from getResponsiveUrl when no responsive images', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect($media->getResponsiveUrl(640, 'webp'))->toEqual($media->getUrl());
});

it('returns original url from getResponsiveUrl when format is original', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
    ]);

    expect($media->getResponsiveUrl(0, 'original'))->toEqual($media->getUrl());
});

it('returns largest responsive url when width=0', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
        ['width' => 800, 'height' => 600, 'format' => 'webp', 'path' => 'p', 'url' => '/800.webp', 'size' => 1],
    ]);

    expect($media->getResponsiveUrl(0, 'webp'))->toEqual('/800.webp');
});

it('returns optimal responsive url for a target width', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
        ['width' => 640, 'height' => 480, 'format' => 'webp', 'path' => 'p', 'url' => '/640.webp', 'size' => 1],
        ['width' => 1024, 'height' => 768, 'format' => 'webp', 'path' => 'p', 'url' => '/1024.webp', 'size' => 1],
    ]);

    expect($media->getResponsiveUrl(500, 'webp'))->toEqual('/640.webp');
});

it('falls back to original url when target width exceeds available widths', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
    ]);

    expect($media->getResponsiveUrl(2000, 'webp'))->toEqual($media->getUrl());
});

it('returns null from getResponsiveImageForWidth when no format is available', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, []);
    $media->save();

    expect($media->getResponsiveImageForWidth(640))->toBeNull();
});

it('returns getUrl for non-image when calling getResponsiveUrl', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    expect($media->getResponsiveUrl(500, 'webp'))->toEqual($media->getUrl());
});

it('falls back to img tag when sources cannot be built', function () {
    // Single non-priority format with no srcset (empty url) — degrades to plain <img>
    $media = buildMediaWithResponsiveData([
        ['width' => 0, 'height' => 0, 'format' => 'webp', 'path' => '', 'url' => '', 'size' => 0],
    ]);

    $html = $media->getPictureHtml();
    expect($html)->toStartWith('<img ')
        ->not->toContain('<picture>');
});

it('returns 0 dimensions when calculateImageDimensions fails', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    // Delete the file so decode() inside calculateImageDimensions throws
    $media->filesystem()->delete($media->getOriginalPath());

    expect($media->getImageWidth())->toEqual(0)
        ->and($media->getImageHeight())->toEqual(0);
});

it('uses image/<format> fallback for unknown extensions in formatToMimeType', function () {
    // tif and an alphabetically-later unknown format put 'xyz' as a <source>
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'xyz', 'path' => 'p', 'url' => '/320.xyz', 'size' => 1],
        ['width' => 320, 'height' => 240, 'format' => 'zzz', 'path' => 'p', 'url' => '/320.zzz', 'size' => 1],
    ]);

    $html = $media->getPictureHtml();

    expect($html)->toContain('image/xyz');
});

it('returns largest responsive url when getResponsiveUrl is called without args', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
        ['width' => 800, 'height' => 600, 'format' => 'webp', 'path' => 'p', 'url' => '/800.webp', 'size' => 1],
    ]);

    expect($media->getResponsiveUrl())->toEqual('/800.webp');
});

it('returns last available format when no preferred format matches', function () {
    // Custom non-priority formats; getBestResponsiveFormat returns the first sorted (alphabetical)
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'xyz', 'path' => 'p', 'url' => '/320.xyz', 'size' => 1],
    ]);

    expect($media->getBestResponsiveFormat())->toEqual('xyz');
});

it('returns null from getResponsiveImageForWidth when format is original', function () {
    $media = buildMediaWithResponsiveData([
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
    ]);

    expect($media->getResponsiveImageForWidth(640, 'original'))->toBeNull();
});

it('reads height from original file as fallback', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    expect($media->getImageHeight())->toBeGreaterThan(0);
});
