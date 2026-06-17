<?php

namespace Emaia\MediaMan\Generators;

use Emaia\MediaMan\Models\Media;

class DefaultPathGenerator implements PathGenerator
{
    public function getDirectory(Media $media): string
    {
        return $media->getKey().'-'.md5($media->getKey().config('app.key'));
    }

    public function getPathForConversion(Media $media, string $conversion): string
    {
        return $this->getDirectory($media).'/'.Media::CONVERSIONS_DIR.'/'.$conversion;
    }

    public function getPathForResponsive(Media $media): string
    {
        return $this->getDirectory($media).'/'.Media::RESPONSIVE_DIR;
    }
}
