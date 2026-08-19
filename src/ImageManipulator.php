<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Conversions\ConversionGenerationConfig;
use Emaia\MediaMan\Conversions\ConversionManifest;
use Emaia\MediaMan\Conversions\ConversionPath;
use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Exceptions\ConversionFormatNotSupported;
use Emaia\MediaMan\Exceptions\MediaFileWriteFailed;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\EncodedImage;
use Intervention\Image\Exceptions\EncoderException;
use Intervention\Image\Exceptions\StreamException;
use Intervention\Image\Image;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

class ImageManipulator
{
    public const string IN_PROGRESS_MARKER = '.mediaman-in-progress';

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
     * @return array{completed: array<int, string>, failed: array<int, array{conversion: string, exception: Throwable}>}
     */
    public function manipulate(Media $media, array $conversions, bool $onlyIfMissing = true): array
    {
        $generationConfig = ConversionGenerationConfig::fromConfig();
        $report = ['completed' => [], 'failed' => []];

        if (! $media->isRasterImage()) {
            return $report;
        }

        if (! $generationConfig->isVersioned()) {
            $transitions = [];
            $coordinateLifecycle = $media->hasCustomProperty(Media::PROPERTY_CONVERSION_FILES)
                || $media->hasCustomProperty(Media::PROPERTY_CONVERSION_GENERATION_EPOCHS)
                || $media->hasCustomProperty(Media::PROPERTY_CONVERSION_CLEARING);

            foreach ($conversions as $conversion) {
                try {
                    try {
                        ConversionPath::name($conversion);
                    } catch (\InvalidArgumentException) {
                        Log::warning('MediaMan: Unsafe legacy conversion name is deprecated', [
                            'conversion' => $conversion,
                        ]);
                    }

                    $snapshot = $this->runLegacyConversion(
                        $media,
                        $conversion,
                        $onlyIfMissing,
                        trackExisting: $coordinateLifecycle,
                    );
                    $report['completed'][] = $conversion;

                    if ($coordinateLifecycle) {
                        $transitions[$conversion] = $snapshot;
                    }
                } catch (Throwable $e) {
                    $report['failed'][] = [
                        'conversion' => $conversion,
                        'exception' => $e,
                    ];
                }
            }

            if ($transitions !== []) {
                try {
                    $published = $this->publishLegacyTransitions($media, $transitions);
                } catch (Throwable $e) {
                    foreach ($transitions as $conversion => $transition) {
                        if (
                            $transition['wrote']
                            && (
                                ! $transition['existed_before']
                                || $this->conversionEpochChanged($media, $conversion, $transition['epoch'])
                            )
                        ) {
                            Storage::disk($transition['write_disk'])->delete($transition['path']);
                        }
                    }

                    throw $e;
                }

                $media->setRawAttributes($published->getAttributes(), true);
            }

            return $report;
        }

        $candidates = [];

        foreach ($conversions as $conversion) {
            try {
                $candidate = $this->runVersionedConversion($media, $conversion, $onlyIfMissing);

                if ($candidate !== null) {
                    $candidates[$conversion] = $candidate;
                }
            } catch (Throwable $e) {
                $report['failed'][] = [
                    'conversion' => $conversion,
                    'exception' => $e,
                ];
            }
        }

        if ($candidates === []) {
            return $report;
        }

        try {
            $published = $this->publishVersionedConversions($media, $candidates);
            $media->setRawAttributes($published->getAttributes(), true);

            foreach ($candidates as $candidate) {
                $this->removeMarker(
                    Storage::disk($candidate['_snapshot']['write_disk']),
                    $candidate['_marker'],
                );
            }
        } catch (Throwable $e) {
            foreach ($candidates as $conversion => $candidate) {
                $this->cleanupUnpublishedGeneration(
                    $media,
                    $conversion,
                    $candidate['generation'],
                    $candidate['_directory'],
                    $candidate['_snapshot']['write_disk'],
                );
            }

            throw $e;
        }

        $report['completed'] = array_keys($candidates);

        return $report;
    }

