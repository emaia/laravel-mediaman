<?php

namespace Emaia\MediaMan\ResponsiveImages\WidthCalculator;

use Illuminate\Support\Collection;

interface WidthCalculator
{
    /**
     * Calculate optimal widths from an image file.
     */
    public function calculateWidthsFromFile(string $imagePath): Collection;

    /**
     * Calculate optimal widths based on file properties.
     */
    public function calculateWidths(int $fileSize, int $width, int $height): Collection;
}
