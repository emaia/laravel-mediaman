<?php

namespace Emaia\MediaMan\ResponsiveImages\WidthCalculator;

use Illuminate\Support\Collection;
use Intervention\Image\ImageManager;

class FileSizeOptimizedWidthCalculator implements WidthCalculator
{
    protected ImageManager $imageManager;

    public function __construct(ImageManager $imageManager)
    {
        $this->imageManager = $imageManager;
    }

    public function calculateWidthsFromFile(string $imagePath): Collection
    {
        return $this->calculateWidthsFromBinary((string) file_get_contents($imagePath));
    }

    public function calculateWidthsFromBinary(string $binary): Collection
    {
        $image = $this->imageManager->decode($binary);

        return $this->calculateWidths(strlen($binary), $image->width(), $image->height());
    }

    public function calculateWidths(int $fileSize, int $width, int $height): Collection
    {
        $targetWidths = collect();

        // Always include original width
        $targetWidths->push($width);

        $ratio = $height / $width;
        $area = $height * $width;

        $predictedFileSize = $fileSize;
        $pixelPrice = $predictedFileSize / $area;

        $reductionFactor = config('mediaman.responsive_images.file_size_optimized.reduction_factor', 0.7);
        $minWidth = config('mediaman.responsive_images.file_size_optimized.min_width', 20);
        $minFileSize = config('mediaman.responsive_images.file_size_optimized.min_file_size_bytes', 10240);

        while (true) {
            $predictedFileSize *= $reductionFactor;

            $newWidth = (int) floor(sqrt(($predictedFileSize / $pixelPrice) / $ratio));

            if ($this->finishedCalculating((int) $predictedFileSize, $newWidth, $minWidth, $minFileSize)) {
                break;
            }

            $targetWidths->push($newWidth);
        }

        // Remove duplicates and sort in descending order
        return $targetWidths->unique()->sort()->reverse()->values();
    }

    /** Stops once a smaller width or a smaller predicted file size hits the floor. */
    protected function finishedCalculating(int $predictedFileSize, int $newWidth, int $minWidth, int $minFileSize): bool
    {
        if ($newWidth < $minWidth) {
            return true;
        }

        if ($predictedFileSize < $minFileSize) {
            return true;
        }

        return false;
    }
}
