<?php

namespace Emaia\MediaMan\Traits;

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Exception;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Log;
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
     * Render a `<picture>` element wrapping the media. Every responsive
     * format becomes a `<source>` (modern formats first); the inner `<img>`
     * always points at the original file as the browser-agnostic fallback.
     *
     * The wrapper is emitted even when no responsive variants exist — the
     * markup shape stays consistent (`<picture><img></picture>`) and callers
     * can rely on it. `getSimpleImgHtml()` remains the explicit escape hatch
     * for contexts that need a bare `<img>` (email templates, etc.).
     */
    public function getPictureHtml(array $attributes = [], $sizes = null): string
    {
        if (! $this->isOfType(MediaType::IMAGE)) {
            return '';
        }

        [$injectPlaceholder, $attributes] = $this->extractPlaceholderOption($attributes);

        $defaultAttributes = $this->setDefaultImgAttributes($sizes);

        $sources = $this->buildResponsiveSourceElements($defaultAttributes['sizes'] ?? null);

        // The <img> fallback always carries the original file. Its srcset
        // declares the original at its native width so browsers that ignore
        // every <source> still receive a width-aware candidate.
        $originalWidth = $this->getImageWidth();
        if ($originalWidth > 0) {
            $defaultAttributes['srcset'] = $this->getUrl().' '.$originalWidth.'w';
        }

        $imgAttributes = array_merge($defaultAttributes, $attributes);
        $imgAttributes = $this->applyPlaceholderStyle($imgAttributes, $injectPlaceholder);
        $imgAttributesString = $this->attributesToString($imgAttributes);

        if (empty($sources)) {
            return "<picture>\n    <img $imgAttributesString>\n</picture>";
        }

        $sourcesString = implode("\n    ", $sources);

        return "<picture>\n    $sourcesString\n    <img $imgAttributesString>\n</picture>";
    }

    /**
     * Build one `<source>` per responsive format, ordered modern-first.
     * Returns an empty array when no responsive variants exist.
     */
    protected function buildResponsiveSourceElements(?string $sizes): array
    {
        $availableFormats = $this->getAvailableResponsiveFormats();

        if (empty($availableFormats) || $availableFormats === ['original']) {
            return [];
        }

        $formatPriority = array_map(fn (MediaFormat $f) => $f->value, MediaFormat::responsiveFormats());

        $sortedFormats = [];
        foreach ($formatPriority as $priorityFormat) {
            if (in_array($priorityFormat, $availableFormats)) {
                $sortedFormats[] = $priorityFormat;
            }
        }

        foreach ($availableFormats as $format) {
            if (! in_array($format, $sortedFormats)) {
                $sortedFormats[] = $format;
            }
        }

        $sources = [];
        foreach ($sortedFormats as $format) {
            $srcset = $this->getSrcset($format);
            if (! $srcset) {
                continue;
            }

            $mimeType = $this->formatToMimeType($format);
            $sourceHtml = "<source type=\"{$mimeType}\" srcset=\"{$srcset}\"";
            if (! empty($sizes)) {
                $sourceHtml .= " sizes=\"{$sizes}\"";
            }
            $sourceHtml .= '>';

            $sources[] = $sourceHtml;
        }

        return $sources;
    }

    /**
     * Pop the `placeholder` key out of the attributes array. Returns
     * [bool $shouldInject, array $remainingAttributes] so callers can
     * decide whether to add the blur background later without leaking
     * the option into the rendered HTML.
     */
    protected function extractPlaceholderOption(array $attributes): array
    {
        $shouldInject = $attributes['placeholder'] ?? true;
        unset($attributes['placeholder']);

        return [(bool) $shouldInject, $attributes];
    }

    /**
     * Append the LQIP placeholder as a CSS background-image on the <img>
     * attributes when one was generated and the call opted in.
     */
    protected function applyPlaceholderStyle(array $attributes, bool $shouldInject): array
    {
        if (! $shouldInject) {
            return $attributes;
        }

        $placeholder = $this->getPlaceholder();

        if ($placeholder === null) {
            return $attributes;
        }

        $background = "background-image:url('{$placeholder}');background-size:cover;background-position:center;";
        $existing = trim($attributes['style'] ?? '');

        if ($existing !== '') {
            $existing = rtrim($existing, ';').';';
        }

        $attributes['style'] = $existing.$background;

        return $attributes;
    }

    /**
     * Get available responsive image formats.
     */
    public function getAvailableResponsiveFormats(): array
    {
        if (! $this->hasResponsiveImages()) {
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
     * Check if responsive images have been generated.
     */
    public function hasResponsiveImages(): bool
    {
        return ! empty($this->getCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES) ?? []);
    }

    /**
     * Get responsive images data.
     */
    public function getResponsiveImages(): BaseCollection
    {
        $responsiveData = $this->getCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES) ?? [];

        return collect($responsiveData)->map(function ($item) {
            return (object) $item;
        });
    }

    /**
     * Get simple img tag HTML.
     */
    public function getSimpleImgHtml(array $attributes = [], $sizes = null): string
    {
        if (! $this->isOfType(MediaType::IMAGE)) {
            return '';
        }

        [$injectPlaceholder, $attributes] = $this->extractPlaceholderOption($attributes);

        $imgAttributes = array_merge($this->setDefaultImgAttributes($sizes), $attributes);
        $imgAttributes = $this->applyPlaceholderStyle($imgAttributes, $injectPlaceholder);
        $imgAttributesString = $this->attributesToString($imgAttributes);

        return "<img $imgAttributesString>";
    }

    public function setDefaultImgAttributes(mixed $sizes): array
    {
        $defaultAttributes = [
            'src' => $this->getUrl(),
            'alt' => $this->name ?? '',
        ];

        if (! empty($sizes)) {
            if ($sizes === 'auto') {

                $sizesFromGeneratedResponsiveImages = $this->getResponsiveImages();

                $minWidth = $sizesFromGeneratedResponsiveImages->pluck('width')->min();
                $minHeight = $sizesFromGeneratedResponsiveImages->pluck('height')->min();

                $defaultAttributes['width'] = $minWidth;
                $defaultAttributes['height'] = $minHeight;

                $sizes = $sizesFromGeneratedResponsiveImages->pluck('width')->slice(0, -1)->map(function ($w) {
                    return '(min-width: '.$w * 0.7.'px) '.$w.'px';
                })->push($sizesFromGeneratedResponsiveImages->pluck('width')->last() * 0.7.'px')->implode(', ');
            }

            $defaultAttributes['sizes'] = $sizes;
        }

        return $defaultAttributes;
    }

    /**
     * Convert attributes array to HTML string.
     */
    protected function attributesToString(array $attributes): string
    {
        return collect($attributes)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $key) => $key.'="'.htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8').'"')
            ->implode(' ');
    }

    /**
     * Generate srcset string for HTML.
     */
    public function getSrcset(string $format = ''): string
    {
        if (! $this->isOfType(MediaType::IMAGE)) {
            return '';
        }

        // If no format specified, try to determine the best format
        if (empty($format)) {
            $availableFormats = $this->getAvailableResponsiveFormats();
            $format = $availableFormats[0] ?? 'original';
        }

        // Handle original format (no responsive images)
        if ($format === 'original' || ! $this->hasResponsiveImages()) {
            $originalWidth = $this->getImageWidth();

            return $originalWidth > 0 ? $this->getUrl().' '.$originalWidth.'w' : $this->getUrl();
        }

        // Get responsive images for the specified format
        $images = $this->getResponsiveImagesByFormat($format);

        if ($images->isEmpty()) {
            // Fallback to original
            $originalWidth = $this->getImageWidth();

            return $originalWidth > 0 ? $this->getUrl().' '.$originalWidth.'w' : $this->getUrl();
        }

        return $images
            ->filter(fn ($img) => ! empty($img->url) && (int) $img->width > 0)
            ->sortByDesc('width')
            ->map(fn ($img) => $img->url.' '.$img->width.'w')
            ->implode(', ');
    }

    /**
     * Get image width (for images only).
     */
    public function getImageWidth(): int
    {
        if (! $this->isOfType(MediaType::IMAGE)) {
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
     * Calculate and cache image dimensions.
     */
    protected function calculateImageDimensions(): array
    {
        try {
            $image = app(ImageManager::class)
                ->decode($this->filesystem()->get($this->getOriginalPath()));

            $dimensions = [
                'width' => $image->width(),
                'height' => $image->height(),
            ];

            $this->setCustomProperty(Media::PROPERTY_DIMENSIONS, $dimensions);
            $this->save();

            return $dimensions;
        } catch (Exception $e) {
            Log::warning('MediaMan: Failed to calculate image dimensions', [
                'media_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            return ['width' => 0, 'height' => 0];
        }
    }

    /**
     * Get responsive images by format.
     */
    public function getResponsiveImagesByFormat(string $format): BaseCollection
    {
        return $this->getResponsiveImages()->where('format', $format);
    }

    /**
     * Convert format to MIME type.
     */
    protected function formatToMimeType(string $format): string
    {
        $mediaFormat = MediaFormat::tryFromValue($format);

        if ($mediaFormat) {
            return $mediaFormat->mimeType();
        }

        // Handle 'tif' alias
        if (strtolower($format) === 'tif') {
            return MediaFormat::TIFF->mimeType();
        }

        return 'image/'.$format;
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
     * Get responsive image URL for specific width and format.
     */
    public function getResponsiveUrl(int $width = 0, string $format = ''): string
    {
        if (! $this->isOfType(MediaType::IMAGE)) {
            return $this->getUrl();
        }

        if (! $this->hasResponsiveImages()) {
            return $this->getUrl();
        }

        if (empty($format)) {
            $format = $this->getBestResponsiveFormat();
        }

        if ($format === 'original') {
            return $this->getUrl();
        }

        if ($width <= 0) {
            $largest = $this->getResponsiveImagesByFormat($format)
                ->sortByDesc('width')
                ->first();

            return $largest ? $largest->url : $this->getUrl();
        }

        $optimal = $this->getResponsiveImageForWidth($width, $format);

        return $optimal ? $optimal->url : $this->getUrl();
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

        $preferredOrder = array_map(fn (MediaFormat $f) => $f->value, MediaFormat::preferredOrder());

        foreach ($preferredOrder as $preferredFormat) {
            if (in_array($preferredFormat, $availableFormats)) {
                return $preferredFormat;
            }
        }

        return $availableFormats[0];
    }

    /**
     * Get the optimal responsive image for a given width.
     */
    public function getResponsiveImageForWidth(int $targetWidth, string $format = ''): ?object
    {
        if (! $this->hasResponsiveImages()) {
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
            ->filter(fn ($img) => $img->width >= $targetWidth)
            ->sortBy('width')
            ->first();
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
        if (! $this->hasResponsiveImages()) {
            return [];
        }

        return $this->getResponsiveImages()
            ->groupBy('format')
            ->toArray();
    }

    /**
     * Get image height (for images only).
     */
    public function getImageHeight(): int
    {
        if (! $this->isOfType(MediaType::IMAGE)) {
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
}
