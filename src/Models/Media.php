<?php

namespace Emaia\MediaMan\Models;

use DateTimeInterface;
use Emaia\MediaMan\Casts\Json;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Conversions\ConversionClearer;
use Emaia\MediaMan\Conversions\ConversionGeneration;
use Emaia\MediaMan\Conversions\ConversionManifest;
use Emaia\MediaMan\Conversions\ConversionPath;
use Emaia\MediaMan\Database\Factories\MediaFactory;
use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\Exceptions\InvalidCopyTarget;
use Emaia\MediaMan\Exceptions\TemporaryUrlNotSupported;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\ResponsiveImages\ResponsiveGeneration;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\Traits\ResolvesModels;
use Emaia\MediaMan\Traits\ResponsiveImages;
use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Events\NullDispatcher;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * @property int $id
 * @property string $name
 * @property string $file_name
 * @property string $mime_type
 * @property string $disk
 * @property string $type
 * @property float|int $size
 * @property array|null $custom_properties
 */
class Media extends Model implements Attachable
{
    use HasFactory, ResolvesModels, ResponsiveImages;

    const string DEFAULT_CHANNEL = 'default';

    const string CONVERSIONS_DIR = 'conversions';

    const string RESPONSIVE_DIR = 'responsive';

    const string PROPERTY_RESPONSIVE_IMAGES = 'responsive_images';

    const string PROPERTY_RESPONSIVE_GENERATION = 'responsive_generation';

    const string PROPERTY_RESPONSIVE_GENERATION_DISK = 'responsive_generation_disk';

    const string PROPERTY_RESPONSIVE_GENERATION_DISKS = 'responsive_generation_disks';

    const string PROPERTY_RESPONSIVE_GENERATION_EPOCH = 'responsive_generation_epoch';

    const string PROPERTY_RESPONSIVE_CLEARING = 'responsive_clearing';

    const string PROPERTY_RESPONSIVE_PRUNING = 'responsive_pruning';

    const string PROPERTY_RESPONSIVE_ROTATING = 'responsive_rotating';

    const string PROPERTY_CONVERSION_FILES = 'conversion_files';

    const string PROPERTY_CONVERSION_GENERATION_DISKS = 'conversion_generation_disks';

    const string PROPERTY_CONVERSION_GENERATION_EPOCHS = 'conversion_generation_epochs';

    const string PROPERTY_CONVERSION_MANIFEST_SIGNATURE = 'conversion_manifest_signature';

    const string PROPERTY_CONVERSION_CLEARING = 'conversion_clearing';

    const string PROPERTY_CONVERSION_PRUNING = 'conversion_pruning';

    const string PROPERTY_IMAGE_META = 'image_meta';

    protected $fillable = [
        'name', 'file_name', 'mime_type', 'size', 'disk', 'custom_properties',
    ];

    protected $casts = [
        'custom_properties' => Json::class,
    ];

    protected $appends = ['friendly_size', 'media_uri', 'media_url', 'type', 'extension'];

    protected array $conversionFormatCache = [];

    protected ?bool $conversionManifestValidityCache = null;

    /** @var string[] */
    protected array $deletionVariantDisks = [];

    /** @var array<int, array{disk: string, path: string}> */
    protected array $deletionResponsivePaths = [];

    /** @var array<int, array{disk: string, path: string}> */
    protected array $deletionConversionPaths = [];

    protected ?string $deletionPrimaryDisk = null;

    protected ?string $deletionDirectory = null;

    protected ?string $deletionFilePath = null;

    public static function booted(): void
    {
        static::updating(function ($media) {
            if ($media->isDirty('disk')) {
                self::ensureDiskUsability($media->disk);
            }
        });

        static::updated(function ($media) {
            $originalDisk = $media->getOriginal('disk');
            $newDisk = $media->disk;

            $originalFileName = $media->getOriginal('file_name');
            $newFileName = $media->file_name;

            $path = $media->getDirectory();

            if ($media->isDirty('disk')) {
                $filePathOnOriginalDisk = $path.'/'.$originalFileName;
                $fileContent = Storage::disk($originalDisk)->get($filePathOnOriginalDisk);

                Storage::disk($newDisk)->put($filePathOnOriginalDisk, $fileContent);
                Storage::disk($originalDisk)->delete($filePathOnOriginalDisk);
            }

            if ($media->isDirty('file_name')) {
                Storage::disk($newDisk)->move($path.'/'.$originalFileName, $path.'/'.$newFileName);
            }
        });
    }

    /** Delete the model while serializing hard-delete against responsive lifecycle operations. */
    public function delete(): ?bool
    {
        $hardDelete = ! method_exists($this, 'isForceDeleting') || $this->isForceDeleting();

        if (! $hardDelete) {
            return parent::delete();
        }

        // Intentionally mirrors Eloquent's hard-delete pipeline so only the fresh row lock and SQL delete run in-transaction. Review this sequence on each supported Laravel major.
        $this->mergeAttributesFromCachedCasts();

        if (! $this->exists) {
            return null;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->touchOwners();
        $dispatchMediaDeleted = ! static::getEventDispatcher() instanceof NullDispatcher;

        DB::connection($this->getConnectionName())->transaction(function (): void {
            /** @var Media|null $fresh */
            $fresh = $this->newQueryWithoutScopes()
                ->useWritePdo()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                $this->exists = false;

                return;
            }

            if ($fresh->hasCustomProperty(self::PROPERTY_RESPONSIVE_ROTATING)) {
                throw new RuntimeException("Media [{$this->getKey()}] is rotating paths.");
            }

            self::captureDeletionState($this, $fresh);
            $this->performDeleteOnModel();
        });

        DB::connection($this->getConnectionName())->afterCommit(
            fn () => self::deleteCapturedFiles($this, $dispatchMediaDeleted),
        );
        $this->fireModelEvent('deleted', false);

        return true;
    }

