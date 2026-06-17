<?php

namespace Emaia\MediaMan\Generators;

use Emaia\MediaMan\Models\Media;

interface PathGenerator
{
    /**
     * Get the base directory where the media is stored.
     */
    public function getDirectory(Media $media): string;

    /**
     * Get the directory for a specific conversion of the media.
     */
    public function getPathForConversion(Media $media, string $conversion): string;

    /**
     * Get the directory for responsive variants of the media.
     */
    public function getPathForResponsive(Media $media): string;
}
