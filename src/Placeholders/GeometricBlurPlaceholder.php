<?php

namespace Emaia\MediaMan\Placeholders;

use Intervention\Image\ImageManager;
use Throwable;

class GeometricBlurPlaceholder implements PlaceholderGenerator
{
    public function __construct(protected ImageManager $images) {}

    public function generate(string $sourcePath, int $width, int $height): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        try {
            $gridSize = (int) config('mediaman.placeholder.geometric_blur.grid_size', 4);
            $stdDeviation = (float) config('mediaman.placeholder.geometric_blur.blur_std_deviation', 20);

            if ($gridSize < 1) {
                return null;
            }

            $thumb = $this->images
                ->decode(file_get_contents($sourcePath))
                ->resize(width: $gridSize, height: $gridSize);

            $cellWidth = $width / $gridSize;
            $cellHeight = $height / $gridSize;

            $rects = '';
            for ($y = 0; $y < $gridSize; $y++) {
                for ($x = 0; $x < $gridSize; $x++) {
                    $color = $thumb->colorAt($x, $y)->toHex(prefix: true);
                    $rects .= sprintf(
                        '<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
                        $this->trimFloat($x * $cellWidth),
                        $this->trimFloat($y * $cellHeight),
                        $this->trimFloat($cellWidth),
                        $this->trimFloat($cellHeight),
                        $color
                    );
                }
            }

            $svg = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d"><filter id="b"><feGaussianBlur stdDeviation="%s"/></filter><g filter="url(#b)">%s</g></svg>',
                $width,
                $height,
                $this->trimFloat($stdDeviation),
                $rects
            );

            return 'data:image/svg+xml,'.rawurlencode($svg);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Shortest decimal representation; integers print without a fractional
     * suffix so the SVG body stays compact.
     */
    protected function trimFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
    }
}
