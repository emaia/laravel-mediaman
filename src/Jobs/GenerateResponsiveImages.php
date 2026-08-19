<?php

namespace Emaia\MediaMan\Jobs;

use Emaia\MediaMan\Events\ResponsiveImagesGenerated;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGenerationResult;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateResponsiveImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Media $media;

    protected array $options;

    public function __construct(Media $media, array $options = [])
    {
        $this->media = $media;
        $this->options = $options;

        $this->onConnection(config('mediaman.queue'));
    }

    public function handle(ResponsiveImageGenerator $generator): void
    {
        $result = $generator->generateResponsiveImages(
            $this->media,
            $this->options
        );

        if (! $result instanceof ResponsiveGenerationResult || $result->wasPublished()) {
            event(new ResponsiveImagesGenerated(
                $this->media,
                $this->options,
                $result instanceof ResponsiveGenerationResult ? $result : null,
            ));
        }
    }

    public function getMedia(): Media
    {
        return $this->media;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
