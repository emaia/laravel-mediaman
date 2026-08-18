<?php

use Emaia\MediaMan\Exceptions\MediaFileWriteFailed;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

it('clears versioned metadata, increments the epoch, and removes the persisted generation disk', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');
    Storage::fake('responsive-a');
    Storage::fake('responsive-b');
    Config::set('mediaman.responsive_images.disk', 'responsive-a');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);
    $responsiveDir = $media->getDirectory().'/responsive';
    expect(Storage::disk('responsive-a')->exists($responsiveDir))->toBeTrue();

    Config::set('mediaman.responsive_images.disk', 'responsive-b');
    $this->generator->clearResponsiveImages($media);

    $fresh = $media->fresh();

    expect($fresh->hasResponsiveImages())->toBeFalse()
        ->and($fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION))->toBeFalse()
        ->and($fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK))->toBeFalse()
        ->and($fresh->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH))->toBe(1)
        ->and(Storage::disk('responsive-a')->exists($responsiveDir))->toBeFalse();
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

it('preserves the exact legacy path when generation versioning is disabled', function () {
    Config::set('mediaman.responsive_images.versioning', false);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);

    $fresh = $media->fresh();
    $item = $fresh->getResponsiveImages()->first();

    expect($item->path)->toBe($media->getDirectory().'/responsive/photo_320w.jpg')
        ->and($fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION))->toBeFalse()
        ->and($fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK))->toBeFalse();
});

it('publishes every variant beneath one generation directory', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320, 640],
        'formats' => ['jpg', 'webp'],
    ]);

    $fresh = $media->fresh();
    $generation = $fresh->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION);
    $paths = $fresh->getResponsiveImages()->pluck('path');

    expect($generation)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($fresh->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_DISK))->toBe($media->responsiveDisk())
        ->and($paths)->toHaveCount(4)
        ->and($paths->every(fn ($path) => str_contains($path, "/responsive/$generation/")))->toBeTrue()
        ->and($paths->every(fn ($path) => Storage::disk($media->responsiveDisk())->exists($path)))->toBeTrue()
        ->and(Storage::disk($media->responsiveDisk())->exists(
            $media->getDirectory()."/responsive/$generation/".ResponsiveImageGenerator::IN_PROGRESS_MARKER
        ))->toBeFalse();
});

it('keeps the previous generation readable after regeneration', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $options = ['widths' => [320], 'formats' => ['jpg']];

    $this->generator->generateResponsiveImages($media, $options);
    $first = $media->fresh();
    $firstGeneration = $first->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION);
    $firstPath = $first->getResponsiveImages()->first()->path;

    $this->generator->generateResponsiveImages($media, $options);
    $second = $media->fresh();
    $secondGeneration = $second->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION);

    expect($secondGeneration)->not->toBe($firstGeneration)
        ->and($second->getResponsiveImages()->first()->path)->toContain("/responsive/$secondGeneration/")
        ->and(Storage::disk($media->responsiveDisk())->exists($firstPath))->toBeTrue();
});

it('merges the manifest into fresh custom properties without saving unrelated dirty attributes', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $originalName = $media->name;
    $concurrent = $media->fresh();
    $concurrent->setCustomProperty('application_state', ['current' => true])->save();
    $media->name = 'unsaved dirty name';

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);

    $fresh = $media->fresh();

    expect($fresh->name)->toBe($originalName)
        ->and($fresh->getCustomProperty('application_state'))->toBe(['current' => true])
        ->and($fresh->hasResponsiveImages())->toBeTrue()
        ->and($media->name)->toBe($originalName);
});

it('publishes safely for legacy rows with null custom properties', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $media->newQuery()->whereKey($media->getKey())->update(['custom_properties' => null]);
    $media->refresh();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);

    expect($media->fresh()->hasResponsiveImages())->toBeTrue();
});

