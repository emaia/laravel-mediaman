<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Intervention\Image\ImageManager;

class ResponsiveImageGenerator
{
    protected ImageManager $imageManager;
    protected WidthCalculator $widthCalculator;

    public function __construct(?ImageManager $imageManager = null, ?WidthCalculator $widthCalculator = null)
    {
        $this->imageManager = $imageManager ?? ImageManager::imagick() ?? ImageManager::gd();
        $this->widthCalculator = $widthCalculator ?? new BreakpointWidthCalculator();
    }

    /**
     * Generate responsive images for a media item.
     */
    public function generateResponsiveImages(Media $media, array $options = []): void
    {
        if (!$media->isOfType('image')) {
            return;
        }

        $originalPath = $media->getOriginalPath();
        $filesystem = $media->filesystem();

        if (!$filesystem->exists($originalPath)) {
            return;
        }

        // Get configuration
        $quality = $options['quality'] ?? config('mediaman.responsive_images.quality', 85);
        $formats = $options['formats'] ?? config('mediaman.responsive_images.formats', ['webp', 'jpg']);
        $widths = $options['widths'] ?? null;

        // Calculate widths if not provided
        if (!$widths) {
            $tempFile = tempnam(sys_get_temp_dir(), 'mediaman_responsive');
            file_put_contents($tempFile, $filesystem->get($originalPath));
            $widths = $this->widthCalculator->calculateWidthsFromFile($tempFile);
            unlink($tempFile);
        } else {
            $widths = collect($widths);
        }

        $responsiveData = [];
        $originalImage = $this->imageManager->read($filesystem->readStream($originalPath));

        foreach ($widths as $targetWidth) {
            // Skip if target width is larger than original
            if ($targetWidth > $originalImage->width()) {
                continue;
            }

            foreach ($formats as $format) {
                $responsiveData[] = $this->generateSingleResponsiveImage(
                    $media,
                    clone $originalImage,
                    $targetWidth,
                    $format,
                    $quality
                );
            }
        }

        $media->setCustomProperty('responsive_images', $responsiveData);
        $media->save();
    }

    /**
     * Generate a single responsive image variant.
     */
    protected function generateSingleResponsiveImage(Media $media, $image, int $targetWidth, string $format, int $quality): array
    {
        $image->scaleDown($targetWidth, null);

        $encodedImage = match ($format) {
            'webp' => $image->toWebp($quality),
            'avif' => $image->toAvif($quality),
            'png' => $image->toPng(),
            default => $image->toJpeg($quality),
        };

        $directory = $media->getDirectory() . '/responsive';
        $fileName = $this->generateResponsiveFileName($media->file_name, $targetWidth, $format);
        $path = $directory . '/' . $fileName;

        $media->filesystem()->put($path, $encodedImage->toFilePointer());

        return [
            'width' => $targetWidth,
            'height' => $image->height(),
            'format' => $format,
            'path' => $path,
            'url' => $media->filesystem()->url($path),
            'size' => strlen($encodedImage->toString()),
        ];
    }

    /**
     * Generate filename for responsive image.
     */
    protected function generateResponsiveFileName(string $originalFileName, int $width, string $format): string
    {
        $pathInfo = pathinfo($originalFileName);
        $baseName = $pathInfo['filename'];

        return "{$baseName}_{$width}w.$format";
    }

    /**
     * Clear all responsive images for a media item.
     */
    public function clearResponsiveImages(Media $media): void
    {
        $responsiveDir = $media->getDirectory() . '/responsive';
        $filesystem = $media->filesystem();

        if ($filesystem->exists($responsiveDir)) {
            $filesystem->deleteDirectory($responsiveDir);
        }

        $media->forgetCustomProperty('responsive_images');
        $media->save();
    }

    /**
     * Set a custom width calculator.
     */
    public function setWidthCalculator(WidthCalculator $calculator): self
    {
        $this->widthCalculator = $calculator;
        return $this;
    }
}