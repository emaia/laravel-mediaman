<?php

namespace Emaia\MediaMan\ResponsiveImages\WidthCalculator;

use Illuminate\Support\Collection;
use Intervention\Image\ImageManager;

class BreakpointWidthCalculator implements WidthCalculator
{
    protected ImageManager $imageManager;

    protected array $breakpoints;

    public function __construct(ImageManager $imageManager, array $breakpoints)
    {
        $this->breakpoints = $breakpoints;
        $this->imageManager = $imageManager;
    }

    /**
     * Calculate widths based on predefined breakpoints.
     */
    public function calculateWidthsFromFile(string $imagePath): Collection
    {
        return $this->calculateWidthsFromBinary((string) file_get_contents($imagePath));
    }

    /**
     * Calculate widths from in-memory binary image data.
     */
    public function calculateWidthsFromBinary(string $binary): Collection
    {
        $image = $this->imageManager->decode($binary);

        return $this->calculateWidths(strlen($binary), $image->width(), $image->height());
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