it('preserves the active generation when a replacement produces no variants', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]);
    $active = $media->fresh();
    $activeGeneration = $active->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION);
    $activePath = $active->getResponsiveImages()->first()->path;

    expect(fn () => $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['bogus'],
    ]))->toThrow(RuntimeException::class, 'produced no variants');

    $fresh = $media->fresh();
    $directories = Storage::disk($media->responsiveDisk())->directories($media->getDirectory().'/responsive');

    expect($fresh->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION))->toBe($activeGeneration)
        ->and($fresh->getResponsiveImages()->first()->path)->toBe($activePath)
        ->and($directories)->toBe([$media->getDirectory()."/responsive/$activeGeneration"]);
});

it('does not publish a variant when the filesystem returns false', function () {
    Config::set('mediaman.responsive_images.versioning', 'generation');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg', 800, 600))->upload();
    Config::set('mediaman.responsive_images.disk', 'failing-responsive');

    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('put')->twice()->andReturn(true, false);
    $filesystem->shouldReceive('deleteDirectory')->once()->andReturn(true);
    Storage::set('failing-responsive', $filesystem);

    expect(fn () => $this->generator->generateResponsiveImages($media, [
        'widths' => [320],
        'formats' => ['jpg'],
    ]))->toThrow(MediaFileWriteFailed::class);

    expect($media->fresh()->hasResponsiveImages())->toBeFalse();
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

// --- Per-format quality ---

it('accepts a scalar quality and applies it across every lossy format', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [400],
        'formats' => ['jpg', 'webp'],
        'quality' => 80,
    ]);

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->sort()->values()->toArray();

    expect($formats)->toEqual(['jpg', 'webp']);
});

it('accepts a per-format quality array', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [400],
        'formats' => ['jpg', 'webp'],
        'quality' => ['jpg' => 70, 'webp' => 50],
    ]);

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->sort()->values()->toArray();

    expect($formats)->toEqual(['jpg', 'webp']);
});

it('throws when per-format quality array misses a lossy format from formats', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect(fn () => $this->generator->generateResponsiveImages($media, [
        'widths' => [400],
        'formats' => ['jpg', 'webp'],
        'quality' => ['jpg' => 80], // missing webp
    ]))
        ->toThrow(InvalidArgumentException::class, 'missing entries for [webp]');
});

it('does not require entries for lossless formats (png/gif) in the quality array', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [400],
        'formats' => ['jpg', 'png'],
        'quality' => ['jpg' => 80], // png intentionally omitted — encoder ignores quality
    ]);

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->sort()->values()->toArray();

    expect($formats)->toEqual(['jpg', 'png']);
});

it('accepts extra quality keys for formats not in the formats list', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, [
        'widths' => [400],
        'formats' => ['jpg'],
        'quality' => ['jpg' => 80, 'webp' => 60, 'avif' => 50], // webp/avif unused
    ]);

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->toArray();

    expect($formats)->toEqual(['jpg']);
});

it('uses the array form when configured at the config level (no per-call override)', function () {
    config()->set('mediaman.responsive_images.formats', ['jpg', 'webp']);
    config()->set('mediaman.responsive_images.quality', ['jpg' => 80, 'webp' => 60]);

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->generator->generateResponsiveImages($media, ['widths' => [400]]);

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->sort()->values()->toArray();

    expect($formats)->toEqual(['jpg', 'webp']);
});

it('throws when config-level quality array misses a lossy format from configured formats', function () {
    config()->set('mediaman.responsive_images.formats', ['jpg', 'webp']);
    config()->set('mediaman.responsive_images.quality', ['jpg' => 80]); // missing webp

    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    expect(fn () => $this->generator->generateResponsiveImages($media, ['widths' => [400]]))
        ->toThrow(InvalidArgumentException::class, 'missing entries for [webp]');
});

it('MediaUploader::withQuality accepts both scalar and array shapes', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

    $media = MediaUploader::source($file)
        ->generateResponsive()
        ->withFormats(['jpg', 'webp'])
        ->withBreakpoints([400])
        ->withQuality(['jpg' => 75, 'webp' => 55])
        ->upload();

    $formats = $media->fresh()->getResponsiveImages()->pluck('format')->sort()->values()->toArray();

    expect($formats)->toEqual(['jpg', 'webp']);
});
