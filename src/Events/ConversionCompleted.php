<?php

namespace Emaia\MediaMan\Events;

use Emaia\MediaMan\Models\Media;
use Illuminate\Queue\SerializesModels;

class ConversionCompleted
{
    use SerializesModels;

    public function __construct(
        public Media $media,
        public array $conversions,
    ) {}
}
