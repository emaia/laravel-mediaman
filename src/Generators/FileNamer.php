<?php

namespace Emaia\MediaMan\Generators;

interface FileNamer
{
    /**
     * Sanitize and produce the on-disk base filename for an uploaded file.
     */
    public function getBaseName(string $originalName): string;

    /**
     * Produce the filename for a conversion variant. The default keeps the
     * original basename and only swaps the extension; the conversion identity
     * lives in the directory path. Custom implementations may add a suffix
     * (e.g., "photo-thumb.jpg") if preferred.
     */
    public function getConversionFileName(string $originalName, string $conversion, string $extension): string;

    /**
     * Produce the filename for a responsive image variant.
     */
    public function getResponsiveFileName(string $originalName, int $width, string $format): string;
}
