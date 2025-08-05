<?php

namespace Emaia\MediaMan\ResponsiveImages\WidthCalculator;

use Illuminate\Support\Collection;
use Intervention\Image\ImageManager;

class FileSizeOptimizedWidthCalculator implements WidthCalculator
{
    protected ImageManager $imageManager;

    public function __construct(?ImageManager $imageManager = null)
    {
        $this->imageManager = $imageManager ?? ImageManager::gd();
    }

    /**
     * Calculate optimal widths from an image file.
     */
    public function calculateWidthsFromFile(string $imagePath): Collection
    {
        $image = $this->imageManager->read($imagePath);

        $width = $image->width();
        $height = $image->height();
        $fileSize = filesize($imagePath);

        return $this->calculateWidths($fileSize, $width, $height);
    }

    /**
     * Calculate optimal widths based on file size optimization.
     */
    public function calculateWidths(int $fileSize, int $width, int $height): Collection
    {
        $targetWidths = collect();

        // Always include original width
        $targetWidths->push($width);

        $ratio = $height / $width;
        $area = $height * $width;

        $predictedFileSize = $fileSize;
        $pixelPrice = $predictedFileSize / $area;

        while (true) {
            // Reduce file size by 30% each iteration
            $predictedFileSize *= 0.7;

            $newWidth = (int) floor(sqrt(($predictedFileSize / $pixelPrice) / $ratio));

            if ($this->finishedCalculating((int) $predictedFileSize, $newWidth)) {
                break;
            }

            $targetWidths->push($newWidth);
        }

        // Remove duplicates and sort in descending order
        return $targetWidths->unique()->sort()->reverse()->values();
    }

    /**
     * Determine if we should stop calculating new widths.
     */
    protected function finishedCalculating(int $predictedFileSize, int $newWidth): bool
    {
        // Stop if width becomes too small
        if ($newWidth < 20) {
            return true;
        }

        // Stop if predicted file size is too small (10KB)
        if ($predictedFileSize < (1024 * 10)) {
            return true;
        }

        return false;
    }
}