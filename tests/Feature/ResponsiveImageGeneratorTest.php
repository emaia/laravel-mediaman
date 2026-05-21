<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;

beforeEach(function () {
    $this->generator = app(ResponsiveImageGenerator::class);
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
