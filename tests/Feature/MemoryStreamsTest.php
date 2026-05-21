<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Illuminate\Http\UploadedFile;
use Intervention\Image\ImageManager;

beforeEach(function () {
    $this->imageManager = app(ImageManager::class);
});

// -- WidthCalculator from-binary contract --------------------------------

it('BreakpointWidthCalculator computes widths from binary input', function () {
    $calculator = new BreakpointWidthCalculator($this->imageManager, [320, 640]);

    $path = tempnam(sys_get_temp_dir(), 'b1_').'.jpg';
    $this->imageManager->createImage(800, 600)->save($path);
    $binary = file_get_contents($path);
    @unlink($path);

    $widths = $calculator->calculateWidthsFromBinary($binary);

    expect($widths->toArray())->toContain(800) // original always included
        ->toContain(640)
        ->toContain(320);
});

it('FileSizeOptimizedWidthCalculator computes widths from binary input', function () {
    $calculator = new FileSizeOptimizedWidthCalculator($this->imageManager);

    $path = tempnam(sys_get_temp_dir(), 'b1_').'.jpg';
    $this->imageManager->createImage(1920, 1080)->save($path);
    $binary = file_get_contents($path);
    @unlink($path);

    $widths = $calculator->calculateWidthsFromBinary($binary);

    expect($widths->count())->toBeGreaterThan(0)
        ->and($widths->first())->toEqual(1920);
});

it('calculateWidthsFromFile produces the same result as calculateWidthsFromBinary', function () {
    $calculator = new BreakpointWidthCalculator($this->imageManager, [320, 640]);

    $path = tempnam(sys_get_temp_dir(), 'b1_').'.jpg';
    $this->imageManager->createImage(800, 600)->save($path);
    $binary = file_get_contents($path);

    $fromFile = $calculator->calculateWidthsFromFile($path)->toArray();
    $fromBinary = $calculator->calculateWidthsFromBinary($binary)->toArray();

    @unlink($path);

    expect($fromFile)->toEqual($fromBinary);
});

// -- Regression guard: no tempfiles created during typical generation ---

it('does not create tempfiles in sys_get_temp_dir during responsive generation', function () {
    $tempDir = sys_get_temp_dir();
    $pattern = $tempDir.'/mediaman_*';

    $before = glob($pattern) ?: [];

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, [
        'widths' => null, // forces width calculation path
        'formats' => ['jpg'],
    ]);

    $after = glob($pattern) ?: [];

    // No mediaman_* tempfiles should be left behind, and no new ones created
    $newlyCreated = array_diff($after, $before);
    expect($newlyCreated)->toBeEmpty();
});

it('does not create tempfiles when calculating image dimensions', function () {
    $tempDir = sys_get_temp_dir();
    $pattern = $tempDir.'/mediaman_*';

    $before = glob($pattern) ?: [];

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // Trigger the dimension calculation fallback path
    $width = $media->getImageWidth();
    $height = $media->getImageHeight();

    $after = glob($pattern) ?: [];

    expect($width)->toBeGreaterThan(0)
        ->and($height)->toBeGreaterThan(0)
        ->and(array_diff($after, $before))->toBeEmpty();
});