    private static function captureDeletionState(Media $media, Media $state): void
    {
        $media->deletionPrimaryDisk = $state->disk;
        $media->deletionDirectory = $state->getDirectory();
        $media->deletionFilePath = $state->getPath();
        $media->deletionVariantDisks = array_values(array_unique(array_merge(
            $state->getConversionDisks(),
            [$state->responsiveDisk(), $state->activeResponsiveDisk()],
            $state->responsiveGenerationDisks(),
        )));
        $clearState = $state->getCustomProperty(self::PROPERTY_RESPONSIVE_CLEARING);

        if (is_array($clearState) && ResponsiveImageGenerator::isValidClearState($state, $clearState)) {
            foreach ($clearState['disks'] as $disk) {
                if (is_string($disk) && $disk !== '' && is_string($clearState['base_path'])) {
                    $media->deletionResponsivePaths[] = [
                        'disk' => $disk,
                        'path' => $clearState['base_path'],
                    ];
                }
            }
        }

        $conversionClearing = $state->getCustomProperty(self::PROPERTY_CONVERSION_CLEARING, []);

        if (! is_array($conversionClearing)) {
            return;
        }

        foreach ($conversionClearing as $conversion => $conversionState) {
            if (
                ! is_string($conversion)
                || ! is_array($conversionState)
                || ! ConversionClearer::isValidClearState($state, $conversion, $conversionState)
            ) {
                continue;
            }

            foreach ($conversionState['disks'] as $disk) {
                $media->deletionVariantDisks[] = $disk;
                $media->deletionConversionPaths[] = [
                    'disk' => $disk,
                    'path' => $conversionState['base_path'],
                ];
            }
        }

        $media->deletionVariantDisks = array_values(array_unique($media->deletionVariantDisks));
    }

    private static function deleteCapturedFiles(Media $media, bool $dispatchEvent): void
    {
        $primaryDisk = $media->deletionPrimaryDisk ?? $media->disk;
        $directory = $media->deletionDirectory ?? $media->getDirectory();
        $filePath = $media->deletionFilePath ?? $media->getPath();
        $mainDeleted = Storage::disk($primaryDisk)->deleteDirectory($directory);
        ! $mainDeleted && Storage::disk($primaryDisk)->delete($filePath);

        $variantDisks = $media->deletionVariantDisks !== []
            ? $media->deletionVariantDisks
            : array_unique(array_merge(
                $media->getConversionDisks(),
                [$media->responsiveDisk(), $media->activeResponsiveDisk()],
                $media->responsiveGenerationDisks(),
            ));

        foreach ($variantDisks as $variantDisk) {
            if ($variantDisk !== $primaryDisk) {
                Storage::disk($variantDisk)->deleteDirectory($directory);
            }
        }

        foreach ($media->deletionResponsivePaths as $responsivePath) {
            Storage::disk($responsivePath['disk'])->deleteDirectory($responsivePath['path']);
        }

        foreach ($media->deletionConversionPaths as $conversionPath) {
            Storage::disk($conversionPath['disk'])->deleteDirectory($conversionPath['path']);
        }

        if ($dispatchEvent) {
            event(new MediaDeleted($media));
        }
    }

    /** Obfuscated directory the media's files live in (per the configured resolver). */
    public function getDirectory(): string
    {
        return app(MediaResolver::class)->directory($this);
    }

    /** Full storage path including the conversion's resolved extension. */
    public function getPath(string $conversion = ''): string
    {
        return $this->getPathWithCorrectExtension($conversion);
    }

    protected function getPathWithCorrectExtension(string $conversion = ''): string
    {
        if ($conversion) {
            $active = $this->getConversionFile($conversion);

            if ($active !== null) {
                return $active['path'];
            }

            $directory = app(MediaResolver::class)->pathForConversion($this, $conversion);
            $originalName = $this->file_name ?? '';
            $extension = $this->detectConversionFormat($conversion)
                ?: pathinfo($originalName, PATHINFO_EXTENSION);
            $fileName = app(MediaResolver::class)->conversionFileName(
                $originalName,
                $conversion,
                $extension
            );
        } else {
            $directory = $this->getDirectory();
            $fileName = $this->file_name;
        }

        return $directory.'/'.$fileName;
    }

