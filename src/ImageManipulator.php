<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\Models\Media;
use Intervention\Image\EncodedImage;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;

class ImageManipulator
{
    protected ConversionRegistry $conversionRegistry;

    protected ImageManager $imageManager;

    public function __construct(ConversionRegistry $conversionRegistry, ImageManager $imageManager)
    {
        $this->conversionRegistry = $conversionRegistry;

        $this->imageManager = $imageManager;
    }

    /**
     * Perform the specified conversions on the given media item.
     */
    public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): void
    {
        if (! $media->isOfType(MediaType::IMAGE)) {
            return;
        }

        foreach ($conversions as $conversion) {
            $converter = $this->conversionRegistry->get($conversion);

            $image = $converter($this->imageManager->decode(
                $media->filesystem()->readStream($media->getOriginalPath())
            ));

            $filesystem = $media->filesystem();

            if ($image instanceof EncodedImage) {
                $extension = $this->getExtensionFromMimeType($image->mediaType());
                $path = $this->getConversionPathWithExtension($media, $conversion, $extension);

                if ($onlyIfMissing && $filesystem->exists($path)) {
                    continue;
                }

                $filesystem->put($path, $image->toStream());

                continue;
            }

            if ($image instanceof Image) {
                $encoded = $image->encode();
                $extension = $this->getExtensionFromMimeType($encoded->mediaType());
                $path = $this->getConversionPathWithExtension($media, $conversion, $extension);

                if ($onlyIfMissing && $filesystem->exists($path)) {
                    continue;
                }

                $filesystem->put($path, $encoded->toStream());
            }
        }
    }

    /**
     * Get the conversion path with specific extension.
     */
    protected function getConversionPathWithExtension(Media $media, string $conversion, string $extension): string
    {
        $resolver = app(MediaResolver::class);
        $directory = $resolver->pathForConversion($media, $conversion);
        $fileName = $resolver->conversionFileName($media->file_name, $conversion, $extension);

        return $directory.'/'.$fileName;
    }

    protected function getExtensionFromMimeType(string $mimeType): string
    {
        return MediaFormat::extensionFromMimeType($mimeType);
    }
}
