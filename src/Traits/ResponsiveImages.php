<?php

namespace Emaia\MediaMan\Traits;

use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Exception;
use Illuminate\Support\Collection as BaseCollection;
use Intervention\Image\ImageManager;

trait ResponsiveImages
{

    public function generateResponsiveImages(array $options = []): self
    {
        if (config('mediaman.responsive_images.queue', true)) {
            GenerateResponsiveImages::dispatch($this, $options);
        } else {
            app(ResponsiveImageGenerator::class)
                ->generateResponsiveImages($this, $options);
        }

        return $this;
    }

    /**
     * Get responsive images data.
     */
    public function getResponsiveImages(): BaseCollection
    {
        $responsiveData = $this->getCustomProperty('responsive_images') ?? [];

        return collect($responsiveData)->map(function ($item) {
            return (object) $item;
        });
    }

    /**
     * Get responsive images by format.
     */
    public function getResponsiveImagesByFormat(string $format): BaseCollection
    {
        return $this->getResponsiveImages()->where('format', $format);
    }

    /**
     * Generate srcset string for HTML.
     */
    public function getSrcset(string $format = ''): string
    {
        if (!$this->isOfType('image')) {
            return '';
        }

        // If no format specified, try to determine the best format
        if (empty($format)) {
            $availableFormats = $this->getAvailableResponsiveFormats();
            $format = $availableFormats[0] ?? 'original';
        }

        // Handle original format (no responsive images)
        if ($format === 'original' || !$this->hasResponsiveImages()) {
            $originalWidth = $this->getImageWidth();
            return $originalWidth > 0 ? $this->getUrl() . ' ' . $originalWidth . 'w' : $this->getUrl();
        }

        // Get responsive images for the specified format
        $images = $this->getResponsiveImagesByFormat($format);

        if ($images->isEmpty()) {
            // Fallback to original
            $originalWidth = $this->getImageWidth();
            return $originalWidth > 0 ? $this->getUrl() . ' ' . $originalWidth . 'w' : $this->getUrl();
        }

        return $images
            ->sortByDesc('width')
            ->map(fn($img) => $img->url . ' ' . $img->width . 'w')
            ->implode(', ');
    }

