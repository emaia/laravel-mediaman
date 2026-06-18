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
        ->and($placeholder)->toStartWith('data:image/svg+xml,');
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
        ->and($media->custom_properties['placeholder'])->toStartWith('data:image/svg+xml,');
});

it('preserves user-provided custom properties alongside placeholder', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->withCustomProperties(['author' => 'Alice'])
        ->upload();

    expect($media->custom_properties)->toHaveKey('author')
        ->and($media->custom_properties['author'])->toEqual('Alice')
        ->and($media->custom_properties)->toHaveKey('placeholder');
});

// --- getUrlOrPlaceholder (single-URL helper for non-srcset contexts) ---

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

    expect($url)->toStartWith('data:image/svg+xml,');
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

it('embeds the original viewBox and a tiny JPEG inside the SVG wrapper', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 1280, 720))->upload();

    $svg = rawurldecode(substr($media->getPlaceholder(), strlen('data:image/svg+xml,')));

    expect($svg)->toContain('viewBox="0 0 1280 720"')
        ->and($svg)->toContain('<image')
        ->and($svg)->toContain('data:image/jpeg;base64,');
});

it('produces a srcset-safe data URI (no whitespace, no unescaped commas)', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 1280, 720))->upload();

    $uri = $media->getPlaceholder();

    // srcset terminates the URL at the first ASCII whitespace, and uses commas
    // as the candidate separator. Anything beyond the leading `data:…,` scheme
    // comma must be percent-encoded for the browser parser to keep the URI in
    // one piece.
    $body = substr($uri, strlen('data:image/svg+xml,'));

    expect($uri)->not->toMatch('/\s/')
        ->and($body)->not->toContain(',');
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
            return 'data:image/svg+xml,STUBBED';
        }
    };

    app()->instance(PlaceholderGenerator::class, $stub);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect($media->getPlaceholder())->toEqual('data:image/svg+xml,STUBBED');
});

// --- getPictureHtml / getSimpleImgHtml integration ---

it('getSimpleImgHtml appends the placeholder as the smallest srcset entry', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml();

    expect($html)->toContain('srcset="')
        ->and($html)->toContain('data:image/svg+xml,')
        ->and($html)->toContain(' 32w')
        ->and($html)->not->toContain('background-image:url(');
});

it('getSimpleImgHtml omits the placeholder when disabled globally', function () {
    Config::set('mediaman.placeholder.enabled', false);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml();

    expect($html)->not->toContain('data:image/svg+xml,');
});

it('getSimpleImgHtml omits the placeholder when opted out per-call', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml(['placeholder' => false]);

    expect($html)->not->toContain('data:image/svg+xml,')
        ->and($html)->not->toContain('placeholder');
});

it('getSimpleImgHtml preserves user-provided style untouched', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getSimpleImgHtml(['style' => 'border-radius:8px']);

    expect($html)->toContain('style="border-radius:8px"')
        ->and($html)->toContain('data:image/svg+xml,')
        ->and($html)->not->toContain('background-image:url(');
});

it('getPictureHtml appends the placeholder to the img srcset', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getPictureHtml();

    expect($html)->toContain('srcset="')
        ->and($html)->toContain('data:image/svg+xml,')
        ->and($html)->toContain(' 32w')
        ->and($html)->not->toContain('background-image:url(');
});

it('getPictureHtml honors the placeholder=false opt-out', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $html = $media->getPictureHtml(['placeholder' => false]);

    expect($html)->not->toContain('data:image/svg+xml,');
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
        ->and(substr_count($html, 'data:image/svg+xml,'))->toBeGreaterThanOrEqual(2);
});
