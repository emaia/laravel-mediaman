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
use Emaia\MediaMan\Generators\FileNamer;
use Emaia\MediaMan\Generators\PathGenerator;
use Emaia\MediaMan\Generators\UrlGenerator;
use Emaia\MediaMan\Traits\ResolvesModels;
use Emaia\MediaMan\Traits\ResponsiveImages;
use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Mail\Attachable;
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

    const string PROPERTY_DIMENSIONS = 'dimensions';

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
            // delete the media directory
            $deleted = Storage::disk($media->disk)->deleteDirectory($media->getDirectory());
            // if failed, try deleting the file then
            ! $deleted && Storage::disk($media->disk)->delete($media->getPath());

            event(new MediaDeleted($media));
        });

        static::updating(function ($media) {
            // If the disk attribute is changed, validate the new disk usability
            if ($media->isDirty('disk')) {
                $newDisk = $media->disk; // updated disk
                self::ensureDiskUsability($newDisk);
            }
        });

        static::updated(function ($media) {
            $originalDisk = $media->getOriginal('disk');
            $newDisk = $media->disk;

            $originalFileName = $media->getOriginal('file_name');
            $newFileName = $media->file_name;

            $path = $media->getDirectory();

            // If the disk has changed, move the file to the new disk first
            if ($media->isDirty('disk')) {
                $filePathOnOriginalDisk = $path.'/'.$originalFileName;
                $fileContent = Storage::disk($originalDisk)->get($filePathOnOriginalDisk);

                // Store the file to the new disk
                Storage::disk($newDisk)->put($filePathOnOriginalDisk, $fileContent);

                // Delete the original file
                Storage::disk($originalDisk)->delete($filePathOnOriginalDisk);
            }

            // If the filename has changed, rename the file on the disk it currently resides
            if ($media->isDirty('file_name')) {
                // Rename the file in the storage
                Storage::disk($newDisk)->move($path.'/'.$originalFileName, $path.'/'.$newFileName);
            }
        });
    }

    /**
     * Get the directory for files on disk.
     */
    public function getDirectory(): string
    {
        return app(PathGenerator::class)->getDirectory($this);
    }

    /**
     * Get the path to the file on disk with the correct extension for conversions.
     */
    public function getPath(string $conversion = ''): string
    {
        return $this->getPathWithCorrectExtension($conversion);
    }

    /**
     * Get the path with the correct extension based on conversion format detection.
     */
    protected function getPathWithCorrectExtension(string $conversion = ''): string
    {
        if ($conversion) {
            $directory = app(PathGenerator::class)->getPathForConversion($this, $conversion);
            $originalName = $this->file_name ?? '';
            $extension = $this->detectConversionFormat($conversion)
                ?: pathinfo($originalName, PATHINFO_EXTENSION);
            $fileName = app(FileNamer::class)->getConversionFileName(
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

    /**
     * Detect the output format of a conversion.
     */
    protected function detectConversionFormat(string $conversion): ?string
    {
        // check for cached conversion
        if (isset($this->conversionFormatCache[$conversion])) {
            return $this->conversionFormatCache[$conversion];
        }

        try {
            $conversionRegistry = app(ConversionRegistry::class);

            if (! $conversionRegistry->exists($conversion)) {
                return null;
            }

            // Only detect a format for image files
            if (! $this->isOfType(MediaType::IMAGE)) {
                return null;
            }

            // Attempt 1: pre-computed format from the registry
            $detectedFormat = $conversionRegistry->getFormat($conversion);

            if ($detectedFormat) {
                $this->conversionFormatCache[$conversion] = $detectedFormat;

                return $detectedFormat;
            }

            // Attempt 2: try to detect a format from conversion name
            $formatFromName = $this->detectFormatFromConversionName($conversion);

            if ($formatFromName) {
                $this->conversionFormatCache[$conversion] = $formatFromName;

                return $formatFromName;
            }

            // Attempt 3: infers the format based on an existing file
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

        // Cache null result to avoid repeated processing
        $this->conversionFormatCache[$conversion] = null;

        return null;
    }

    /**
     * Determine if the file is of the specified type.
     */
    public function isOfType(string|MediaType $type): bool
    {
        $typeValue = $type instanceof MediaType ? $type->value : $type;

        return $this->type === $typeValue;
    }

    /**
     * Detect the format based on the conversion name.
     */
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

    /**
     * Detects the format by checking if the file already exists with different extensions.
     */
    protected function detectFormatFromExistingFile(string $conversion): ?string
    {
        $formats = array_map(fn (MediaFormat $f) => $f->value, MediaFormat::detectableFormats());
        $baseDirectory = app(PathGenerator::class)->getPathForConversion($this, $conversion);
        $baseFileName = pathinfo($this->file_name, PATHINFO_FILENAME);

        foreach ($formats as $format) {
            $testPath = $baseDirectory.'/'.$baseFileName.'.'.$format;
            if ($this->filesystem()->exists($testPath)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Get the filesystem where the associated file is stored.
     */
    public function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Replace the extension of a filename.
     */
    public function replaceFileExtension(string $fileName, string $newExtension): string
    {
        $pathInfo = pathinfo($fileName);

        return $pathInfo['filename'].'.'.$newExtension;
    }

    /**
     * Ensure the specified disk exists and is writable.
     */
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

        // Accessibility checks for read-write operations
        $disk = Storage::disk($diskName);
        $tempFileName = 'temp_check_file_'.uniqid();

        try {
            // Attempt to write to the disk
            $disk->put($tempFileName, 'check');

            // Now, attempt to delete the temporary file
            $disk->delete($tempFileName);
        } catch (Exception $e) {
            throw new Exception("Failed to write or delete on the disk [$diskName]. Error: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return MediaFactory::new();
    }

    /**
     * Get file extension from a mime type with extended support.
     */
    public function getExtensionFromMimeType(string $mimeType): string
    {
        return MediaFormat::extensionFromMimeType($mimeType);
    }

    /**
     * The table associated with the model.
     */
    public function getTable(): string
    {
        return config('mediaman.tables.media', 'mediaman_media');
    }

    /**
     * Get the file extension.
     */
    public function getExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    /**
     * Get the file type.
     */
    public function getTypeAttribute(): string
    {
        return Str::before($this->mime_type, '/');
    }

    /**
     * Get the file size in human-readable format.
     */
    public function getFriendlySizeAttribute(): ?string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        if ($this->size == 0) {
            return '0 '.$units[1];
        }

        for ($i = 0; $this->size > 1024; $i++) {
            $this->size /= 1024;
        }

        return round($this->size, 2).' '.$units[$i];
    }

    /**
     * Get the original media url.
     */
    public function getMediaUrlAttribute(): string
    {
        return asset($this->filesystem()->url($this->getPath()));
    }

    /**
     * Get the original media uri.
     */
    public function getMediaUriAttribute(): string
    {
        return $this->filesystem()->url($this->getPath());
    }

    /**
     * Get the full path to the file with automatic format detection for conversions.
     */
    public function getFullPath(string $conversion = ''): string
    {
        return $this->filesystem()->path(
            $this->getPathWithCorrectExtension($conversion)
        );
    }

    /**
     * Get the original path (without format detection) - useful for internal operations.
     */
    public function getOriginalPath(string $conversion = ''): string
    {
        if ($conversion) {
            $directory = app(PathGenerator::class)->getPathForConversion($this, $conversion);
        } else {
            $directory = $this->getDirectory();
        }

        return $directory.'/'.$this->file_name;
    }

    /**
     * Get conversion URL only if the conversion exists.
     */
    public function getConversionUrl(string $conversion): ?string
    {
        if (! $this->hasConversion($conversion)) {
            return null;
        }

        return $this->getUrl($conversion);
    }

    /**
     * Check if a conversion file exists with automatic format detection.
     */
    public function hasConversion(string $conversion): bool
    {
        $path = $this->getPathWithCorrectExtension($conversion);

        return $this->filesystem()->exists($path);
    }

    /**
     * Get the url to the file with automatic format detection for conversions.
     */
    public function getUrl(string $conversion = ''): string
    {
        return app(UrlGenerator::class)->getUrl(
            $this,
            $conversion !== '' ? $conversion : null
        );
    }

    /**
     * Get URL with fallback - returns conversion URL if exists, otherwise original.
     */
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

    /**
     * Clear the conversion format cache.
     */
    public function clearConversionFormatCache(): void
    {
        $this->conversionFormatCache = [];
    }

    /**
     * Sync collections of a media
     */
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

    /**
     * Check if all collections should be detached
     */
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

    /**
     * Fetch collections
     */
    private function fetchCollections(mixed $collections): Collection|BaseCollection|Model|null
    {
        $model = $this->collectionModel();

        // an eloquent collection doesn't need to be fetched again;
        // it's treated as a valid source of MediaCollection resource
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

        // all array items should be of the same type
        // find by id or name based on the type of the first item in the array
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

    /**
     * Find one or many media by name.
     */
    public static function findByName(string|array $names, array $columns = ['*']): Collection|static|null
    {
        $query = static::query()->select($columns);

        if (is_array($names)) {
            return $query->whereIn('name', $names)->get();
        }

        return $query->where('name', $names)->first();
    }

    /**
     * Attach media to collections
     */
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

    /**
     * Detach media from collections
     */
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

    public function getCustomProperty(string $propertyName, ?string $default = null): mixed
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
        return $this->filesystem()->download(
            $this->getPath($conversion ?? ''),
            $this->file_name
        );
    }

    /**
     * Stream the file as an inline HTTP response (browsers display in-tab).
     */
    public function toInlineResponse(?string $conversion = null): StreamedResponse
    {
        return $this->filesystem()->response(
            $this->getPath($conversion ?? ''),
            $this->file_name
        );
    }

    /**
     * Open a read stream to the underlying file. Caller is responsible for closing it.
     *
     * @return resource
     */
    public function getStream(?string $conversion = null)
    {
        return $this->filesystem()->readStream(
            $this->getPath($conversion ?? '')
        );
    }

    /**
     * Generate a temporary signed URL for cloud disks that support it.
     *
     * @throws TemporaryUrlNotSupported when the disk has no temporary URL support
     */
    public function getTemporaryUrl(?DateTimeInterface $expiration = null, ?string $conversion = null): string
    {
        $filesystem = $this->filesystem();

        if (! method_exists($filesystem, 'providesTemporaryUrls') || ! $filesystem->providesTemporaryUrls()) {
            throw TemporaryUrlNotSupported::forDisk($this->disk);
        }

        $expiration ??= now()->addMinutes(
            (int) config('mediaman.temporary_url.default_lifetime_minutes', 5)
        );

        return app(UrlGenerator::class)->getTemporaryUrl(
            $this,
            $expiration,
            $conversion !== '' ? $conversion : null
        );
    }

    /**
     * Build a Mail Attachment for use in Laravel Mailables.
     * Pass an optional conversion name to attach a specific variant.
     */
    public function mailAttachment(?string $conversion = null): Attachment
    {
        return Attachment::fromStorageDisk($this->disk, $this->getPath($conversion ?? ''))
            ->as($this->file_name)
            ->withMime($this->mime_type);
    }

    /**
     * Attachable contract method — Laravel calls this when $mailable->attach($media)
     * is used. Returns the attachment for the original file (no conversion).
     */
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
     * Copy this media to another model.
     *
     * Creates a new Media record, copies the physical file (and conversions
     * and responsive variants), and attaches it to the target model. If any
     * file copy fails, the new Media record is rolled back.
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

    /**
     * Attach this media to another model without duplicating the file.
     *
     * This is a purely relational operation — it does not touch the file on disk.
     * To move between disks, change the `disk` attribute instead.
     */
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
        $conversionsDir = $this->getDirectory().'/'.self::CONVERSIONS_DIR;

        if (! $this->filesystem()->exists($conversionsDir)) {
            return;
        }

        $sameDisk = $this->disk === $target->disk;
        $sourceFs = $this->filesystem();
        $targetFs = $target->filesystem();

        foreach ($sourceFs->allFiles($conversionsDir) as $file) {
            $relativePath = substr($file, strlen($conversionsDir) + 1);
            $targetPath = $target->getDirectory().'/'.self::CONVERSIONS_DIR.'/'.$relativePath;

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

    protected function copyResponsiveVariants(Media $target): void
    {
        $responsiveDir = $this->getDirectory().'/'.self::RESPONSIVE_DIR;

        if (! $this->filesystem()->exists($responsiveDir)) {
            return;
        }

        $allFiles = $this->filesystem()->allFiles($responsiveDir);

        foreach ($allFiles as $file) {
            $relativePath = substr($file, strlen($responsiveDir) + 1);
            $targetPath = $target->getDirectory().'/'.self::RESPONSIVE_DIR.'/'.$relativePath;

            if ($this->disk === $target->disk) {
                $this->filesystem()->copy($file, $targetPath);
            } else {
                $stream = $this->filesystem()->readStream($file);
                $target->filesystem()->writeStream($targetPath, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
