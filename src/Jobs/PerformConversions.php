<?php

namespace Emaia\MediaMan\Jobs;

use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PerformConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Media $media;

    protected array $conversions;

    public function __construct(Media $media, array $conversions)
    {
        $this->media = $media;

        $this->conversions = $conversions;
    }

    public function handle(): void
    {
        app(ImageManipulator::class)->manipulate(
            $this->media,
            $this->conversions
        );
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getConversions(): array
    {
        return $this->conversions;
    }
}