    protected function detectConversionFormat(string $conversion): ?string
    {
        $active = $this->getConversionFile($conversion);

        if ($active !== null) {
            return $active['format'];
        }

        if (isset($this->conversionFormatCache[$conversion])) {
            return $this->conversionFormatCache[$conversion];
        }

        try {
            $conversionRegistry = app(ConversionRegistry::class);

            if (! $conversionRegistry->exists($conversion)) {
                return null;
            }

            if (! $this->isOfType(MediaType::IMAGE)) {
                return null;
            }

            // Four-stage fallback: registry → conversion name → existing file → source MIME.
            $detectedFormat = $conversionRegistry->getFormat($conversion);

            if ($detectedFormat) {
                $this->conversionFormatCache[$conversion] = $detectedFormat;

                return $detectedFormat;
            }

            $formatFromName = $this->detectFormatFromConversionName($conversion);

            if ($formatFromName) {
                $this->conversionFormatCache[$conversion] = $formatFromName;

                return $formatFromName;
            }

            $existingFormat = $this->detectFormatFromExistingFile($conversion);

            if ($existingFormat) {
                $this->conversionFormatCache[$conversion] = $existingFormat;

                return $existingFormat;
            }

            // ImageManipulator preserves the source MIME when a conversion
            // returns an unencoded Image. Use the same canonical extension so
            // URLs generated before the queued conversion exists match the
            // file that will eventually be written (e.g. JFIF → JPEG → .jpg).
            $sourceFormat = MediaFormat::extensionFromMimeType($this->mime_type);

            if ($sourceFormat) {
                $this->conversionFormatCache[$conversion] = $sourceFormat;

                return $sourceFormat;
            }

        } catch (Exception $e) {
            Log::warning('MediaMan: Failed to detect conversion format', [
                'media_id' => $this->id,
                'conversion' => $conversion,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->conversionFormatCache[$conversion] = null;

        return null;
    }

    public function isOfType(string|MediaType $type): bool
    {
        $typeValue = $type instanceof MediaType ? $type->value : $type;

        return $this->type === $typeValue;
    }

    /**
     * True when the media is a raster image the image pipeline can process.
     * Matches `MediaFormat::rasterMimeTypes()` so any `image/*` MIME outside
     * the detectable-formats list (notably SVG) is rejected — preventing
     * conversion/responsive jobs from queueing guaranteed-to-fail work.
     */
    public function isRasterImage(): bool
    {
        return $this->isOfType(MediaType::IMAGE)
            && in_array($this->mime_type, MediaFormat::rasterMimeTypes(), true);
    }

    /** Restrict a query to media the image pipeline can decode (see {@see isRasterImage()}). */
    public function scopeRaster(Builder $query): Builder
    {
        return $query->whereIn('mime_type', MediaFormat::rasterMimeTypes());
    }

    protected function detectFormatFromConversionName(string $conversion): ?string
    {
        $conversion = strtolower($conversion);

        $patterns = [
            '/webp/' => MediaFormat::WEBP->value,
            '/avif/' => MediaFormat::AVIF->value,
            '/png/' => MediaFormat::PNG->value,
            '/jpg|jpeg/' => MediaFormat::JPG->value,
            '/gif/' => MediaFormat::GIF->value,
            '/bmp/' => MediaFormat::BMP->value,
            '/tiff|tif/' => MediaFormat::TIFF->value,
            '/heic/' => MediaFormat::HEIC->value,
            '/heif/' => MediaFormat::HEIF->value,
        ];

        foreach ($patterns as $pattern => $format) {
            if (preg_match($pattern, $conversion)) {
                return $format;
            }
        }

        return null;
    }

    /** Probe disk for a matching extension when registry/name detection both miss. */
    protected function detectFormatFromExistingFile(string $conversion): ?string
    {
        $formats = array_map(fn (MediaFormat $f) => $f->value, MediaFormat::detectableFormats());
        $baseDirectory = app(MediaResolver::class)->pathForConversion($this, $conversion);
        $baseFileName = pathinfo($this->file_name, PATHINFO_FILENAME);
        $fs = $this->conversionFilesystem($conversion);

        foreach ($formats as $format) {
            $testPath = $baseDirectory.'/'.$baseFileName.'.'.$format;
            if ($fs->exists($testPath)) {
                return $format;
            }
        }

        return null;
    }

    public function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /** Resolve the active conversion disk, preferring persisted generation metadata. */
    public function getConversionDisk(string $conversion): string
    {
        return $this->getConversionFile($conversion)['disk'] ?? $this->getConversionWriteDisk($conversion);
    }

    /** Resolve where a newly generated conversion should be written. */
    public function getConversionWriteDisk(string $conversion): string
    {
        $disk = app(ConversionRegistry::class)->getDisk($conversion)
            ?? config('mediaman.conversions.disk');

        return $disk ?? $this->disk ?? config('mediaman.disk') ?? config('filesystems.default');
    }

    public function conversionFilesystem(string $conversion): Filesystem
    {
        return Storage::disk($this->getConversionDisk($conversion));
    }

    public function conversionWriteFilesystem(string $conversion): Filesystem
    {
        return Storage::disk($this->getConversionWriteDisk($conversion));
    }

    /**
     * All unique disks used by conversions registered for this media. Each
     * conversion is resolved through `getConversionDisk()` so the config
     * default and the media's own disk are reflected when no explicit disk
     * was registered.
     *
     * @return string[]
     */
    public function getConversionDisks(): array
    {
        $registry = app(ConversionRegistry::class);
        $disks = [];

        foreach (array_keys($registry->all()) as $conversion) {
            $disks[$this->getConversionWriteDisk($conversion)] = true;
        }

        foreach ($this->conversionFiles() as $file) {
            $disks[$file['disk']] = true;
        }

        $knownDisks = $this->conversionManifestIsValid()
            ? $this->getCustomProperty(self::PROPERTY_CONVERSION_GENERATION_DISKS, [])
            : [];

        if (is_array($knownDisks)) {
            foreach ($knownDisks as $disk) {
                if (is_string($disk) && $disk !== '') {
                    $disks[$disk] = true;
                }
            }
        }

        return array_keys($disks);
    }

    /** Falls back from `mediaman.responsive_images.disk` to the media's own disk. */
    public function responsiveDisk(): string
    {
        return config('mediaman.responsive_images.disk')
            ?? $this->disk
            ?? config('mediaman.disk')
            ?? config('filesystems.default');
    }

    public function responsiveFilesystem(): Filesystem
    {
        return Storage::disk($this->responsiveDisk());
    }

    /** Resolve the disk of the published responsive generation, with a legacy fallback. */
    public function activeResponsiveDisk(): string
    {
        $disk = $this->getCustomProperty(self::PROPERTY_RESPONSIVE_GENERATION_DISK);

        return is_string($disk) && $disk !== '' ? $disk : $this->responsiveDisk();
    }

    /**
     * Return every disk that may contain a retained responsive generation.
     *
     * @return string[]
     */
    public function responsiveGenerationDisks(): array
    {
        $disks = $this->getCustomProperty(self::PROPERTY_RESPONSIVE_GENERATION_DISKS, []);

        return is_array($disks)
            ? array_values(array_filter($disks, fn ($disk) => is_string($disk) && $disk !== ''))
            : [];
    }

    public function replaceFileExtension(string $fileName, string $newExtension): string
    {
        $pathInfo = pathinfo($fileName);

        return $pathInfo['filename'].'.'.$newExtension;
    }

    /** Probes the disk with a temp write/delete cycle when `check_disk_accessibility` is on. */
    protected static function ensureDiskUsability(string $diskName): void
    {
        $allDisks = config('filesystems.disks');

        if (! array_key_exists($diskName, $allDisks)) {
            throw new InvalidArgumentException("Disk [$diskName] is not defined in the filesystems configuration.");
        }

        // Early return if the accessibility check is disabled
        if (! config('mediaman.check_disk_accessibility', false)) {
            return;
        }

        $disk = Storage::disk($diskName);
        $tempFileName = 'temp_check_file_'.uniqid();

        try {
            $disk->put($tempFileName, 'check');
            $disk->delete($tempFileName);
        } catch (Exception $e) {
            throw new Exception("Failed to write or delete on the disk [$diskName]. Error: ".$e->getMessage(), 0, $e);
        }
    }

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }

    public function getExtensionFromMimeType(string $mimeType): ?string
    {
        return MediaFormat::extensionFromMimeType($mimeType);
    }

    public function getTable(): string
    {
        return config('mediaman.tables.media', 'mediaman_media');
    }

    public function getExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    public function getTypeAttribute(): string
    {
        return Str::before($this->mime_type, '/');
    }

    /** Format the file size as a human-readable string with binary units. */
    public function getFriendlySizeAttribute(): ?string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        if ($this->size == 0) {
            return '0 '.$units[1];
        }

        // Local copy — never mutate `$this->size` (model attribute persisted in DB
        // and serialized every time `friendly_size` is appended).
        $bytes = (float) $this->size;

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /** Absolutized resolver URL — routes through getUrl() so url.prefix and url.versioning apply. */
    public function getMediaUrlAttribute(): string
    {
        return asset($this->getUrl());
    }

    /** Alias of getUrl() preserved as an appended attribute for serialization. */
    public function getMediaUriAttribute(): string
    {
        return $this->getUrl();
    }

    /** Absolute on-disk path with the conversion's resolved extension. */
    public function getFullPath(string $conversion = ''): string
    {
        $filesystem = $conversion !== '' ? $this->conversionFilesystem($conversion) : $this->filesystem();

        return $filesystem->path(
            $this->getPathWithCorrectExtension($conversion)
        );
    }

    /** Relative path keyed by `$this->file_name` (no format-detection extension swap). */
    public function getOriginalPath(string $conversion = ''): string
    {
        if ($conversion) {
            $active = $this->getConversionFile($conversion);

            if ($active !== null) {
                return $active['path'];
            }

            $directory = app(MediaResolver::class)->pathForConversion($this, $conversion);
        } else {
            $directory = $this->getDirectory();
        }

        return $directory.'/'.$this->file_name;
    }

    /** Returns null when the conversion file isn't on disk (yet). */
    public function getConversionUrl(string $conversion): ?string
    {
        if (! $this->hasConversion($conversion)) {
            return null;
        }

        return $this->getUrl($conversion);
    }

    public function hasConversion(string $conversion): bool
    {
        $path = $this->getPathWithCorrectExtension($conversion);

        return $this->conversionFilesystem($conversion)->exists($path);
    }

    /** URL to the original (no `$conversion`) or to a specific conversion variant. */
    public function getUrl(string $conversion = ''): string
    {
        return app(MediaResolver::class)->url(
            $this,
            $conversion !== '' ? $conversion : null
        );
    }

    /** LQIP placeholder data URI captured at upload, or null when none was generated. */
    public function getPlaceholder(): ?string
    {
        $value = $this->getCustomProperty('placeholder');

        return is_string($value) ? $value : null;
    }

    /**
     * Hex CSS color (`#rrggbb`) of the source image average — useful as a
     * `background-color` skeleton for email/SSR where SVG LQIP isn't viable.
     * Null on non-image media or pre-v2.13 records.
     */
    public function getPlaceholderColor(): ?string
    {
        $meta = $this->getCustomProperty(self::PROPERTY_IMAGE_META);

        if (! is_array($meta) || ! isset($meta['dominant_color'])) {
            return null;
        }

        return (string) $meta['dominant_color'];
    }

    /**
     * Single-URL helper for srcset-incompatible contexts (email, OG tags,
     * `background-image`): conversion URL → LQIP placeholder → original URL.
     * For real `<img>`/`<picture>`, prefer `getPictureHtml()` / `getSimpleImgHtml()`.
     */
    public function getUrlOrPlaceholder(string $conversion = ''): string
    {
        if ($conversion !== '' && ! $this->hasConversion($conversion)) {
            $placeholder = $this->getPlaceholder();

            if ($placeholder !== null) {
                return $placeholder;
            }
        }

        return $this->getUrl($conversion);
    }

    /** Conversion URL when the variant exists on disk, original URL otherwise. */
    public function getUrlWithFallback(string $conversion = ''): string
    {
        if (empty($conversion)) {
            return $this->getUrl();
        }

        if ($this->hasConversion($conversion)) {
            return $this->getUrl($conversion);
        }

        return $this->getUrl();
    }

    public function clearConversionFormatCache(): void
    {
        $this->conversionFormatCache = [];
        $this->conversionManifestValidityCache = null;
    }

    /** Return the validated active metadata for a conversion, or null for legacy/unsafe data. */
    public function getConversionFile(string $conversion): ?array
    {
        try {
            ConversionPath::name($conversion);
        } catch (InvalidArgumentException) {
            return null;
        }

        $files = $this->getCustomProperty(self::PROPERTY_CONVERSION_FILES, []);
        $entry = is_array($files) ? ($files[$conversion] ?? null) : null;

        if (! is_array($entry) || ! $this->conversionManifestIsValid()) {
            return null;
        }

        return $this->validateConversionFile($conversion, $entry);
    }

    private function validateConversionFile(string $conversion, array $entry): ?array
    {

        foreach (['generation', 'disk', 'path', 'format', 'file_name', 'mime_type'] as $key) {
            if (! isset($entry[$key]) || ! is_string($entry[$key]) || $entry[$key] === '') {
                return null;
            }
        }

        if (! isset($entry['size']) || ! is_int($entry['size']) || $entry['size'] <= 0) {
            return null;
        }

        if (! ConversionGeneration::isManaged($entry['generation'])) {
            return null;
        }

        $format = MediaFormat::tryFromValue($entry['format']);

        if ($format === null || $format->mimeType() !== $entry['mime_type']) {
            return null;
        }

        try {
            Storage::disk($entry['disk']);
        } catch (Throwable) {
            return null;
        }

        try {
            $fileName = ConversionPath::fileName($entry['file_name']);
            $suffix = '/'.$entry['generation'].'/'.$fileName;

            if (! str_ends_with($entry['path'], $suffix)) {
                return null;
            }

            ConversionPath::directory(substr($entry['path'], 0, -strlen($suffix)));
        } catch (InvalidArgumentException) {
            return null;
        }

        return $entry;
    }

    /** Return every valid active conversion manifest entry keyed by conversion name. */
    public function conversionFiles(): array
    {
        $files = $this->getCustomProperty(self::PROPERTY_CONVERSION_FILES, []);

        if (! is_array($files) || ! $this->conversionManifestIsValid()) {
            return [];
        }

        $valid = [];

        foreach (array_keys($files) as $conversion) {
            if (! is_string($conversion)) {
                continue;
            }

            $entry = is_array($files[$conversion] ?? null)
                ? $this->validateConversionFile($conversion, $files[$conversion])
                : null;

            if ($entry !== null) {
                $valid[$conversion] = $entry;
            }
        }

        return $valid;
    }

    /** Verify that package-owned conversion paths and disk history were published together. */
    public function conversionManifestIsValid(): bool
    {
        if ($this->conversionManifestValidityCache !== null) {
            return $this->conversionManifestValidityCache;
        }

        $files = $this->getCustomProperty(self::PROPERTY_CONVERSION_FILES, []);
        $disks = $this->getCustomProperty(self::PROPERTY_CONVERSION_GENERATION_DISKS, []);

        if (! is_array($files) || ! is_array($disks)) {
            return $this->conversionManifestValidityCache = false;
        }

        $valid = ConversionManifest::isValid(
            $this,
            $files,
            $disks,
            $this->getCustomProperty(self::PROPERTY_CONVERSION_MANIFEST_SIGNATURE),
        );

        if (! $valid && $files !== []) {
            Log::warning('MediaMan: Conversion manifest signature is invalid', [
                'mediaId' => $this->getKey(),
            ]);
        }

        return $this->conversionManifestValidityCache = $valid;
    }

    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->conversionManifestValidityCache = null;

        return parent::setRawAttributes($attributes, $sync);
    }

    public function setAttribute($key, $value)
    {
        if ($key === 'custom_properties') {
            $this->conversionManifestValidityCache = null;
        }

        return parent::setAttribute($key, $value);
    }

    /** Replace the media's collection associations (set `$detaching=false` to add only). */
    public function syncCollections(Collection|BaseCollection|array|int|string|bool|Model|null $collections, $detaching = true): array
    {
        if ($this->shouldDetachAll($collections)) {
            return $this->collections()->sync([]);
        }

        $fetch = $this->fetchCollections($collections);

        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            $ids = $fetch->modelKeys();

            return $this->collections()->sync($ids, $detaching);
        }

        return $this->collections()->sync($fetch->getKey(), $detaching);

    }

