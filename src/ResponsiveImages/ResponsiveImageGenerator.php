<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

class ResponsiveImageGenerator
{
    protected ImageManager $imageManager;

    protected WidthCalculator $widthCalculator;

    public function __construct(ImageManager $imageManager, WidthCalculator $widthCalculator)
    {
        $this->imageManager = $imageManager;
        $this->widthCalculator = $widthCalculator;
    }

    public function generateResponsiveImages(Media $media, array $options = []): void
    {
        if (! $media->isRasterImage()) {
            return;
        }

        $originalPath = $media->getOriginalPath();
        $filesystem = $media->filesystem();

        if (! $filesystem->exists($originalPath)) {
            return;
        }

        // Get configuration
        $quality = $options['quality'] ?? config('mediaman.responsive_images.quality', 85);
        $formats = $options['formats'] ?? config('mediaman.responsive_images.formats', ['webp', 'jpg']);
        $widths = $options['widths'] ?? null;

        $this->assertQualityShape($quality, $formats);

        // Read the original bytes once and reuse for width calculation + decoding
        $originalBytes = $filesystem->get($originalPath);

        if (! $widths) {
            $widths = $this->widthCalculator->calculateWidthsFromBinary($originalBytes);
        } else {
            $widths = collect($widths);
        }

        // Global clamps applied regardless of which calculator produced the widths.
        // `min_width` / `max_width` of 0 are treated as "no clamp on that side".
        $minWidth = (int) config('mediaman.responsive_images.min_width', 0);
        $maxWidth = (int) config('mediaman.responsive_images.max_width', 0);

        $widths = $widths->filter(function ($w) use ($minWidth, $maxWidth) {
            if ($minWidth > 0 && $w < $minWidth) {
                return false;
            }

            if ($maxWidth > 0 && $w > $maxWidth) {
                return false;
            }

            return true;
        });

        $responsiveData = [];
        $originalImage = $this->imageManager->decode($originalBytes);

        foreach ($widths as $targetWidth) {
            // Skip if target width is larger than original
            if ($targetWidth > $originalImage->width()) {
                continue;
            }

            foreach ($formats as $format) {
                try {
                    $responsiveData[] = $this->generateSingleResponsiveImage(
                        $media,
                        clone $originalImage,
                        $targetWidth,
                        $format,
                        $this->resolveQuality($format, $quality)
                    );
                } catch (\Throwable $e) {
                    Log::warning('MediaMan: Skipping responsive format — driver does not support encoding', [
                        'mediaId' => $media->id,
                        'format' => $format,
                        'width' => $targetWidth,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }
        }

        $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, $responsiveData);
        $media->save();
    }

    protected function generateSingleResponsiveImage(Media $media, ImageInterface $image, int $targetWidth, string $format, int $quality): array
    {
        $image->scaleDown($targetWidth, null);

        $encodedImage = match ($format) {
            MediaFormat::WEBP->value => $image->encodeUsingFormat(Format::WEBP, quality: $quality),
            MediaFormat::AVIF->value => $image->encodeUsingFormat(Format::AVIF, quality: $quality),
            MediaFormat::HEIC->value => $image->encodeUsingFormat(Format::HEIC, quality: $quality),
            MediaFormat::JPG->value, MediaFormat::JPEG->value => $image->encodeUsingFormat(Format::JPEG, quality: $quality),
            MediaFormat::PNG->value => $image->encodeUsingFormat(Format::PNG),
            MediaFormat::GIF->value => $image->encodeUsingFormat(Format::GIF),
            default => throw new InvalidArgumentException("Unsupported responsive format [$format]."),
        };

        $size = strlen((string) $encodedImage);

        if ($size === 0) {
            throw new \RuntimeException(
                "Encoder for [$format] returned zero bytes — the driver likely lacks support (e.g. imagick without libheif for HEIC)."
            );
        }

        $directory = app(MediaResolver::class)->pathForResponsive($media);
        $fileName = app(MediaResolver::class)->responsiveFileName($media->file_name, $targetWidth, $format);
        $path = $directory.'/'.$fileName;

        $filesystem = $media->responsiveFilesystem();
        $filesystem->put($path, $encodedImage->toStream());

        return [
            'width' => $targetWidth,
            'height' => $image->height(),
            'format' => $format,
            'path' => $path,
            'url' => $filesystem->url($path),
            'size' => $size,
        ];
    }

    /**
     * Clear all responsive images for a media item.
     */
    public function clearResponsiveImages(Media $media): void
    {
        $responsiveDir = app(MediaResolver::class)->pathForResponsive($media);
        $filesystem = $media->responsiveFilesystem();

        if ($filesystem->exists($responsiveDir)) {
            $filesystem->deleteDirectory($responsiveDir);
        }

        $media->forgetCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES);
        $media->save();
    }

    public function setWidthCalculator(WidthCalculator $calculator): self
    {
        $this->widthCalculator = $calculator;

        return $this;
    }

    /**
     * Pick the quality for a single format. Scalar config applies uniformly;
     * an array carries one entry per lossy format (already validated).
     */
    protected function resolveQuality(string $format, int|array $quality): int
    {
        if (is_int($quality)) {
            return $quality;
        }

        return (int) ($quality[strtolower($format)] ?? 85);
    }

    /**
     * Fail-loud when `quality` is an array but doesn't cover every lossy
     * format in the resolved `formats` list — mirrors the strict pattern
     * used for `width_calculator` (PR #38). PNG/GIF are exempt because
     * their encoders don't take a quality parameter.
     */
    protected function assertQualityShape(int|array $quality, array $formats): void
    {
        if (is_int($quality)) {
            return;
        }

        $lossy = array_map(fn (MediaFormat $f) => $f->value, MediaFormat::lossyResponsiveFormats());
        $required = array_intersect(array_map('strtolower', $formats), $lossy);
        $missing = array_diff($required, array_keys($quality));

        if (! empty($missing)) {
            throw new InvalidArgumentException(sprintf(
                'Per-format quality is missing entries for [%s]. When `responsive_images.quality` is an array, every lossy format in `formats` must be declared (lossy: %s).',
                implode(', ', $missing),
                implode(', ', $lossy),
            ));
        }
    }
}
