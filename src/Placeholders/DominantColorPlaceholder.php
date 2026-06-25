<?php

namespace Emaia\MediaMan\Placeholders;

use Intervention\Image\ImageManager;
use Throwable;

class DominantColorPlaceholder implements PlaceholderGenerator
{
    public function __construct(protected ImageManager $images) {}

    public function generate(string $sourcePath, int $width, int $height): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        try {
            $color = $this->images
                ->decode(file_get_contents($sourcePath))
                ->resize(width: 1, height: 1)
                ->colorAt(0, 0)
                ->toHex(prefix: true);

            $svg = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="%s"/></svg>',
                $width,
                $height,
                $color
            );

            return 'data:image/svg+xml,'.rawurlencode($svg);
        } catch (Throwable) {
            return null;
        }
    }
}