    /** Empty / null / false / `[]` are all signals to detach everything. */
    private function shouldDetachAll(mixed $collections): bool
    {
        if (is_bool($collections) || empty($collections)) {
            return true;
        }

        if (is_countable($collections) && count($collections) === 0) {
            return true;
        }

        return false;
    }

    /**
     * A media belongs-to-many collection
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->collectionModel(),
            config('mediaman.tables.collection_media'),
            'media_id',
            'collection_id');
    }

    /** Coerce any of the accepted shapes (id, name, array, instance, collection) into models. */
    private function fetchCollections(mixed $collections): Collection|BaseCollection|Model|null
    {
        $model = $this->collectionModel();

        // Eloquent collection already carries hydrated models — no refetch.
        if ($collections instanceof Collection) {
            return $collections;
        }

        if ($collections instanceof BaseCollection) {
            $ids = $collections->map(
                fn ($item) => is_object($item) && method_exists($item, 'getKey')
                    ? $item->getKey()
                    : $item
            )->all();

            return $model::find($ids);
        }

        if (is_object($collections) && method_exists($collections, 'getKey')) {
            return $model::find($collections->getKey());
        }

        if (is_numeric($collections)) {
            return $model::find($collections);
        }

        if (is_string($collections)) {
            return $model::findByName($collections);
        }

        // Array branch dispatches by the first element's type — assumes a
        // homogeneous list (all ints OR all strings, not mixed).
        if (is_array($collections) && isset($collections[0])) {
            if (is_numeric($collections[0])) {
                return $model::find($collections);
            }

            if (is_string($collections[0])) {
                return $model::findByName($collections);
            }
        }

        return null;
    }

