<?php

namespace Emaia\MediaMan\Placeholders;

interface PlaceholderGenerator
{
    /**
     * Generate a low-quality image placeholder for the given source image.
     *
     * Implementations return a data URI (e.g. `data:image/svg+xml;base64,...`)
     * suitable for direct use in an `srcset` entry. Returning `null` signals
     * that no placeholder could be produced so the upload pipeline can move
     * on without breaking.
     */
    public function generate(string $sourcePath, int $width, int $height): ?string;
}