    protected function conversionEpochChanged(Media $media, string $conversion, int $expectedEpoch): bool
    {
        try {
            $fresh = $media->newQuery()->useWritePdo()->whereKey($media->getKey())->first();
            $epochs = $fresh?->getCustomProperty(Media::PROPERTY_CONVERSION_GENERATION_EPOCHS, []);

            return is_array($epochs) && (int) ($epochs[$conversion] ?? 0) !== $expectedEpoch;
        } catch (Throwable $e) {
            Log::warning('MediaMan: Could not verify legacy conversion rollback epoch', [
                'mediaId' => $media->getKey(),
                'conversion' => $conversion,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Encode and persist a single conversion. Extracted so the per-iteration
     * try/catch in `manipulate()` covers every step (registry lookup, decode,
     * encode, write).
     *
     * @throws StreamException
     * @throws EncoderException
     */
    protected function runLegacyConversion(
        Media $media,
        string $conversion,
        bool $onlyIfMissing,
        bool $trackExisting,
    ): array {
        $snapshot = $this->snapshot($media, $conversion);
        $filesystem = $media->conversionWriteFilesystem($conversion);
        $existingPaths = $trackExisting && $filesystem->exists($snapshot['base_directory'])
            ? $filesystem->allFiles($snapshot['base_directory'])
            : [];
        $converter = $this->conversionRegistry->get($conversion);

        $image = $converter($this->imageManager->decode(
            $media->filesystem()->readStream($media->getOriginalPath())
        ));

        $snapshot['wrote'] = false;

        if ($image instanceof EncodedImage) {
            $extension = $this->getExtensionFromMimeType($image->mediaType());
            $path = $this->getConversionPathWithExtension($media, $conversion, $extension);
            $snapshot['path'] = $path;
            $existsNow = $filesystem->exists($path);
            $snapshot['existed_before'] = in_array($path, $existingPaths, true);

            if ($onlyIfMissing && $existsNow) {
                return $snapshot;
            }

            if (! $filesystem->put($path, $image->toStream())) {
                throw MediaFileWriteFailed::forPath($path, $snapshot['write_disk']);
            }

            $snapshot['wrote'] = true;

            return $snapshot;
        }

        if ($image instanceof Image) {
            // Pass the source MIME explicitly. Drivers handle the no-format
            // case inconsistently: notably vips+AVIF encodes to HEIC by
            // default because libheif picks HEVC as the default HEIF codec,
            // breaking the round-trip on both filename extension and
            // downstream browser support. Letting Intervention pick when
            // we already know the source format is asking for surprises.
            $encoded = $image->encodeUsingMediaType($media->mime_type);
            $extension = $this->getExtensionFromMimeType($encoded->mediaType());
            $path = $this->getConversionPathWithExtension($media, $conversion, $extension);
            $snapshot['path'] = $path;
            $existsNow = $filesystem->exists($path);
            $snapshot['existed_before'] = in_array($path, $existingPaths, true);

            if ($onlyIfMissing && $existsNow) {
                return $snapshot;
            }

            if (! $filesystem->put($path, $encoded->toStream())) {
                throw MediaFileWriteFailed::forPath($path, $snapshot['write_disk']);
            }

            $snapshot['wrote'] = true;
        }

        if (! isset($snapshot['path'])) {
            throw new RuntimeException("Conversion [$conversion] returned an unsupported value.");
        }

        return $snapshot;
    }

    /** @return array{generation: string, disk: string, path: string, format: string, file_name: string, mime_type: string, size: int, _directory: string, _marker: string, _snapshot: array{file_name: string, disk: string, write_disk: string, base_directory: string, epoch: int}}|null */
    protected function runVersionedConversion(Media $media, string $conversion, bool $onlyIfMissing): ?array
    {
        ConversionPath::name($conversion);

        if ($onlyIfMissing && $media->hasConversion($conversion)) {
            return null;
        }

        $snapshot = $this->snapshot($media, $conversion, validatePaths: true);
        $generation = strtoupper((string) Str::ulid());
        $directory = $snapshot['base_directory'].'/'.$generation;
        $markerPath = $directory.'/'.self::IN_PROGRESS_MARKER;
        $filesystem = Storage::disk($snapshot['write_disk']);

        try {
            $marker = json_encode([
                'media_key' => $media->getKey(),
                'conversion' => $conversion,
                'generation' => $generation,
                'started_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR);

            if (! $filesystem->put($markerPath, $marker)) {
                throw MediaFileWriteFailed::forPath($markerPath, $snapshot['write_disk']);
            }

            $converter = $this->conversionRegistry->get($conversion);
            $image = $converter($this->imageManager->decode(
                $media->filesystem()->readStream($media->getOriginalPath())
            ));
            $encoded = match (true) {
                $image instanceof EncodedImage => $image,
                $image instanceof Image => $image->encodeUsingMediaType($media->mime_type),
                default => throw new RuntimeException("Conversion [$conversion] returned an unsupported value."),
            };
            $mimeType = $encoded->mediaType();
            $extension = $this->getExtensionFromMimeType($mimeType);
            $fileName = ConversionPath::fileName(app(MediaResolver::class)->conversionFileName(
                $snapshot['file_name'],
                $conversion,
                $extension,
            ));
            $path = $directory.'/'.$fileName;
            $size = strlen((string) $encoded);

            if ($size === 0) {
                throw new ConversionFormatNotSupported("Conversion [$conversion] produced zero bytes.");
            }

            if (! $filesystem->put($path, $encoded->toStream())) {
                throw MediaFileWriteFailed::forPath($path, $snapshot['write_disk']);
            }

            return [
                'generation' => $generation,
                'disk' => $snapshot['write_disk'],
                'path' => $path,
                'format' => $extension,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'size' => $size,
                '_directory' => $directory,
                '_marker' => $markerPath,
                '_snapshot' => $snapshot,
            ];
        } catch (Throwable $e) {
            $this->cleanupUnpublishedGeneration(
                $media,
                $conversion,
                $generation,
                $directory,
                $snapshot['write_disk'],
            );

            throw $e;
        }
    }

    /** @return array{file_name: string, disk: string, write_disk: string, base_directory: string, epoch: int} */
    protected function snapshot(Media $media, string $conversion, bool $validatePaths = false): array
    {
        $epochs = $media->getCustomProperty(Media::PROPERTY_CONVERSION_GENERATION_EPOCHS, []);

        return [
            'file_name' => $media->file_name,
            'disk' => $media->disk,
            'write_disk' => $media->getConversionWriteDisk($conversion),
            'base_directory' => $validatePaths
                ? ConversionPath::directory(app(MediaResolver::class)->pathForConversion($media, $conversion))
                : app(MediaResolver::class)->pathForConversion($media, $conversion),
            'epoch' => is_array($epochs) ? (int) ($epochs[$conversion] ?? 0) : 0,
        ];
    }

    /** @param array<string, array<string, mixed>> $candidates */
    protected function publishVersionedConversions(Media $media, array $candidates): Media
    {
        return DB::connection($media->getConnectionName())->transaction(function () use ($media, $candidates): Media {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw new RuntimeException("Media [{$media->getKey()}] no longer exists.");
            }

            if (
                $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)
            ) {
                throw new RuntimeException("Media [{$media->getKey()}] has a conflicting conversion lifecycle operation.");
            }

            $resolver = app(MediaResolver::class);
            $epochs = $fresh->getCustomProperty(Media::PROPERTY_CONVERSION_GENERATION_EPOCHS, []);
            $epochs = is_array($epochs) ? $epochs : [];
            $pruning = $fresh->getCustomProperty(Media::PROPERTY_CONVERSION_PRUNING, []);
            $pruning = is_array($pruning) ? $pruning : [];
            $clearing = $fresh->getCustomProperty(Media::PROPERTY_CONVERSION_CLEARING, []);
            $clearing = is_array($clearing) ? $clearing : [];

            foreach ($candidates as $conversion => $candidate) {
                $snapshot = $candidate['_snapshot'];

                if (
                    isset($clearing[$conversion])
                    || $fresh->file_name !== $snapshot['file_name']
                    || $fresh->disk !== $snapshot['disk']
                    || $fresh->getConversionWriteDisk($conversion) !== $snapshot['write_disk']
                    || ConversionPath::directory($resolver->pathForConversion($fresh, $conversion)) !== $snapshot['base_directory']
                    || (int) ($epochs[$conversion] ?? 0) !== $snapshot['epoch']
                ) {
                    throw new RuntimeException("Media [{$media->getKey()}] changed while conversion [$conversion] was being generated.");
                }

                if (isset($pruning[$conversion][$candidate['generation']])) {
                    throw new RuntimeException("Conversion generation [{$candidate['generation']}] is being pruned.");
                }

                if (! Storage::disk($snapshot['write_disk'])->exists($candidate['path'])) {
                    throw new RuntimeException("Conversion [{$candidate['path']}] disappeared before publication.");
                }
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $files = $properties[Media::PROPERTY_CONVERSION_FILES] ?? [];
            $files = is_array($files) ? $files : [];
            $knownDisks = $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS] ?? [];
            $knownDisks = is_array($knownDisks) ? $knownDisks : [];

            foreach ($candidates as $conversion => $candidate) {
                $files[$conversion] = [
                    'generation' => $candidate['generation'],
                    'disk' => $candidate['disk'],
                    'path' => $candidate['path'],
                    'format' => $candidate['format'],
                    'file_name' => $candidate['file_name'],
                    'mime_type' => $candidate['mime_type'],
                    'size' => $candidate['size'],
                ];
                $knownDisks[] = $candidate['disk'];
            }

            $properties[Media::PROPERTY_CONVERSION_FILES] = $files;
            $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS] = array_values(array_unique(array_filter(
                $knownDisks,
                fn ($disk) => is_string($disk) && $disk !== '',
            )));
            $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
                $fresh,
                $files,
                $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS],
            );
            $fresh->custom_properties = $properties;
            $fresh->save();

            return $fresh;
        });
    }

    /** @param array<string, array{file_name: string, disk: string, write_disk: string, base_directory: string, epoch: int, path: string, wrote: bool, existed_before: bool}> $transitions */
    protected function publishLegacyTransitions(Media $media, array $transitions): Media
    {
        return DB::connection($media->getConnectionName())->transaction(function () use ($media, $transitions): Media {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw new RuntimeException("Media [{$media->getKey()}] no longer exists.");
            }

            $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
            $files = $properties[Media::PROPERTY_CONVERSION_FILES] ?? [];
            $files = is_array($files) ? $files : [];
            $resolver = app(MediaResolver::class);
            $epochs = $properties[Media::PROPERTY_CONVERSION_GENERATION_EPOCHS] ?? [];
            $epochs = is_array($epochs) ? $epochs : [];
            $clearing = $properties[Media::PROPERTY_CONVERSION_CLEARING] ?? [];
            $clearing = is_array($clearing) ? $clearing : [];
            $changed = false;

            foreach ($transitions as $conversion => $snapshot) {
                if (
                    isset($clearing[$conversion])
                    || $fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)
                    || $fresh->file_name !== $snapshot['file_name']
                    || $fresh->disk !== $snapshot['disk']
                    || $fresh->getConversionWriteDisk($conversion) !== $snapshot['write_disk']
                    || $resolver->pathForConversion($fresh, $conversion) !== $snapshot['base_directory']
                    || (int) ($epochs[$conversion] ?? 0) !== $snapshot['epoch']
                ) {
                    throw new RuntimeException("Media [{$media->getKey()}] changed while conversion [$conversion] was being generated.");
                }

                $path = $snapshot['path'];

                if (! Storage::disk($snapshot['write_disk'])->exists($path)) {
                    throw new RuntimeException("Legacy conversion [$path] disappeared before publication.");
                }

                if ($snapshot['wrote'] && $fresh->getConversionFile($conversion) !== null) {
                    unset($files[$conversion]);
                    $changed = true;
                }
            }

            if (! $changed) {
                return $fresh;
            }

            if ($files === []) {
                unset($properties[Media::PROPERTY_CONVERSION_FILES]);
            } else {
                $properties[Media::PROPERTY_CONVERSION_FILES] = $files;
            }

            $knownDisks = $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS] ?? [];
            $knownDisks = is_array($knownDisks) ? $knownDisks : [];
            $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
                $fresh,
                $files,
                $knownDisks,
            );

