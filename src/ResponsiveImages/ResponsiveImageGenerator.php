<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Exceptions\MediaFileWriteFailed;
use Emaia\MediaMan\Exceptions\ResponsiveFormatNotSupported;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ResponsiveImageGenerator
{
    public const string IN_PROGRESS_MARKER = '.mediaman-in-progress';

    protected ImageManager $imageManager;

    protected WidthCalculator $widthCalculator;

    public function __construct(ImageManager $imageManager, WidthCalculator $widthCalculator)
    {
        $this->imageManager = $imageManager;
        $this->widthCalculator = $widthCalculator;
    }

    public function generateResponsiveImages(Media $media, array $options = []): void
    {
        $generationConfig = ResponsiveGenerationConfig::fromConfig();

        if (! $media->isRasterImage()) {
            return;
        }

        if (
            $media->hasCustomProperty(Media::PROPERTY_RESPONSIVE_CLEARING)
            || $media->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)
            || $media->hasCustomProperty(Media::PROPERTY_RESPONSIVE_DELETING)
        ) {
            throw new RuntimeException("Media [{$media->getKey()}] has a conflicting responsive lifecycle operation.");
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

        $originalImage = $this->imageManager->decode($originalBytes);
        $widths = $widths->filter(fn ($width) => $width <= $originalImage->width());

        if ($widths->isEmpty() && $generationConfig->isVersioned()) {
            return;
        }

        $resolver = app(MediaResolver::class);
        $baseDirectory = $resolver->pathForResponsive($media);
        $generation = $generationConfig->isVersioned()
            ? strtoupper((string) Str::ulid())
            : null;
        $outputDirectory = $generation === null
            ? $baseDirectory
            : $baseDirectory.'/'.$generation;
        $responsiveDisk = $media->responsiveDisk();
        $responsiveFilesystem = Storage::disk($responsiveDisk);
        $snapshot = [
            'file_name' => $media->file_name,
            'disk' => $media->disk,
            'responsive_disk' => $responsiveDisk,
            'base_directory' => $baseDirectory,
            'epoch' => (int) $media->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH, 0),
        ];
        $responsiveData = [];

        try {
            if ($generation !== null) {
                $markerPath = $outputDirectory.'/'.self::IN_PROGRESS_MARKER;
                $marker = json_encode([
                    'media_key' => $media->getKey(),
                    'generation' => $generation,
                    'started_at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR);

                if (! $responsiveFilesystem->put($markerPath, $marker)) {
                    throw MediaFileWriteFailed::forPath($markerPath, $responsiveDisk);
                }
            }

            foreach ($widths as $targetWidth) {
                foreach ($formats as $format) {
                    try {
                        $responsiveData[] = $this->generateSingleResponsiveImage(
                            $media,
                            clone $originalImage,
                            $targetWidth,
                            $format,
                            $this->resolveQuality($format, $quality),
                            $outputDirectory,
                            $responsiveDisk,
                        );
                    } catch (ImageException|ResponsiveFormatNotSupported|InvalidArgumentException $e) {
                        Log::warning('MediaMan: Skipping responsive format — driver does not support encoding', [
                            'mediaId' => $media->id,
                            'format' => $format,
                            'width' => $targetWidth,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            if ($responsiveData === [] && $generation !== null) {
                throw new RuntimeException("Responsive generation [$generation] produced no variants.");
            }

            foreach ($responsiveData as $item) {
                if (! $responsiveFilesystem->exists($item['path'])) {
                    throw new RuntimeException("Responsive image [{$item['path']}] disappeared before publication.");
                }
            }

            $published = $this->publishManifest($media, $responsiveData, $generation, $snapshot);
            $media->setRawAttributes($published->getAttributes(), true);

            if ($generation !== null) {
                $this->removeMarker($responsiveFilesystem, $outputDirectory.'/'.self::IN_PROGRESS_MARKER);
            }
        } catch (Throwable $e) {
            if ($generation !== null) {
                $this->cleanupUnpublishedGeneration($media, $generation, $outputDirectory, $responsiveDisk);
            }

            throw $e;
        }
    }

    protected function generateSingleResponsiveImage(
        Media $media,
        ImageInterface $image,
        int $targetWidth,
        string $format,
        int $quality,
        string $directory,
        string $disk,
    ): array {
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
            throw new ResponsiveFormatNotSupported(
                "Encoder for [$format] returned zero bytes — the driver likely lacks support (e.g. imagick without libheif for HEIC)."
            );
        }

        $fileName = app(MediaResolver::class)->responsiveFileName($media->file_name, $targetWidth, $format);
        $path = $directory.'/'.$fileName;
        $filesystem = Storage::disk($disk);

        if (! $filesystem->put($path, $encodedImage->toStream())) {
            throw MediaFileWriteFailed::forPath($path, $disk);
        }

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
     * @param  array<int, array{width: int, height: int, format: string, path: string, url: string, size: int}>  $responsiveData
     * @param  array{file_name: string, disk: string, responsive_disk: string, base_directory: string, epoch: int}  $snapshot
     */
    protected function publishManifest(Media $media, array $responsiveData, ?string $generation, array $snapshot): Media
    {
        return DB::connection($media->getConnectionName())->transaction(function () use (
            $media,
            $responsiveData,
            $generation,
            $snapshot,
        ): Media {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw new RuntimeException("Media [{$media->getKey()}] no longer exists.");
            }

            $resolver = app(MediaResolver::class);
            $freshEpoch = (int) $fresh->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH, 0);

            if (
                $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_CLEARING)
                || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)
                || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_DELETING)
            ) {
                throw new RuntimeException("Media [{$media->getKey()}] has a conflicting responsive lifecycle operation.");
            }

            $pruning = $fresh->getCustomProperty(Media::PROPERTY_RESPONSIVE_PRUNING, []);

            if ($generation !== null && is_array($pruning) && array_key_exists($generation, $pruning)) {
                throw new RuntimeException("Responsive generation [$generation] is being pruned.");
            }

            if (
                $fresh->file_name !== $snapshot['file_name']
                || $fresh->disk !== $snapshot['disk']
                || $fresh->responsiveDisk() !== $snapshot['responsive_disk']
                || $resolver->pathForResponsive($fresh) !== $snapshot['base_directory']
                || $freshEpoch !== $snapshot['epoch']
            ) {
                throw new RuntimeException("Media [{$media->getKey()}] changed while responsive images were being generated.");
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $properties[Media::PROPERTY_RESPONSIVE_IMAGES] = $responsiveData;

            if ($generation === null) {
                unset(
                    $properties[Media::PROPERTY_RESPONSIVE_GENERATION],
                    $properties[Media::PROPERTY_RESPONSIVE_GENERATION_DISK],
                );
            } else {
                $properties[Media::PROPERTY_RESPONSIVE_GENERATION] = $generation;
                $properties[Media::PROPERTY_RESPONSIVE_GENERATION_DISK] = $snapshot['responsive_disk'];
                $knownDisks = $properties[Media::PROPERTY_RESPONSIVE_GENERATION_DISKS] ?? [];
                $knownDisks = is_array($knownDisks) ? $knownDisks : [];
                $knownDisks[] = $snapshot['responsive_disk'];
                $properties[Media::PROPERTY_RESPONSIVE_GENERATION_DISKS] = array_values(array_unique(array_filter(
                    $knownDisks,
                    fn ($disk) => is_string($disk) && $disk !== '',
                )));
            }

            $fresh->custom_properties = $properties;
            $fresh->save();

            return $fresh;
        });
    }

    protected function cleanupUnpublishedGeneration(Media $media, string $generation, string $directory, string $disk): void
    {
        try {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->useWritePdo()->whereKey($media->getKey())->first();

            if ($fresh?->getCustomProperty(Media::PROPERTY_RESPONSIVE_GENERATION) === $generation) {
                return;
            }
        } catch (Throwable $e) {
            Log::warning('MediaMan: Could not verify failed responsive generation state; retaining files', [
                'mediaId' => $media->getKey(),
                'generation' => $generation,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            if (! Storage::disk($disk)->deleteDirectory($directory)) {
                Log::warning('MediaMan: Failed to clean unpublished responsive generation', [
                    'mediaId' => $media->getKey(),
                    'generation' => $generation,
                    'disk' => $disk,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('MediaMan: Failed to clean unpublished responsive generation', [
                'mediaId' => $media->getKey(),
                'generation' => $generation,
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function removeMarker(Filesystem $filesystem, string $path): void
    {
        try {
            if (! $filesystem->delete($path)) {
                Log::warning('MediaMan: Failed to remove responsive generation marker', ['path' => $path]);
            }
        } catch (Throwable $e) {
            Log::warning('MediaMan: Failed to remove responsive generation marker', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear all responsive images for a media item.
     */
    public function clearResponsiveImages(Media $media): void
    {
        [$fresh, $clearState] = DB::connection($media->getConnectionName())->transaction(
            function () use ($media): array {
                /** @var Media|null $fresh */
                $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

                if ($fresh === null) {
                    throw new RuntimeException("Media [{$media->getKey()}] no longer exists.");
                }

                if (
                    $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)
                    || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_DELETING)
                ) {
                    throw new RuntimeException("Media [{$media->getKey()}] has a conflicting responsive lifecycle operation.");
                }

                $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
                $clearState = $properties[Media::PROPERTY_RESPONSIVE_CLEARING] ?? null;
                $resolvedBase = app(MediaResolver::class)->pathForResponsive($fresh);
                $persistedDisk = $properties[Media::PROPERTY_RESPONSIVE_GENERATION_DISK] ?? null;
                $allowedDisks = array_values(array_unique(array_filter([
                    is_string($persistedDisk) && $persistedDisk !== '' ? $persistedDisk : null,
                    $fresh->responsiveDisk(),
                    ...$fresh->responsiveGenerationDisks(),
                ])));

                if (! is_array($clearState) || ! isset($clearState['token'], $clearState['base_path'], $clearState['disks'])) {
                    $clearState = [
                        'token' => strtoupper((string) Str::ulid()),
                        'base_path' => $resolvedBase,
                        'disks' => $allowedDisks,
                        'started_at' => now()->toIso8601String(),
                    ];
                    $clearState['signature'] = self::signClearState($fresh, $clearState);
                } elseif (! self::isValidClearState($fresh, $clearState)) {
                    throw new RuntimeException('Responsive clear state is invalid; refusing storage cleanup.');
                }

                $properties[Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH] =
                    ((int) ($properties[Media::PROPERTY_RESPONSIVE_GENERATION_EPOCH] ?? 0)) + 1;
                $properties[Media::PROPERTY_RESPONSIVE_CLEARING] = $clearState;
                unset(
                    $properties[Media::PROPERTY_RESPONSIVE_IMAGES],
                    $properties[Media::PROPERTY_RESPONSIVE_GENERATION],
                    $properties[Media::PROPERTY_RESPONSIVE_GENERATION_DISK],
                    $properties[Media::PROPERTY_RESPONSIVE_GENERATION_DISKS],
                );

                $fresh->custom_properties = $properties;
                $fresh->save();

                return [
                    $fresh,
                    $clearState,
                ];
            }
        );

        $media->setRawAttributes($fresh->getAttributes(), true);

        foreach ($clearState['disks'] as $disk) {
            if (! is_string($disk) || $disk === '') {
                throw new RuntimeException('Responsive clear state contains an invalid disk.');
            }

            $filesystem = Storage::disk($disk);
            $responsiveDir = $clearState['base_path'];

            if ($filesystem->exists($responsiveDir) && ! $filesystem->deleteDirectory($responsiveDir)) {
                throw new RuntimeException("Failed to delete responsive images at [$responsiveDir] on disk [$disk].");
            }
        }

        $published = DB::connection($media->getConnectionName())->transaction(function () use ($media, $clearState): Media {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw new RuntimeException("Media [{$media->getKey()}] no longer exists.");
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $currentState = $properties[Media::PROPERTY_RESPONSIVE_CLEARING] ?? null;

            if (is_array($currentState) && ($currentState['token'] ?? null) === $clearState['token']) {
                unset($properties[Media::PROPERTY_RESPONSIVE_CLEARING]);
                $fresh->custom_properties = $properties;
                $fresh->save();
            }

            return $fresh;
        });

        $media->setRawAttributes($published->getAttributes(), true);
    }

    /** Validate that a clear tombstone was issued for this exact media record. */
    public static function isValidClearState(Media $media, array $state): bool
    {
        if (! is_array($state['disks'] ?? null)) {
            return false;
        }

        foreach ($state['disks'] as $disk) {
            if (! is_string($disk) || $disk === '') {
                return false;
            }
        }

        return isset($state['signature'], $state['started_at'])
            && is_string($state['signature'])
            && is_string($state['token'] ?? null)
            && is_string($state['base_path'] ?? null)
            && $state['base_path'] !== ''
            && ! str_contains($state['base_path'], '..')
            && is_string($state['started_at'])
            && hash_equals(self::signClearState($media, $state), $state['signature']);
    }

    /** @param array{token: string, base_path: string, disks: array, started_at: string, signature?: string} $state */
    protected static function signClearState(Media $media, array $state): string
    {
        return hash_hmac('sha256', json_encode([
            'model' => $media::class,
            'table' => $media->getTable(),
            'key' => (string) $media->getKey(),
            'token' => $state['token'],
            'base_path' => $state['base_path'],
            'disks' => array_values($state['disks']),
            'started_at' => $state['started_at'],
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
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
