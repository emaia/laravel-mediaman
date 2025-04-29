<?php

namespace FarhanShares\MediaMan;

use FarhanShares\MediaMan\Exceptions\InvalidConversion;
use FarhanShares\MediaMan\Models\Media;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Intervention\Image\ImageManager;

class ImageManipulator
{
    /** @var ConversionRegistry */
    protected $conversionRegistry;

    /** @var ImageManager */
    protected $imageManager;

    public function __construct(ConversionRegistry $conversionRegistry)
    {
        $this->conversionRegistry = $conversionRegistry;

        $this->imageManager = ImageManager::gd();
    }

    /**
     * Perform the specified conversions on the given media item.
     *
     * @param bool $onlyIfMissing
     * @return void
     *
     * @throws InvalidConversion
     * @throws FileNotFoundException
     *
     * todo: resolve dependency model from config in __construct?
     */
    public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true)
    {
        if (! $media->isOfType('image')) {
            return;
        }

        foreach ($conversions as $conversion) {
            $converter = $this->conversionRegistry->get($conversion);
            
            $image = $converter($this->imageManager->read(
                $media->filesystem()->readStream($media->getPath())
            ));

            $extension = $this->getExtensionFromMimeType($image->mediaType());
            
            $path = $this->updatePathExtension($media->getPath($conversion), $extension);
            
            $filesystem = $media->filesystem();

            if ($onlyIfMissing && $filesystem->exists($path)) {
                continue;
            }

            $filesystem->put($path, $image->toFilePointer());
        }
    }
    
    protected function getExtensionFromMimeType(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            
            'image/avif' => 'avif',
            'image/tiff' => 'tiff',
            'image/jp2' => 'jp2',     // JPEG 2000
            'image/jpx' => 'jpx',     // JPEG 2000 Part 2
            'image/jpm' => 'jpm',     // JPEG 2000 Part 6
            'image/heic' => 'heic',   // HEIC (High Efficiency Image Format)
            'image/heif' => 'heif',   // HEIF (High Efficiency Image Format)
            
            'image/x-ms-bmp' => 'bmp',
            'image/tif' => 'tif',
            'image/vnd.adobe.photoshop' => 'psd',
            'image/x-photoshop' => 'psd',
        ];
        
        return $map[$mimeType] ?? 'jpg';
    }
    
    protected function updatePathExtension(string $path, string $newExtension): string
    {
        $pathInfo = pathinfo($path);
        
        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.' . $newExtension;
    }
}
