<?php

namespace Emaia\MediaMan\Events;

use Emaia\MediaMan\Models\Media;
use Illuminate\Queue\SerializesModels;

class ResponsiveImagesGenerated
{
    use SerializesModels;

    public function __construct(
        public Media $media,
        public array $options,
    ) {}
}
