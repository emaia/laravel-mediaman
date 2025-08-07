<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Intervention\Image\Image;

class ResponsiveConversion
{
    protected Image $image;
    protected array $options;

    public function __construct(Image $image, array $options = [])
    {
        $this->image = $image;
        $this->options = $options;
    }

    /**
     * This method will be called by ImageManipulator
     * Instead of returning a processed image, it triggers responsive generation
     */
    public function __invoke($media): Image
    {
        // Get the media instance from the manipulation context
        if (method_exists($media, 'generateResponsiveImages')) {
            $media->generateResponsiveImages($this->options);
        }

        // Return the original image to maintain compatibility
        return $this->image;
    }

    /**
     * Get the options for this responsive conversion.
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}