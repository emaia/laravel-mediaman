<?php

namespace Emaia\MediaMan\ResponsiveImages\WidthCalculator;

use Illuminate\Support\Collection;
use Intervention\Image\ImageManager;

class BreakpointWidthCalculator implements WidthCalculator
{
    protected ImageManager $imageManager;

    protected array $breakpoints;

    public function __construct(?array $breakpoints = null, ?ImageManager $imageManager = null)
    {
        $this->breakpoints = $breakpoints ?? config('mediaman.responsive_images.breakpoints', [320, 640, 1024, 1366, 1920]);
        $this->imageManager = $imageManager ?? ImageManager::gd();
    }

    /**
     * Calculate widths based on predefined breakpoints.
     */
    public function calculateWidthsFromFile(string $imagePath): Collection
    {
        $image = $this->imageManager->read($imagePath);

        $originalWidth = $image->width();
        $originalHeight = $image->height();
        $fileSize = filesize($imagePath);

        return $this->calculateWidths($fileSize, $originalWidth, $originalHeight);
    }

    /**
     * Calculate widths based on breakpoints, filtered by original width.
     */
    public function calculateWidths(int $fileSize, int $width, int $height): Collection
    {
        return collect($this->breakpoints)
            ->filter(fn ($breakpoint) => $breakpoint <= $width)
            ->push($width) // Always include original width
            ->unique()
            ->sort()
            ->reverse()
            ->values();
    }

    /**
     * Set custom breakpoints.
     */
    public function setBreakpoints(array $breakpoints): self
    {
        $this->breakpoints = $breakpoints;

        return $this;
    }

    /**
     * Get current breakpoints.
     */
    public function getBreakpoints(): array
    {
        return $this->breakpoints;
    }
}
