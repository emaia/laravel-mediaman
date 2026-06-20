<?php

namespace Emaia\MediaMan\Events;

use Emaia\MediaMan\Models\MediaCollection;
use Illuminate\Queue\SerializesModels;

class MediaPrunedFromCollection
{
    use SerializesModels;

    /**
     * @param  array<int, int|string>  $detachedMediaIds  Keys of the media detached from the collection.
     */
    public function __construct(
        public MediaCollection $collection,
        public array $detachedMediaIds,
    ) {}
}
