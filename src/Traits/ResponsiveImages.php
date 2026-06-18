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
        $placeholderUri = $injectPlaceholder ? $this->getPlaceholder() : null;

        $defaultAttributes = $this->setDefaultImgAttributes($sizes);

        $sources = $this->buildResponsiveSourceElements($defaultAttributes['sizes'] ?? null, $placeholderUri);

        // The <img> fallback always carries the original file. Its srcset
        // declares the original at its native width so browsers that ignore
        // every <source> still receive a width-aware candidate; the LQIP
        // placeholder rides along as the lowest-width entry.
        $originalWidth = $this->getImageWidth();
        $imgSrcset = $originalWidth > 0 ? $this->getUrl().' '.$originalWidth.'w' : '';
        $imgSrcset = $this->injectPlaceholderIntoSrcset($imgSrcset, $placeholderUri);
        if ($imgSrcset !== '') {
            $defaultAttributes['srcset'] = $imgSrcset;
        }

        $imgAttributes = array_merge($defaultAttributes, $attributes);
        $imgAttributesString = $this->attributesToString($imgAttributes);

        if (empty($sources)) {
            return "<picture>\n    <img $imgAttributesString>\n</picture>";
        }

        $sourcesString = implode("\n    ", $sources);

        return "<picture>\n    $sourcesString\n    <img $imgAttributesString>\n</picture>";
    }

    /**
     * Build one `<source>` per responsive format, ordered modern-first.
     * Each source's srcset gets the LQIP placeholder appended as the lowest
     * `32w` entry when one was generated. Returns an empty array when no
     * responsive variants exist.
     */
    protected function buildResponsiveSourceElements(?string $sizes, ?string $placeholderUri): array
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

            $srcset = $this->injectPlaceholderIntoSrcset($srcset, $placeholderUri);

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
     * decide whether to add the placeholder to the srcset later without
     * leaking the option into the rendered HTML.
     */
    protected function extractPlaceholderOption(array $attributes): array
    {
        $shouldInject = $attributes['placeholder'] ?? true;
        unset($attributes['placeholder']);

        return [(bool) $shouldInject, $attributes];
    }

    /**
     * Append the LQIP placeholder as the lowest-width entry of a srcset
     * so the browser can pin the aspect ratio (via the SVG viewBox) and
     * surface the blurred preview while the real image is fetched.
     */
    protected function injectPlaceholderIntoSrcset(string $srcset, ?string $placeholderUri): string
    {
        if ($placeholderUri === null) {
            return $srcset;
        }

        $srcset = trim($srcset);
        $entry = $placeholderUri.' 32w';

        return $srcset === '' ? $entry : $srcset.', '.$entry;
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
        $placeholderUri = $injectPlaceholder ? $this->getPlaceholder() : null;

        $imgAttributes = array_merge($this->setDefaultImgAttributes($sizes), $attributes);

        if ($placeholderUri !== null) {
            $existingSrcset = (string) ($imgAttributes['srcset'] ?? '');
            if ($existingSrcset === '') {
                $width = $this->getImageWidth();
                if ($width > 0) {
                    $existingSrcset = $this->getUrl().' '.$width.'w';
                }
            }
            $imgAttributes['srcset'] = $this->injectPlaceholderIntoSrcset($existingSrcset, $placeholderUri);
        }

        $imgAttributesString = $this->attributesToString($imgAttributes);

        return "<img $imgAttributesString>";
    }

    public function setDefaultImgAttributes(mixed $sizes): array
    {
        $defaultAttributes = [
            'src' => $this->getUrl(),
            'alt' => $this->name ?? '',
            // decoding=async lets the browser decode the bitmap off the main
            // thread. Pure upside (no LCP penalty unlike loading=lazy), and
            // overridable by passing `decoding` in the call's $attributes.
            'decoding' => 'async',
        ];

        $width = $this->getImageWidth();
        $height = $this->getImageHeight();

        if ($width > 0 && $height > 0) {
            $defaultAttributes['width'] = $width;
            $defaultAttributes['height'] = $height;
        }

        if (! empty($sizes)) {
            if ($sizes === 'auto') {
                $widths = $this->getResponsiveImages()->pluck('width');

                $sizes = $widths->slice(0, -1)->map(function ($w) {
                    return '(min-width: '.$w * 0.7.'px) '.$w.'px';
                })->push($widths->last() * 0.7.'px')->implode(', ');
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

        $meta = $this->getCustomProperty(Media::PROPERTY_IMAGE_META);
        if (is_array($meta) && isset($meta['width'])) {
            return (int) $meta['width'];
        }

        $responsiveImages = $this->getResponsiveImages();
        if ($responsiveImages->isNotEmpty()) {
            return (int) $responsiveImages->max('width');
        }

        return $this->calculateImageDimensions()['width'] ?? 0;
    }

    /**
     * Lazy fallback when image_meta wasn't persisted at upload (pre-v2.13
     * records or non-MediaUploader-created models). Only computes width and
     * height — dominant_color requires the upload pipeline.
     */
    protected function calculateImageDimensions(): array
    {
        try {
            $image = app(ImageManager::class)
                ->decode($this->filesystem()->get($this->getOriginalPath()));

            $meta = [
                'width' => $image->width(),
                'height' => $image->height(),
            ];

            $this->setCustomProperty(Media::PROPERTY_IMAGE_META, $meta);
            $this->save();

            return $meta;
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

        $meta = $this->getCustomProperty(Media::PROPERTY_IMAGE_META);
        if (is_array($meta) && isset($meta['height'])) {
            return (int) $meta['height'];
        }

        $responsiveImages = $this->getResponsiveImages();
        if ($responsiveImages->isNotEmpty()) {
            $largestImage = $responsiveImages->where('width', $this->getImageWidth())->first();

            return $largestImage ? (int) $largestImage->height : 0;
        }

        return $this->calculateImageDimensions()['height'] ?? 0;
    }
}