    /** Find one (string `$names`) or many (array) records by the `name` column. */
    public static function findByName(string|array $names, array $columns = ['*']): Collection|static|null
    {
        $query = static::query()->select($columns);

        if (is_array($names)) {
            return $query->whereIn('name', $names)->get();
        }

        return $query->where('name', $names)->first();
    }

    /** Returns the count of newly attached collections, or null when nothing changed. */
    public function attachCollections(Collection|BaseCollection|array|int|string|Model $collections): ?int
    {
        $fetch = $this->fetchCollections($collections);

        if ($fetch instanceof Collection) {
            $ids = $fetch->modelKeys();
            $res = $this->collections()->sync($ids, false);
            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        }

        if (is_object($fetch) && method_exists($fetch, 'getKey')) {
            $res = $this->collections()->sync($fetch->getKey(), false);
            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        }

        return null;
    }

    /** Returns the count of detached collections, or null when nothing changed. */
    public function detachCollections(Collection|BaseCollection|int|bool|array|string|Model|null $collections): ?int
    {
        if ($this->shouldDetachAll($collections)) {
            return $this->collections()->detach();
        }

        $fetch = $this->fetchCollections($collections);

        if ($fetch instanceof Collection) {
            $ids = $fetch->modelKeys();

            return $this->collections()->detach($ids);
        }

        if (is_object($fetch) && method_exists($fetch, 'getKey')) {
            return $this->collections()->detach($fetch->getKey());
        }

        return null;
    }

    public function hasCustomProperty(string $propertyName): bool
    {
        return is_array($this->custom_properties)
            && Arr::has($this->custom_properties, $propertyName);
    }

    public function getCustomProperty(string $propertyName, mixed $default = null): mixed
    {
        return Arr::get(
            is_array($this->custom_properties) ? $this->custom_properties : [],
            $propertyName,
            $default,
        );
    }

    public function setCustomProperty(string $name, mixed $value): self
    {
        $customProperties = is_array($this->custom_properties) ? $this->custom_properties : [];

        Arr::set($customProperties, $name, $value);

        $this->custom_properties = $customProperties;
        $this->conversionManifestValidityCache = null;

        return $this;
    }

    /**
     * Stream the file as a downloadable HTTP response.
     */
    public function toResponse(?string $conversion = null): StreamedResponse
    {
        $path = $this->getPath($conversion ?? '');
        $fs = $conversion !== null && $conversion !== ''
            ? $this->conversionFilesystem($conversion)
            : $this->filesystem();

        $fileName = $conversion !== null && $conversion !== ''
            ? ($this->getConversionFile($conversion)['file_name'] ?? $this->file_name)
            : $this->file_name;

        return $fs->download($path, $fileName);
    }

    /**
     * Stream the file as an inline HTTP response (browsers display in-tab).
     */
    public function toInlineResponse(?string $conversion = null): StreamedResponse
    {
        $path = $this->getPath($conversion ?? '');
        $fs = $conversion !== null && $conversion !== ''
            ? $this->conversionFilesystem($conversion)
            : $this->filesystem();

        $fileName = $conversion !== null && $conversion !== ''
            ? ($this->getConversionFile($conversion)['file_name'] ?? $this->file_name)
            : $this->file_name;

        return $fs->response($path, $fileName);
    }

