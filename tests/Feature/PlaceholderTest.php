<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Placeholders\PlaceholderGenerator;
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
        ->and($placeholder)->toStartWith('data:image/svg+xml;base64,');
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
        ->and($media->custom_properties['placeholder'])->toStartWith('data:image/svg+xml;base64,');
});

it('preserves user-provided custom properties alongside placeholder', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->withCustomProperties(['author' => 'Alice'])
        ->upload();

    expect($media->custom_properties)->toHaveKey('author')
        ->and($media->custom_properties['author'])->toEqual('Alice')
        ->and($media->custom_properties)->toHaveKey('placeholder');
});

it('placeholder size stays within a reasonable budget (< 4 KB)', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 2000, 2000))->upload();

    $placeholder = $media->getPlaceholder();

    expect(strlen($placeholder))->toBeLessThan(4096);
});

it('embeds the original viewBox and a tiny JPEG inside the SVG wrapper', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 1280, 720))->upload();

    $svg = base64_decode(substr($media->getPlaceholder(), strlen('data:image/svg+xml;base64,')));

    expect($svg)->toContain('viewBox="0 0 1280 720"')
        ->and($svg)->toContain('<image')
        ->and($svg)->toContain('data:image/jpeg;base64,');
});

it('persists original dimensions on every image upload regardless of placeholder.enabled', function () {
    Config::set('mediaman.placeholder.enabled', false);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 1024, 768))->upload();

    expect($media->custom_properties)->toHaveKey('dimensions')
        ->and($media->custom_properties['dimensions'])->toEqual(['width' => 1024, 'height' => 768]);
});

it('uses the PlaceholderGenerator bound in the container', function () {
    $stub = new class implements PlaceholderGenerator
    {
        public function generate(string $sourcePath, int $width, int $height): ?string
        {
            return 'data:image/svg+xml;base64,STUBBED';
        }
    };

    app()->instance(PlaceholderGenerator::class, $stub);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect($media->getPlaceholder())->toEqual('data:image/svg+xml;base64,STUBBED');
});

// --- getPictureHtml / getSimpleImgHtml integration ---

it('getSimpleImgHtml appends the placeholder as the smallest srcset entry', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml();

    expect($html)->toContain('srcset="')
        ->and($html)->toContain('data:image/svg+xml;base64,')
        ->and($html)->toContain(' 32w')
        ->and($html)->not->toContain('background-image:url(');
});

it('getSimpleImgHtml omits the placeholder when disabled globally', function () {
    Config::set('mediaman.placeholder.enabled', false);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml();

    expect($html)->not->toContain('data:image/svg+xml;base64,');
});

it('getSimpleImgHtml omits the placeholder when opted out per-call', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml(['placeholder' => false]);

    expect($html)->not->toContain('data:image/svg+xml;base64,')
        ->and($html)->not->toContain('placeholder');
});

it('getSimpleImgHtml preserves user-provided style untouched', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml(['style' => 'border-radius:8px']);

    expect($html)->toContain('style="border-radius:8px"')
        ->and($html)->toContain('data:image/svg+xml;base64,')
        ->and($html)->not->toContain('background-image:url(');
});

it('getPictureHtml appends the placeholder to the img srcset', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getPictureHtml();

    expect($html)->toContain('srcset="')
        ->and($html)->toContain('data:image/svg+xml;base64,')
        ->and($html)->toContain(' 32w')
        ->and($html)->not->toContain('background-image:url(');
});

it('getPictureHtml honors the placeholder=false opt-out', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getPictureHtml(['placeholder' => false]);

    expect($html)->not->toContain('data:image/svg+xml;base64,');
});

it('getPictureHtml appends the placeholder to every source srcset', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/320.webp', 'size' => 1],
        ['width' => 320, 'height' => 240, 'format' => 'jpg', 'path' => 'p', 'url' => '/320.jpg', 'size' => 1],
    ]);
    $media->save();

    $html = $media->getPictureHtml();

    // one per <source srcset> + one in the <img srcset> fallback
    expect($html)->toContain('<source')
        ->and(substr_count($html, 'data:image/svg+xml;base64,'))->toBeGreaterThanOrEqual(2);
});
