<?php

namespace Emaia\MediaMan\Events;

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationResult;
use Illuminate\Queue\SerializesModels;

class ResponsiveImagesGenerated
{
    use SerializesModels;

    public function __construct(
        public Media $media,
        public array $options,
        public ?ResponsiveGenerationResult $result = null,
    ) {}
}
