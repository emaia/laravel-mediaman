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

    public function calculateWidthsFromFile(string $imagePath): Collection
    {
        return $this->calculateWidthsFromBinary((string) file_get_contents($imagePath));
    }

    public function calculateWidthsFromBinary(string $binary): Collection
    {
        $image = $this->imageManager->decode($binary);

        return $this->calculateWidths(strlen($binary), $image->width(), $image->height());
    }

    /** Drops breakpoints larger than `$width`; original width is always included. */
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

    public function setBreakpoints(array $breakpoints): self
    {
        $this->breakpoints = $breakpoints;

        return $this;
    }

    public function getBreakpoints(): array
    {
        return $this->breakpoints;
    }
}
