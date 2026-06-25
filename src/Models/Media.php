<?php

namespace Emaia\MediaMan\Models;

use DateTimeInterface;
use Emaia\MediaMan\Casts\Json;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Database\Factories\MediaFactory;
use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Events\MediaDeleted;
use Emaia\MediaMan\Exceptions\InvalidCopyTarget;
use Emaia\MediaMan\Exceptions\TemporaryUrlNotSupported;
use Emaia\MediaMan\Resolvers\MediaResolver;
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
use Illuminate\Mail\Attachment;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
 * @property array $custom_properties
 */
class Media extends Model implements Attachable
{
    use HasFactory, ResolvesModels, ResponsiveImages;

    const string DEFAULT_CHANNEL = 'default';

    const string CONVERSIONS_DIR = 'conversions';

    const string RESPONSIVE_DIR = 'responsive';

    const string PROPERTY_RESPONSIVE_IMAGES = 'responsive_images';

    const string PROPERTY_IMAGE_META = 'image_meta';

    protected $fillable = [
        'name', 'file_name', 'mime_type', 'size', 'disk', 'custom_properties',
    ];

    protected $casts = [
        'custom_properties' => Json::class,
    ];

    protected $appends = ['friendly_size', 'media_uri', 'media_url', 'type', 'extension'];

    protected array $conversionFormatCache = [];

    public static function booted(): void
    {
        static::deleted(static function ($media) {
            // Respect soft deletes: a soft delete fires the `deleted` event too,
            // but the files must survive so a later restore() keeps working.
            // Only a force delete (or a model without soft deletes) removes files.
            if (method_exists($media, 'isForceDeleting') && ! $media->isForceDeleting()) {
                return;
            }

            $mainDeleted = Storage::disk($media->disk)->deleteDirectory($media->getDirectory());
            ! $mainDeleted && Storage::disk($media->disk)->delete($media->getPath());

            // Deduplicate variant disks against the main disk and against each
            // other — same disk must not be wiped twice.
            $variantDisks = array_unique(array_merge(
                $media->getConversionDisks(),
                [$media->responsiveDisk()],
            ));

            foreach ($variantDisks as $variantDisk) {
                if ($variantDisk === $media->disk) {
                    continue;
                }

                Storage::disk($variantDisk)->deleteDirectory($media->getDirectory());
            }

            event(new MediaDeleted($media));
        });

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

            // Three-stage fallback: registry → conversion name → existing file.
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

        } catch (Exception $e) {
            Log::warning('MediaMan: Failed to detect conversion format', [
                'media_id' => $this->id,
                'conversion' => $conversion,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Cache the null result so the three-stage probe doesn't re-run.
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

    /**
     * Resolution chain (most specific wins): per-register `disk:` →
     * `mediaman.conversions.disk` → media's own disk → `mediaman.disk` →
     * Laravel's `filesystems.default`.
     */
    public function getConversionDisk(string $conversion): string
    {
        $disk = app(ConversionRegistry::class)->getDisk($conversion)
            ?? config('mediaman.conversions.disk');

        return $disk ?? $this->disk ?? config('mediaman.disk') ?? config('filesystems.default');
    }

    public function conversionFilesystem(string $conversion): Filesystem
    {
        return Storage::disk($this->getConversionDisk($conversion));
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
            $disks[$this->getConversionDisk($conversion)] = true;
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
        return $this->filesystem()->path(
            $this->getPathWithCorrectExtension($conversion)
        );
    }

    /** Relative path keyed by `$this->file_name` (no format-detection extension swap). */
    public function getOriginalPath(string $conversion = ''): string
    {
        if ($conversion) {
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
        return Arr::has($this->custom_properties, $propertyName);
    }

    public function getCustomProperty(string $propertyName, mixed $default = null): mixed
    {
        return Arr::get($this->custom_properties, $propertyName, $default);
    }

    public function setCustomProperty(string $name, mixed $value): self
    {
        $customProperties = $this->custom_properties;

        Arr::set($customProperties, $name, $value);

        $this->custom_properties = $customProperties;

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

        return $fs->download($path, $this->file_name);
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

        return $fs->response($path, $this->file_name);
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

        return Attachment::fromStorageDisk($disk, $this->getPath($conversion ?? ''))
            ->as($this->file_name)
            ->withMime($this->mime_type);
    }

    /** Laravel's Attachable contract — invoked by `$mailable->attach($media)`. */
    public function toMailAttachment(): Attachment
    {
        return $this->mailAttachment();
    }

    public function forgetCustomProperty(string $name): self
    {
        $customProperties = $this->custom_properties;

        Arr::forget($customProperties, $name);

        $this->custom_properties = $customProperties;

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

        $copy = $this->replicate(['id']);
        $copy->save();

        try {
            $this->copyPrimaryFile($copy);
            $this->copyConversions($copy);
            $this->copyResponsiveVariants($copy);
        } catch (Throwable $e) {
            $copy->delete();

            throw $e;
        }

        $target->attachMedia($copy, $channel);

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
            $this->filesystem()->copy($this->getPath(), $target->getPath());

            return;
        }

        $stream = $this->filesystem()->readStream($this->getPath());

        try {
            $target->filesystem()->writeStream($target->getPath(), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    protected function copyConversions(Media $target): void
    {
        $registry = app(ConversionRegistry::class);
        $resolver = app(MediaResolver::class);

        foreach (array_keys($registry->all()) as $conversion) {
            $sourceFs = $this->conversionFilesystem($conversion);
            $sourceDir = $resolver->pathForConversion($this, $conversion);

            if (! $sourceFs->exists($sourceDir)) {
                continue;
            }

            $targetFs = $target->conversionFilesystem($conversion);
            $targetDir = $resolver->pathForConversion($target, $conversion);
            $sameDisk = $this->getConversionDisk($conversion) === $target->getConversionDisk($conversion);

            foreach ($sourceFs->allFiles($sourceDir) as $file) {
                $relativePath = substr($file, strlen($sourceDir) + 1);
                $targetPath = $targetDir.'/'.$relativePath;

                if ($sameDisk) {
                    $sourceFs->copy($file, $targetPath);

                    continue;
                }

                $stream = $sourceFs->readStream($file);

                try {
                    $targetFs->writeStream($targetPath, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
        }
    }

    protected function copyResponsiveVariants(Media $target): void
    {
        $sourceFs = $this->responsiveFilesystem();
        $responsiveDir = $this->getDirectory().'/'.self::RESPONSIVE_DIR;

        if (! $sourceFs->exists($responsiveDir)) {
            return;
        }

        $targetFs = $target->responsiveFilesystem();
        $sameDisk = $this->responsiveDisk() === $target->responsiveDisk();

        foreach ($sourceFs->allFiles($responsiveDir) as $file) {
            $relativePath = substr($file, strlen($responsiveDir) + 1);
            $targetPath = $target->getDirectory().'/'.self::RESPONSIVE_DIR.'/'.$relativePath;

            if ($sameDisk) {
                $sourceFs->copy($file, $targetPath);

                continue;
            }

            $stream = $sourceFs->readStream($file);

            try {
                $targetFs->writeStream($targetPath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