    /**
     * Open a read stream to the underlying file. Caller is responsible for closing it.
     *
     * @return resource
     */
    public function getStream(?string $conversion = null)
    {
        $path = $this->getPath($conversion ?? '');
        $fs = $conversion !== null && $conversion !== ''
            ? $this->conversionFilesystem($conversion)
            : $this->filesystem();

        return $fs->readStream($path);
    }

    /**
     * Generate a temporary signed URL for cloud disks that support it.
     *
     * @throws TemporaryUrlNotSupported when the disk has no temporary URL support
     */
    public function getTemporaryUrl(?DateTimeInterface $expiration = null, ?string $conversion = null): string
    {
        $filesystem = $conversion !== null && $conversion !== ''
            ? $this->conversionFilesystem($conversion)
            : $this->filesystem();

        if (! $filesystem->providesTemporaryUrls()) {
            throw TemporaryUrlNotSupported::forDisk(
                $conversion !== null && $conversion !== ''
                    ? $this->getConversionDisk($conversion)
                    : $this->disk
            );
        }

        $expiration ??= now()->addMinutes(
            (int) config('mediaman.temporary_url.default_lifetime_minutes', 5)
        );

        return app(MediaResolver::class)->temporaryUrl(
            $this,
            $expiration,
            $conversion !== '' ? $conversion : null
        );
    }

    /** Build a Mail Attachment for the original or a specific conversion variant. */
    public function mailAttachment(?string $conversion = null): Attachment
    {
        $disk = $conversion !== null && $conversion !== ''
            ? $this->getConversionDisk($conversion)
            : $this->disk;

        $active = $conversion !== null && $conversion !== ''
            ? $this->getConversionFile($conversion)
            : null;

        return Attachment::fromStorageDisk($disk, $this->getPath($conversion ?? ''))
            ->as($active['file_name'] ?? $this->file_name)
            ->withMime($active['mime_type'] ?? $this->mime_type);
    }

    /** Laravel's Attachable contract — invoked by `$mailable->attach($media)`. */
    public function toMailAttachment(): Attachment
    {
        return $this->mailAttachment();
    }

    public function forgetCustomProperty(string $name): self
    {
        $customProperties = is_array($this->custom_properties) ? $this->custom_properties : [];

        Arr::forget($customProperties, $name);

        $this->custom_properties = $customProperties;
        $this->conversionManifestValidityCache = null;

        return $this;
    }

