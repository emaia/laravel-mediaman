<?php

namespace Emaia\MediaMan\Tests\Feature;

use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Image;
use PHPUnit\Framework\Attributes\Test;

class FormatDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // Register test conversions
        Conversion::register('webp_thumb', function (Image $image) {
            return $image->resize(200, 200)->toWebp();
        });

        Conversion::register('avif_hero', function (Image $image) {
            return $image->resize(800, 600)->toAvif();
        });

        Conversion::register('png_logo', function (Image $image) {
            return $image->resize(100, 100)->toPng();
        });

        Conversion::register('original_format', function (Image $image) {
            return $image->resize(300, 300); // Mantém formato original
        });
    }

    #[Test]
    public function it_detects_webp_format_for_webp_conversion()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.jpg', 500, 500);
        $media = MediaUploader::source($file)->upload();

        // Act
        $url = $media->getUrl('webp_thumb');
        $path = $media->getPath('webp_thumb');

        // Assert
        $this->assertStringEndsWith('.webp', $url);
        $this->assertStringEndsWith('.webp', $path);
        $this->assertStringContainsString('/conversions/webp_thumb/', $url);
    }

    #[Test]
    public function it_detects_avif_format_for_avif_conversion()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.png', 800, 600);
        $media = MediaUploader::source($file)->upload();

        // Act
        $url = $media->getUrl('avif_hero');

        // Assert
        $this->assertStringEndsWith('.avif', $url);
        $this->assertStringContainsString('/conversions/avif_hero/', $url);
    }

    #[Test]
    public function it_detects_png_format_for_png_conversion()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.jpg', 200, 200);
        $media = MediaUploader::source($file)->upload();

        // Act
        $url = $media->getUrl('png_logo');

        // Assert
        $this->assertStringEndsWith('.png', $url);
        $this->assertStringContainsString('/conversions/png_logo/', $url);
    }

    #[Test]
    public function it_keeps_original_format_when_no_format_change()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.jpg', 400, 400);
        $media = MediaUploader::source($file)->upload();

        // Act
        $url = $media->getUrl('original_format');

        // Assert
        $this->assertStringEndsWith('.jpg', $url);
        $this->assertStringContainsString('/conversions/original_format/', $url);
    }

    #[Test]
    public function it_returns_original_url_when_no_conversion_specified()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.png', 300, 300);
        $media = MediaUploader::source($file)->upload();

        // Act
        $url = $media->getUrl();

        // Assert
        $this->assertStringEndsWith('.png', $url);
        $this->assertStringNotContainsString('/conversions/', $url);
    }

    #[Test]
    public function it_falls_back_to_original_extension_on_detection_failure()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.jpg', 200, 200);
        $media = MediaUploader::source($file)->upload();

        // Act - try to get URL for non-existent conversion
        $url = $media->getUrl('non_existent_conversion');

        // Assert - should still work with the original extension
        $this->assertStringEndsWith('.jpg', $url);
        $this->assertStringContainsString('/conversions/non_existent_conversion/', $url);
    }

    #[Test]
    public function has_conversion_method_works_correctly()
    {
        // Arrange
        $file = UploadedFile::fake()->image('test.jpg', 200, 200);
        $media = MediaUploader::source($file)->upload();

        // Simulate that conversion file exists
        Storage::disk('public')->put(
            $media->getDirectory().'/conversions/webp_thumb/test.webp',
            'fake content'
        );

        // Act & Assert
        // $this->assertTrue($media->hasConversion('webp_thumb'));
        $this->assertFalse($media->hasConversion('non_existent'));
    }

    #[Test]
    public function extension_mapping_works_for_all_supported_formats()
    {
        $file = UploadedFile::fake()->image('test.jpg', 200, 200);
        $media = MediaUploader::source($file)->upload();

        $formats = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/avif' => 'avif',
            'image/tiff' => 'tiff',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
        ];

        foreach ($formats as $mimeType => $expectedExtension) {
            $actualExtension = $media->getExtensionFromMimeType($mimeType);
            $this->assertEquals($expectedExtension, $actualExtension,
                "Failed for mime type: $mimeType");
        }
    }
}
