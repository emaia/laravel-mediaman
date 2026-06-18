<?php

namespace Emaia\MediaMan\Placeholders;

use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

class BlurredSvgPlaceholder implements PlaceholderGenerator
{
    public function __construct(protected ImageManager $images) {}

    public function generate(string $sourcePath, int $width, int $height): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        try {
            $tinyWidth = (int) config('mediaman.placeholder.width', 32);
            $blur = (int) config('mediaman.placeholder.blur', 20);
            $quality = (int) config('mediaman.placeholder.quality', 40);

            $jpeg = (string) $this->images
                ->decode(file_get_contents($sourcePath))
                ->scaleDown($tinyWidth)
                ->blur($blur)
                ->encodeUsingFormat(Format::JPEG, quality: $quality);

            $jpegDataUri = 'data:image/jpeg;base64,'.base64_encode($jpeg);

            $svg = sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 %d %d"><image width="%d" height="%d" xlink:href="%s"/></svg>',
                $width,
                $height,
                $width,
                $height,
                $jpegDataUri
            );

            // url-encode the SVG wrapper instead of base64. The wrapper is
            // ASCII XML, so percent-encoding is ~16% smaller than base64 and
            // leaves the structure readable in DevTools. rawurlencode escapes
            // whitespace and commas, so the resulting data URI is safe inside
            // srcset (whose parser terminates the URL at the first ASCII
            // whitespace).
            return 'data:image/svg+xml,'.rawurlencode($svg);
        } catch (Throwable) {
            return null;
        }
    }
}