    /**
     * Replicate row + physical files (original + conversions + responsive variants)
     * and attach to `$target`. Rolls back the new record on any file-copy failure.
     */
    public function copy(object $target, string $channel = self::DEFAULT_CHANNEL): Media
    {
        if (! method_exists($target, 'attachMedia')) {
            throw InvalidCopyTarget::missingTrait();
        }

        $generation = $this->getCustomProperty(self::PROPERTY_RESPONSIVE_GENERATION);

        if ($generation !== null && (! is_string($generation) || ! ResponsiveGeneration::isManaged($generation))) {
            throw new RuntimeException('Cannot copy malformed responsive generation metadata.');
        }

        $copy = $this->replicate(['id']);
        $copyProperties = is_array($copy->custom_properties) ? $copy->custom_properties : [];
        unset(
            $copyProperties[self::PROPERTY_CONVERSION_FILES],
            $copyProperties[self::PROPERTY_CONVERSION_GENERATION_DISKS],
            $copyProperties[self::PROPERTY_CONVERSION_GENERATION_EPOCHS],
            $copyProperties[self::PROPERTY_CONVERSION_MANIFEST_SIGNATURE],
            $copyProperties[self::PROPERTY_CONVERSION_CLEARING],
            $copyProperties[self::PROPERTY_CONVERSION_PRUNING],
            $copyProperties[self::PROPERTY_RESPONSIVE_IMAGES],
            $copyProperties[self::PROPERTY_RESPONSIVE_GENERATION],
            $copyProperties[self::PROPERTY_RESPONSIVE_GENERATION_DISK],
            $copyProperties[self::PROPERTY_RESPONSIVE_GENERATION_DISKS],
            $copyProperties[self::PROPERTY_RESPONSIVE_GENERATION_EPOCH],
            $copyProperties[self::PROPERTY_RESPONSIVE_CLEARING],
            $copyProperties[self::PROPERTY_RESPONSIVE_PRUNING],
            $copyProperties[self::PROPERTY_RESPONSIVE_ROTATING],
        );
        $copy->custom_properties = $copyProperties;
        $copy->save();

        try {
            $this->copyPrimaryFile($copy);
            $this->copyConversions($copy);
            $this->copyResponsiveVariants($copy);
            $target->attachMedia($copy, $channel);
        } catch (Throwable $e) {
            try {
                $copy->forceDelete();
            } catch (Throwable $cleanupError) {
                Log::warning('MediaMan: Failed to roll back copied media', [
                    'media_id' => $copy->getKey(),
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            throw $e;
        }

        return $copy;
    }

    /** Purely relational: attaches the existing row to `$target`, never touches the file. */
    public function attachTo(object $target, string $channel = self::DEFAULT_CHANNEL): self
    {
        if (! method_exists($target, 'attachMedia')) {
            throw InvalidCopyTarget::missingTrait();
        }

        $target->attachMedia($this, $channel);

        return $this;
    }

    protected function copyPrimaryFile(Media $target): void
    {
        if ($this->disk === $target->disk) {
            if (! $this->filesystem()->copy($this->getPath(), $target->getPath())) {
                throw new RuntimeException("Failed to copy media file [{$this->getPath()}].");
            }

            return;
        }

        $stream = $this->filesystem()->readStream($this->getPath());

        if (! is_resource($stream)) {
            throw new RuntimeException("Failed to read media file [{$this->getPath()}].");
        }

        try {
            if (! $target->filesystem()->writeStream($target->getPath(), $stream)) {
                throw new RuntimeException("Failed to write copied media file [{$target->getPath()}].");
            }
        } finally {
            fclose($stream);
        }
    }

    protected function copyConversions(Media $target): void
    {
        $registry = app(ConversionRegistry::class);
        $resolver = app(MediaResolver::class);
        $rawFiles = $this->getCustomProperty(self::PROPERTY_CONVERSION_FILES, []);
        $activeFiles = $this->conversionFiles();

        if (! is_array($rawFiles) || count($rawFiles) !== count($activeFiles)) {
            throw new RuntimeException('Cannot copy malformed conversion metadata.');
        }

        /** @var Media|null $freshSource */
        $freshSource = $this->newQuery()->useWritePdo()->whereKey($this->getKey())->first();

        if (
            $freshSource === null
            || $freshSource->hasCustomProperty(self::PROPERTY_CONVERSION_CLEARING)
            || $freshSource->hasCustomProperty(self::PROPERTY_RESPONSIVE_ROTATING)
        ) {
            throw new RuntimeException("Media [{$this->getKey()}] cannot copy conversions during a lifecycle operation.");
        }

        $rebuiltFiles = [];
        $markers = [];
        $copiedVersionedFiles = [];
        $versionedPublished = false;

        try {
            foreach ($activeFiles as $conversion => $entry) {
                $sourceFs = Storage::disk($entry['disk']);
                $targetDisk = $registry->exists($conversion)
                    ? $target->getConversionWriteDisk($conversion)
                    : $entry['disk'];
                $targetFs = Storage::disk($targetDisk);
                $targetBase = ConversionPath::directory($resolver->pathForConversion($target, $conversion));
                $targetDir = $targetBase.'/'.$entry['generation'];
                $targetFileName = ConversionPath::fileName($resolver->conversionFileName(
                    $target->file_name,
                    $conversion,
                    $entry['format'],
                ));
                $targetPath = $targetDir.'/'.$targetFileName;
                $markerName = ImageManipulator::IN_PROGRESS_MARKER.'-copy-'.strtoupper((string) Str::ulid());
                $markerBody = json_encode([
                    'operation' => 'copy',
                    'started_at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR);
                $sourceMarker = dirname($entry['path']).'/'.$markerName;
                $targetMarker = $targetDir.'/'.$markerName;

                if (! $sourceFs->put($sourceMarker, $markerBody)) {
                    throw new RuntimeException("Failed to protect conversion source [{$entry['path']}] during copy.");
                }

                $markers[] = [$sourceFs, $sourceMarker];

                if (! $targetFs->put($targetMarker, $markerBody)) {
                    throw new RuntimeException("Failed to protect conversion target [$targetPath] during copy.");
                }

                $markers[] = [$targetFs, $targetMarker];
                $this->copyConversionFile(
                    $sourceFs,
                    $entry['path'],
                    $targetFs,
                    $targetPath,
                    $entry['disk'] === $targetDisk,
                );
                $copiedVersionedFiles[] = [$targetFs, $targetPath];

                $rebuiltFiles[$conversion] = [
                    ...$entry,
                    'disk' => $targetDisk,
                    'path' => $targetPath,
                    'file_name' => $targetFileName,
                ];
            }

            foreach (array_keys($registry->all()) as $conversion) {
                if (isset($activeFiles[$conversion])) {
                    continue;
                }

                $sourceFs = Storage::disk($this->getConversionWriteDisk($conversion));
                $sourcePath = $this->getPath($conversion);

                if (! $sourceFs->exists($sourcePath)) {
                    continue;
                }

                $targetDisk = $target->getConversionWriteDisk($conversion);
                $targetFs = Storage::disk($targetDisk);
                $targetPath = $target->getPath($conversion);
                $this->copyConversionFile(
                    $sourceFs,
                    $sourcePath,
                    $targetFs,
                    $targetPath,
                    $this->getConversionWriteDisk($conversion) === $targetDisk,
                );
            }

            if ($rebuiltFiles === []) {
                $versionedPublished = true;

                return;
            }

            $published = DB::connection($target->getConnectionName())->transaction(function () use (
                $target,
                $rebuiltFiles,
            ): Media {
                /** @var Media|null $fresh */
                $fresh = $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->first();

                if ($fresh === null) {
                    throw new RuntimeException("Copied media [{$target->getKey()}] no longer exists.");
                }

                if (
                    $fresh->hasCustomProperty(self::PROPERTY_CONVERSION_CLEARING)
                    || $fresh->hasCustomProperty(self::PROPERTY_RESPONSIVE_ROTATING)
                ) {
                    throw new RuntimeException("Copied media [{$target->getKey()}] changed while conversions were copied.");
                }

                $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
                $pruning = $properties[self::PROPERTY_CONVERSION_PRUNING] ?? [];
                $pruning = is_array($pruning) ? $pruning : [];

                foreach ($rebuiltFiles as $conversion => $entry) {
                    if (isset($pruning[$conversion][$entry['generation']])) {
                        throw new RuntimeException("Copied conversion generation [{$entry['generation']}] is being pruned.");
                    }

                    if (! Storage::disk($entry['disk'])->exists($entry['path'])) {
                        throw new RuntimeException("Copied conversion [{$entry['path']}] disappeared before publication.");
                    }
                }

                $properties[self::PROPERTY_CONVERSION_FILES] = $rebuiltFiles;
                $properties[self::PROPERTY_CONVERSION_GENERATION_DISKS] = array_values(array_unique(array_column(
                    $rebuiltFiles,
                    'disk',
                )));
                $properties[self::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
                    $fresh,
                    $rebuiltFiles,
                    $properties[self::PROPERTY_CONVERSION_GENERATION_DISKS],
                );
                $fresh->custom_properties = $properties;
                $fresh->save();

                return $fresh;
            });
            $target->setRawAttributes($published->getAttributes(), true);
            $versionedPublished = true;
        } finally {
            foreach ($markers as [$filesystem, $path]) {
                try {
                    $filesystem->delete($path);
                } catch (Throwable $e) {
                    Log::warning('MediaMan: Failed to remove conversion copy marker', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $versionedPublished) {
                foreach ($copiedVersionedFiles as [$filesystem, $path]) {
                    try {
                        if (! $filesystem->delete($path)) {
                            Log::warning('MediaMan: Failed to roll back copied conversion file', ['path' => $path]);
                        }
                    } catch (Throwable $e) {
                        Log::warning('MediaMan: Failed to roll back copied conversion file', [
                            'path' => $path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    protected function copyConversionFile(
        Filesystem $sourceFilesystem,
        string $sourcePath,
        Filesystem $targetFilesystem,
        string $targetPath,
        bool $sameDisk,
    ): void {
        if ($sameDisk) {
            if (! $sourceFilesystem->copy($sourcePath, $targetPath)) {
                throw new RuntimeException("Failed to copy conversion file [$sourcePath].");
            }

            return;
        }

        $stream = $sourceFilesystem->readStream($sourcePath);

        if (! is_resource($stream)) {
            throw new RuntimeException("Failed to read conversion file [$sourcePath].");
        }

        try {
            if (! $targetFilesystem->writeStream($targetPath, $stream)) {
                throw new RuntimeException("Failed to write copied conversion file [$targetPath].");
            }
        } finally {
            fclose($stream);
        }
    }

    protected function copyResponsiveVariants(Media $target): void
    {
        $manifest = $this->getCustomProperty(self::PROPERTY_RESPONSIVE_IMAGES, []);

        if (! is_array($manifest) || $manifest === []) {
            return;
        }

        $resolver = app(MediaResolver::class);
        $sourceDir = rtrim($resolver->pathForResponsive($this), '/');
        $targetDir = rtrim($resolver->pathForResponsive($target), '/');
        $generation = $this->getCustomProperty(self::PROPERTY_RESPONSIVE_GENERATION);
        $sourceDisk = $this->activeResponsiveDisk();
        $sourceFs = Storage::disk($sourceDisk);
        $targetFs = $target->responsiveFilesystem();
        $targetDisk = $target->responsiveDisk();
        $sameDisk = $sourceDisk === $targetDisk;
        $targetEpoch = (int) $target->getCustomProperty(self::PROPERTY_RESPONSIVE_GENERATION_EPOCH, 0);
        $rebuiltManifest = [];
        $markers = [];

        /** @var Media|null $freshSource */
        $freshSource = $this->newQuery()->useWritePdo()->whereKey($this->getKey())->first();

        if (
            $freshSource === null
            || $freshSource->hasCustomProperty(self::PROPERTY_RESPONSIVE_CLEARING)
            || $freshSource->hasCustomProperty(self::PROPERTY_RESPONSIVE_ROTATING)
        ) {
            throw new RuntimeException("Media [{$this->getKey()}] cannot copy responsive files during a lifecycle operation.");
        }

        if ($generation !== null && (! is_string($generation) || ! ResponsiveGeneration::isManaged($generation))) {
            throw new RuntimeException('Cannot copy malformed responsive generation metadata.');
        }

        try {
            if (is_string($generation)) {
                $markerId = strtoupper((string) Str::ulid());
                $markerName = ResponsiveImageGenerator::IN_PROGRESS_MARKER.'-copy-'.$markerId;
                $sourceMarker = $sourceDir.'/'.$generation.'/'.$markerName;
                $targetMarker = $targetDir.'/'.$generation.'/'.$markerName;
                $markerBody = json_encode([
                    'operation' => 'copy',
                    'started_at' => now()->toIso8601String(),
                ], JSON_THROW_ON_ERROR);

                if (! $sourceFs->put($sourceMarker, $markerBody)) {
                    throw new RuntimeException("Failed to protect responsive source generation [$generation] during copy.");
                }

                $markers[] = [$sourceFs, $sourceMarker];

                if (! $targetFs->put($targetMarker, $markerBody)) {
                    throw new RuntimeException("Failed to protect responsive target generation [$generation] during copy.");
                }

                $markers[] = [$targetFs, $targetMarker];
            }

            foreach ($manifest as $item) {
                if (! is_array($item) || ! isset($item['path']) || ! is_string($item['path'])) {
                    throw new RuntimeException('Cannot copy malformed responsive image metadata.');
                }

                $sourcePath = $item['path'];
                $prefix = $sourceDir.'/';

                if (! str_starts_with($sourcePath, $prefix)) {
                    throw new RuntimeException("Responsive path [$sourcePath] is outside [$sourceDir].");
                }

                $relativePath = substr($sourcePath, strlen($prefix));

                if (is_string($generation) && ! str_starts_with($relativePath, $generation.'/')) {
                    throw new RuntimeException("Responsive path [$sourcePath] does not belong to active generation [$generation].");
                }

                $targetPath = $targetDir.'/'.$relativePath;

                if ($sameDisk) {
                    if (! $sourceFs->copy($sourcePath, $targetPath)) {
                        throw new RuntimeException("Failed to copy responsive image [$sourcePath].");
                    }
                } else {
                    $stream = $sourceFs->readStream($sourcePath);

                    if (! is_resource($stream)) {
                        throw new RuntimeException("Failed to read responsive image [$sourcePath].");
                    }

                    try {
                        if (! $targetFs->writeStream($targetPath, $stream)) {
                            throw new RuntimeException("Failed to write copied responsive image [$targetPath].");
                        }
                    } finally {
                        fclose($stream);
                    }
                }

                $item['path'] = $targetPath;
                $item['url'] = $targetFs->url($targetPath);
                $rebuiltManifest[] = $item;
            }

            $published = DB::connection($target->getConnectionName())->transaction(function () use (
                $target,
                $targetEpoch,
                $rebuiltManifest,
                $generation,
                $targetDisk,
            ): Media {
                /** @var Media|null $fresh */
                $fresh = $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->first();

                if ($fresh === null) {
                    throw new RuntimeException("Copied media [{$target->getKey()}] no longer exists.");
                }

                if (
                    $fresh->hasCustomProperty(self::PROPERTY_RESPONSIVE_CLEARING)
                    || $fresh->hasCustomProperty(self::PROPERTY_RESPONSIVE_ROTATING)
                    || (int) $fresh->getCustomProperty(self::PROPERTY_RESPONSIVE_GENERATION_EPOCH, 0) !== $targetEpoch
                ) {
                    throw new RuntimeException("Copied media [{$target->getKey()}] changed while responsive files were copied.");
                }

                $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
                $properties[self::PROPERTY_RESPONSIVE_IMAGES] = $rebuiltManifest;

                if (is_string($generation)) {
                    $pruning = $properties[self::PROPERTY_RESPONSIVE_PRUNING] ?? [];

                    if (is_array($pruning) && array_key_exists($generation, $pruning)) {
                        throw new RuntimeException("Copied responsive generation [$generation] is being pruned.");
                    }

                    $properties[self::PROPERTY_RESPONSIVE_GENERATION] = $generation;
                    $properties[self::PROPERTY_RESPONSIVE_GENERATION_DISK] = $targetDisk;
                    $properties[self::PROPERTY_RESPONSIVE_GENERATION_DISKS] = [$targetDisk];
                }

                $fresh->custom_properties = $properties;
                $fresh->save();

                return $fresh;
            });

            $target->setRawAttributes($published->getAttributes(), true);
        } finally {
            foreach ($markers as [$filesystem, $path]) {
                try {
                    $filesystem->delete($path);
                } catch (Throwable $e) {
                    Log::warning('MediaMan: Failed to remove responsive copy marker', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
