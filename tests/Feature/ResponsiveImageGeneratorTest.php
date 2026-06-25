<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;

beforeEach(function () {
    $this->generator = app(ResponsiveImageGenerator::class);

    // Disable global width clamps by default — individual tests opt-in
    // when they're specifically validating clamp behavior.
    config()->set('mediaman.responsive_images.min_width', 0);
    config()->set('mediaman.responsive_images.max_width', 0);
});

it('does nothing for non-image media', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media);

    expect($media->fresh()->hasResponsiveImages())->toBeFalse();
});

it('returns early when the original file is missing on disk', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // Remove the source file
    $media->filesystem()->delete($media->getOriginalPath());

    $this->generator->generateResponsiveImages($media);

    expect($media->fresh()->hasResponsiveImages())->toBeFalse();
});

it('uses custom widths from options', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [200, 400],
        'formats' => ['jpg'],
        'quality' => 80,
    ]);

    $responsive = $media->fresh()->getResponsiveImages();
    expect($responsive->pluck('width')->sort()->values()->toArray())->toEqual([200, 400]);
});

it('skips widths larger than the original image width', function () {
    $file = UploadedFile::fake()->image('test.jpg', 400, 300);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [200, 800, 1600],
        'formats' => ['jpg'],
    ]);

    $widths = $media->fresh()->getResponsiveImages()->pluck('width')->unique()->values()->toArray();
    expect($widths)->toEqual([200]);
});

it('generates png variants when png format requested', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [400],
        'formats' => ['png'],
    ]);

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->unique()->values()->toArray();
    expect($formats)->toEqual(['png']);
});

it('clears responsive images when directory exists', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [200],
        'formats' => ['jpg'],
    ]);

    $responsiveDir = $media->getDirectory().'/'.Media::RESPONSIVE_DIR;
    expect($media->filesystem()->exists($responsiveDir))->toBeTrue();

    $this->generator->clearResponsiveImages($media);

    expect($media->filesystem()->exists($responsiveDir))->toBeFalse()
        ->and($media->fresh()->hasResponsiveImages())->toBeFalse();
});

it('clears responsive images when directory does not exist', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // No responsive images generated yet — should still succeed
    $this->generator->clearResponsiveImages($media);

    expect($media->fresh()->hasResponsiveImages())->toBeFalse();
});

it('exposes a fluent setWidthCalculator', function () {
    $custom = new BreakpointWidthCalculator(app(ImageManager::class), [100, 200]);

    expect($this->generator->setWidthCalculator($custom))->toBe($this->generator);
});

it('uses the configured width calculator when widths option is omitted', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $calculator = Mockery::mock(WidthCalculator::class);
    $calculator->shouldReceive('calculateWidthsFromBinary')
        ->once()
        ->andReturn(collect([300]));

    $this->generator->setWidthCalculator($calculator);

    $this->generator->generateResponsiveImages($media, ['formats' => ['jpg']]);

    expect($media->fresh()->getResponsiveImages()->pluck('width')->unique()->toArray())->toEqual([300]);
});

it('generates heic/heif responsive variants when the driver supports it', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['heic', 'webp'],
    ]);

    $responsive = $media->fresh()->getResponsiveImages();
    $formats = $responsive->pluck('format')->toArray();

    expect($formats)->toContain('webp');

    if (in_array('heic', $formats)) {
        $heic = $responsive->firstWhere('format', 'heic');
        expect((int) $heic->width)->toBe(320);
        expect($heic->url)->toEndWith('.heic');
    }
});

it('generates jpg variants when jpg format requested', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);

    $responsive = $media->fresh()->getResponsiveImages();

    expect($responsive)->toHaveCount(1)
        ->and($responsive->first()->format)->toBe('jpg')
        ->and($responsive->first()->url)->toEndWith('.jpg');
});

it('skips a format when the encoder returns zero bytes (e.g. imagick without libheif)', function () {
    Log::spy();

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // Simulate a driver that "succeeds" with an empty payload — the silent-failure
    // mode of imagick without libheif when asked to encode HEIC.
    $emptyEncoded = Mockery::mock(EncodedImageInterface::class);
    $emptyEncoded->shouldReceive('__toString')->andReturn('');

    $image = Mockery::mock(ImageInterface::class);
    $image->shouldReceive('width')->andReturn(800);
    $image->shouldReceive('height')->andReturn(600);
    $image->shouldReceive('scaleDown')->andReturnSelf();
    $image->shouldReceive('encodeUsingFormat')->andReturn($emptyEncoded);
    $image->shouldReceive('__clone');

    $manager = Mockery::mock(ImageManager::class);
    $manager->shouldReceive('decode')->andReturn($image);

    $generator = new ResponsiveImageGenerator($manager, app(WidthCalculator::class));

    $generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['heic'],
    ]);

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->toArray();

    // Zero-byte HEIC was skipped — no garbage `.heic` file on disk, no entry in the
    // responsive_images metadata.
    expect($formats)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'Skipping responsive format')
            && ($context['format'] ?? null) === 'heic'
            && str_contains($context['error'] ?? '', 'zero bytes'));
});

it('skips unknown formats with a warning instead of falling back to JPEG bytes', function () {
    Log::spy();

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['webp', 'bogus'],
    ]);

    $responsive = $media->fresh()->getResponsiveImages();
    $formats = $responsive->pluck('format')->toArray();

    // webp succeeded, bogus was skipped — disk never got a `bogus`-extension file
    // carrying JPEG bytes (the regression this guards against).
    expect($formats)->toBe(['webp']);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'Skipping responsive format')
            && ($context['format'] ?? null) === 'bogus'
            && str_contains($context['error'] ?? '', 'Unsupported responsive format'));
});

// --- Global min_width / max_width clamps ---

it('drops widths below the global min_width clamp', function () {
    config()->set('mediaman.responsive_images.min_width', 500);

    $file = UploadedFile::fake()->image('photo.jpg', 1920, 1080);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [200, 400, 600, 1000],
        'formats' => ['jpg'],
    ]);

    $widths = $media->fresh()->getResponsiveImages()->pluck('width')->sort()->values()->toArray();

    expect($widths)->toEqual([600, 1000]);
});

it('drops widths above the global max_width clamp', function () {
    config()->set('mediaman.responsive_images.max_width', 800);

    $file = UploadedFile::fake()->image('photo.jpg', 1920, 1080);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320, 640, 1024, 1920],
        'formats' => ['jpg'],
    ]);

    $widths = $media->fresh()->getResponsiveImages()->pluck('width')->sort()->values()->toArray();

    expect($widths)->toEqual([320, 640]);
});

it('applies both min and max clamps simultaneously', function () {
    config()->set('mediaman.responsive_images.min_width', 400);
    config()->set('mediaman.responsive_images.max_width', 1000);

    $file = UploadedFile::fake()->image('photo.jpg', 1920, 1080);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [200, 500, 800, 1200, 1920],
        'formats' => ['jpg'],
    ]);

    $widths = $media->fresh()->getResponsiveImages()->pluck('width')->sort()->values()->toArray();

    expect($widths)->toEqual([500, 800]);
});

it('zero clamps mean no clamping', function () {
    config()->set('mediaman.responsive_images.min_width', 0);
    config()->set('mediaman.responsive_images.max_width', 0);

    $file = UploadedFile::fake()->image('photo.jpg', 1920, 1080);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320, 640, 1024, 1920],
        'formats' => ['jpg'],
    ]);

    $widths = $media->fresh()->getResponsiveImages()->pluck('width')->sort()->values()->toArray();

    expect($widths)->toEqual([320, 640, 1024, 1920]);
});
