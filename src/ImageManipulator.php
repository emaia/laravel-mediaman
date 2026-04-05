<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Models\Media;
use Intervention\Image\EncodedImage;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;

class ImageManipulator
{
    protected ConversionRegistry $conversionRegistry;

    protected ImageManager $imageManager;

    public function __construct(ConversionRegistry $conversionRegistry, ?ImageManager $imageManager = null)
    {
        $this->conversionRegistry = $conversionRegistry;

        $this->imageManager = $imageManager ?? ImageManager::imagick() ?? ImageManager::gd();
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

            $image = $converter($this->imageManager->read(
                $media->filesystem()->readStream($media->getOriginalPath())
            ));

            $filesystem = $media->filesystem();

            if ($image instanceof EncodedImage) {
                $extension = $this->getExtensionFromMimeType($image->mediaType());
                $path = $this->getConversionPathWithExtension($media, $conversion, $extension);

                if ($onlyIfMissing && $filesystem->exists($path)) {
                    continue;
                }

                $filesystem->put($path, $image->toFilePointer());

                continue;
            }

            if ($image instanceof Image) {
                $encoded = $image->encodeByMediaType();
                $extension = $this->getExtensionFromMimeType($encoded->mediaType());
                $path = $this->getConversionPathWithExtension($media, $conversion, $extension);

                if ($onlyIfMissing && $filesystem->exists($path)) {
                    continue;
                }

                $filesystem->put($path, $encoded->toFilePointer());
            }
        }
    }

    /**
     * Get the conversion path with specific extension.
     */
    protected function getConversionPathWithExtension(Media $media, string $conversion, string $extension): string
    {
        $directory = $media->getDirectory().'/'.Media::CONVERSIONS_DIR.'/'.$conversion;
        $fileName = $media->replaceFileExtension($media->file_name, $extension);

        return $directory.'/'.$fileName;
    }

    protected function getExtensionFromMimeType(string $mimeType): string
    {
        return MediaFormat::extensionFromMimeType($mimeType);
    }

    protected function updatePathExtension(string $path, string $newExtension): string
    {
        $pathInfo = pathinfo($path);

        return $pathInfo['dirname'].'/'.$pathInfo['filename'].'.'.$newExtension;
    }
}
