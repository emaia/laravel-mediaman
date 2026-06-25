<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Intervention\Image\EncodedImage;
use Intervention\Image\Exceptions\EncoderException;
use Intervention\Image\Exceptions\StreamException;
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
     * Run each requested conversion in isolation — a failure on one no longer
     * cancels the remaining items in the batch. The returned report carries
     * the names of successful conversions and any per-conversion exceptions.
     *
     * @param  string[]  $conversions
     * @return array{completed: array<int, string>, failed: array<int, array{conversion: string, exception: \Throwable}>}
     */
    public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): array
    {
        $report = ['completed' => [], 'failed' => []];

        if (! $media->isOfType(MediaType::IMAGE)) {
            return $report;
        }

        foreach ($conversions as $conversion) {
            try {
                $this->runConversion($media, $conversion, $onlyIfMissing);
                $report['completed'][] = $conversion;
            } catch (\Throwable $e) {
                $report['failed'][] = [
                    'conversion' => $conversion,
                    'exception' => $e,
                ];
            }
        }

        return $report;
    }

    /**
     * Encode and persist a single conversion. Extracted so the per-iteration
     * try/catch in `manipulate()` covers every step (registry lookup, decode,
     * encode, write).
     *
     * @throws StreamException
     * @throws EncoderException
     */
    protected function runConversion(Media $media, string $conversion, bool $onlyIfMissing): void
    {
        $converter = $this->conversionRegistry->get($conversion);

        $image = $converter($this->imageManager->decode(
            $media->filesystem()->readStream($media->getOriginalPath())
        ));

        $filesystem = $media->conversionFilesystem($conversion);

        if ($image instanceof EncodedImage) {
            $extension = $this->getExtensionFromMimeType($image->mediaType());
            $path = $this->getConversionPathWithExtension($media, $conversion, $extension);

            if ($onlyIfMissing && $filesystem->exists($path)) {
                return;
            }

            $filesystem->put($path, $image->toStream());

            return;
        }

        if ($image instanceof Image) {
            $encoded = $image->encode();
            $extension = $this->getExtensionFromMimeType($encoded->mediaType());
            $path = $this->getConversionPathWithExtension($media, $conversion, $extension);

            if ($onlyIfMissing && $filesystem->exists($path)) {
                return;
            }

            $filesystem->put($path, $encoded->toStream());
        }
    }

    protected function getConversionPathWithExtension(Media $media, string $conversion, string $extension): string
    {
        $resolver = app(MediaResolver::class);
        $directory = $resolver->pathForConversion($media, $conversion);
        $fileName = $resolver->conversionFileName($media->file_name, $conversion, $extension);

        return $directory.'/'.$fileName;
    }

    /**
     * Throws on unknown MIME types so the per-conversion try/catch in `manipulate()`
     * captures it as a per-conversion failure rather than writing the encoded
     * bytes with a wrong-but-plausible extension (PR #31 family).
     */
    protected function getExtensionFromMimeType(string $mimeType): string
    {
        $extension = MediaFormat::extensionFromMimeType($mimeType);

        if ($extension === null) {
            throw new \RuntimeException(
                "Cannot resolve a file extension for MIME type [$mimeType] — refusing to write the conversion."
            );
        }

        return $extension;
    }
}
