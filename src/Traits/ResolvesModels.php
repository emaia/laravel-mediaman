<?php

namespace Emaia\MediaMan\Traits;

trait ResolvesModels
{
    protected function mediaModel(): string
    {
        return config('mediaman.models.media');
    }

    protected function collectionModel(): string
    {
        return config('mediaman.models.collection');
    }
}