            $fresh->custom_properties = $properties;
            $fresh->save();

            return $fresh;
        });
    }

    protected function cleanupUnpublishedGeneration(
        Media $media,
        string $conversion,
        string $generation,
        string $directory,
        string $disk,
    ): void {
        try {
            /** @var Media|null $fresh */
            $fresh = $media->newQuery()->useWritePdo()->whereKey($media->getKey())->first();

            $active = $fresh?->getConversionFile($conversion);

            if ($active !== null && $active['generation'] === $generation) {
                return;
            }
        } catch (Throwable $e) {
            Log::warning('MediaMan: Could not verify failed conversion generation state; retaining files', [
                'mediaId' => $media->getKey(),
                'conversion' => $conversion,
                'generation' => $generation,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        try {
            if (! Storage::disk($disk)->deleteDirectory($directory)) {
                Log::warning('MediaMan: Failed to clean unpublished conversion generation', [
                    'mediaId' => $media->getKey(),
                    'conversion' => $conversion,
                    'generation' => $generation,
                    'disk' => $disk,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('MediaMan: Failed to clean unpublished conversion generation', [
                'mediaId' => $media->getKey(),
                'conversion' => $conversion,
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
                Log::warning('MediaMan: Failed to remove conversion generation marker', ['path' => $path]);
            }
        } catch (Throwable $e) {
            Log::warning('MediaMan: Failed to remove conversion generation marker', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
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
            throw new RuntimeException(
                "Cannot resolve a file extension for MIME type [$mimeType] — refusing to write the conversion."
            );
        }

        return $extension;
    }
}