    /**
     * Get available responsive image formats.
     */
    public function getAvailableResponsiveFormats(): array
    {
        if (!$this->hasResponsiveImages()) {
            return ['original'];
        }

        return $this->getResponsiveImages()
            ->pluck('format')
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Get picture element HTML with multiple formats.
     */
    public function getPictureHtml(array $attributes = []): string
    {
        if (!$this->isOfType('image')) {
            return '';
        }

        $availableFormats = $this->getAvailableResponsiveFormats();

        // If no responsive images, return simple img tag
        if (empty($availableFormats) || $availableFormats === ['original']) {
            return $this->getSimpleImgHtml($attributes);
        }

        $sources = [];

        // Define format priority (modern formats first)
        $formatPriority = ['avif', 'webp', 'jpg', 'jpeg', 'png', 'gif'];

        // Sort available formats by priority
        $sortedFormats = [];
        foreach ($formatPriority as $priorityFormat) {
            if (in_array($priorityFormat, $availableFormats)) {
                $sortedFormats[] = $priorityFormat;
            }
        }

        // Add any remaining formats
        foreach ($availableFormats as $format) {
            if (!in_array($format, $sortedFormats)) {
                $sortedFormats[] = $format;
            }
        }

        // Generate source elements (exclude the last format for fallback)
        $sourceFormats = array_slice($sortedFormats, 0, -1);
        foreach ($sourceFormats as $format) {
            $srcset = $this->getSrcset($format);
            if ($srcset) {
                $mimeType = $this->formatToMimeType($format);
                $sources[] = "<source type=\"$mimeType\" srcset=\"$srcset\">";
            }
        }

        // Use the last format as fallback (or original if no responsive images)
        $fallbackFormat = end($sortedFormats);
        $fallbackSrcset = $this->getSrcset($fallbackFormat);

        // Generate img attributes
        $defaultAttributes = [
            'src' => $this->getUrl(),
            'alt' => $this->name ?? '',
        ];

        if ($fallbackSrcset) {
            $defaultAttributes['srcset'] = $fallbackSrcset;
        }

        $imgAttributes = array_merge($defaultAttributes, $attributes);
        $imgAttributesString = $this->attributesToString($imgAttributes);

        // Generate picture element
        if (empty($sources)) {
            return "<img $imgAttributesString>";
        }

        $sourcesString = implode("\n    ", $sources);

        return "<picture>\n    $sourcesString\n    <img $imgAttributesString>\n</picture>";
    }

    /**
     * Get simple img tag HTML.
     */
    public function getSimpleImgHtml(array $attributes = []): string
    {
        if (!$this->isOfType('image')) {
            return '';
        }

        $defaultAttributes = [
            'src' => $this->getUrl(),
            'alt' => $this->name ?? '',
        ];

        $imgAttributes = array_merge($defaultAttributes, $attributes);
        $imgAttributesString = $this->attributesToString($imgAttributes);

        return "<img $imgAttributesString>";
    }

    /**
     * Convert format to MIME type.
     */
    protected function formatToMimeType(string $format): string
    {
        return match(strtolower($format)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'tiff', 'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            default => 'image/' . $format,
        };
    }

    /**
     * Convert attributes array to HTML string.
     */
    protected function attributesToString(array $attributes): string
    {
        return collect($attributes)
            ->filter(fn($value) => $value !== null && $value !== '')
            ->map(fn($value, $key) => $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"')
            ->implode(' ');
    }

    /**
     * Check if responsive images have been generated.
     */
    public function hasResponsiveImages(): bool
    {
        return !empty($this->getCustomProperty('responsive_images') ?? []);
    }

    /**
     * Clear responsive images.
     */
    public function clearResponsiveImages(): self
    {
        app(ResponsiveImageGenerator::class)
            ->clearResponsiveImages($this);

        return $this;
    }

    /**
     * Get the optimal responsive image for a given width.
     */
    public function getResponsiveImageForWidth(int $targetWidth, string $format = ''): ?object
    {
        if (!$this->hasResponsiveImages()) {
            return null;
        }

        // If no format specified, use the first available format
        if (empty($format)) {
            $availableFormats = $this->getAvailableResponsiveFormats();
            $format = $availableFormats[0] ?? '';
        }

        if (empty($format) || $format === 'original') {
            return null;
        }

        return $this->getResponsiveImagesByFormat($format)
            ->filter(fn($img) => $img->width >= $targetWidth)
            ->sortBy('width')
            ->first();
    }

    /**
     * Get the best format available for responsive images.
     * Prioritizes modern formats like AVIF and WebP.
     */
    public function getBestResponsiveFormat(): string
    {
        $availableFormats = $this->getAvailableResponsiveFormats();

        if (empty($availableFormats) || $availableFormats === ['original']) {
            return 'original';
        }

        // Priority order: AVIF > WebP > JPEG > PNG > others
        $preferredOrder = ['avif', 'webp', 'jpg', 'jpeg', 'png'];

        foreach ($preferredOrder as $preferredFormat) {
            if (in_array($preferredFormat, $availableFormats)) {
                return $preferredFormat;
            }
        }

        // Return first available if no preferred format found
        return $availableFormats[0];
    }

    /**
     * Get responsive image URL for specific width and format.
     */
    public function getResponsiveUrl(int $width = 0, string $format = ''): string
    {
        if (!$this->isOfType('image')) {
            return $this->getUrl();
        }

        // If no responsive images available, return original
        if (!$this->hasResponsiveImages()) {
            return $this->getUrl();
        }

        // Use best format if none specified
        if (empty($format)) {
            $format = $this->getBestResponsiveFormat();
        }

        // Return original if format is 'original' or not found
        if ($format === 'original') {
            return $this->getUrl();
        }

        // If no specific width requested, get the largest available
        if ($width <= 0) {
            $largest = $this->getResponsiveImagesByFormat($format)
                ->sortByDesc('width')
                ->first();

            return $largest ? $largest->url : $this->getUrl();
        }

        // Find optimal image for requested width
        $optimal = $this->getResponsiveImageForWidth($width, $format);

        return $optimal ? $optimal->url : $this->getUrl();
    }

    /**
     * Check if a specific format is available in responsive images.
     */
    public function hasResponsiveFormat(string $format): bool
    {
        return in_array($format, $this->getAvailableResponsiveFormats());
    }

    /**
     * Get responsive images grouped by format.
     */
    public function getResponsiveImagesByFormatGrouped(): array
    {
        if (!$this->hasResponsiveImages()) {
            return [];
        }

        return $this->getResponsiveImages()
            ->groupBy('format')
            ->toArray();
    }

    /**
     * Get image width (for images only).
     */
    public function getImageWidth(): int
    {
        if (!$this->isOfType('image')) {
            return 0;
        }

        // Try to get from responsive images data first
        $responsiveImages = $this->getResponsiveImages();
        if ($responsiveImages->isNotEmpty()) {
            return $responsiveImages->max('width');
        }

        // Check if we have dimensions stored in data
        if ($this->getCustomProperty('width') !== null) {
            return (int) $this->getCustomProperty('width');
        }

        // Fallback: read from original file
        return $this->calculateImageDimensions()['width'] ?? 0;
    }

    /**
     * Get image height (for images only).
     */
    public function getImageHeight(): int
    {
        if (!$this->isOfType('image')) {
            return 0;
        }

        // Try to get from responsive images data first
        $responsiveImages = $this->getResponsiveImages();
        if ($responsiveImages->isNotEmpty()) {
            // Find the original dimensions (largest width)
            $largestImage = $responsiveImages->where('width', $this->getImageWidth())->first();
            return $largestImage ? $largestImage->height : 0;
        }

        // Check if we have dimensions stored in data
        if ($this->getCustomProperty('height') !== null) {
            return (int) $this->getCustomProperty('height');
        }

        // Fallback: read from original file
        return $this->calculateImageDimensions()['height'] ?? 0;
    }

    /**
     * Calculate and cache image dimensions.
     */
    protected function calculateImageDimensions(): array
    {
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'mediaman_dimensions');
            file_put_contents($tempFile, $this->filesystem()->get($this->getOriginalPath()));

            $imageManager = ImageManager::imagick() ?? ImageManager::gd();
            $image = $imageManager->read($tempFile);
            $dimensions = [
                'width' => $image->width(),
                'height' => $image->height()
            ];

            unlink($tempFile);

            $this->setCustomProperty('dimensions', $dimensions);
            $this->save();

            return $dimensions;
        } catch (Exception $e) {
            return ['width' => 0, 'height' => 0];
        }
    }
}