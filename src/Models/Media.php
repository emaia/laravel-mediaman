<?php

namespace Emaia\MediaMan\Models;

use Emaia\MediaMan\Casts\Json;
use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Traits\ResponsiveImages;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionFunction;
use SplFileObject;

/**
 * @property int $id
 * @property string $file_name
 * @property string $mime_type
 * @property string $disk
 * @property string $type
 * @property float|int $size
 */
class Media extends Model
{
    use ResponsiveImages;

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
            !$deleted && Storage::disk($media->disk)->delete($media->getPath());
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
                $filePathOnOriginalDisk = $path . '/' . $originalFileName;
                $fileContent = Storage::disk($originalDisk)->get($filePathOnOriginalDisk);

                // Store the file to the new disk
                Storage::disk($newDisk)->put($filePathOnOriginalDisk, $fileContent);

                // Delete the original file
                Storage::disk($originalDisk)->delete($filePathOnOriginalDisk);
            }

            // If the filename has changed, rename the file on the disk it currently resides
            if ($media->isDirty('file_name')) {
                // Rename the file in the storage
                Storage::disk($newDisk)->move($path . '/' . $originalFileName, $path . '/' . $newFileName);
            }
        });
    }

    /**
     * Get the directory for files on disk.
     */
    public function getDirectory(): string
    {
        return $this->getKey() . '-' . md5($this->getKey() . config('app.key'));
    }

    /**
     * Get the path to the file on disk with correct extension for conversions.
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
        $directory = $this->getDirectory();
        $fileName = $this->file_name;

        if ($conversion) {
            $directory .= '/conversions/' . $conversion;

            // Try to detect the correct format for this conversion
            $detectedExtension = $this->detectConversionFormat($conversion);

            if ($detectedExtension) {
                $fileName = $this->replaceFileExtension($this->file_name, $detectedExtension);
            }
        }

        return $directory . '/' . $fileName;
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

            if (!$conversionRegistry->exists($conversion)) {
                return null;
            }

            // Only detect a format for image files
            if (!$this->isOfType('image')) {
                return null;
            }

            $converter = $conversionRegistry->get($conversion);

            // Attempt 1: try to detect a format with Reflection
            $detectedFormat = $this->detectFormatWithReflection($converter);

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
            return null;
        }

        // Cache null result to avoid repeated processing
        $this->conversionFormatCache[$conversion] = null;
        return null;
    }

    /**
     * Determine if the file is of the specified type.
     */
    public function isOfType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Get a format from conversion code using Reflection
     */
    protected function detectFormatWithReflection(callable $converter): ?string
    {
        try {
            if (is_callable($converter)) {
                $reflection = new ReflectionFunction($converter);
                $code = $this->getClosureCode($reflection);

                if ($code) {
                    // Check for specific method call
                    $formatMethods = [
                        'toWebp(' => 'webp',
                        '->toWebp(' => 'webp',
                        'toAvif(' => 'avif',
                        '->toAvif(' => 'avif',
                        'toPng(' => 'png',
                        '->toPng(' => 'png',
                        'toJpeg(' => 'jpg',
                        '->toJpeg(' => 'jpg',
                        'toGif(' => 'gif',
                        '->toGif(' => 'gif',
                        'toBmp(' => 'bmp',
                        '->toBmp(' => 'bmp',
                        'toTiff(' => 'tiff',
                        '->toTiff(' => 'tiff',
                        'toHeic(' => 'heic',
                        '->toHeic(' => 'heic',
                        'toHeif(' => 'heif',
                        '->toHeif(' => 'heif',
                    ];

                    foreach ($formatMethods as $method => $format) {
                        if (stripos($code, $method) !== false) {
                            return $format;
                        }
                    }

                    // check for specific encoding
                    if (preg_match('/encode\w*\([\'"]([^\'\"]+)[\'"]/', $code, $matches)) {
                        return $this->getExtensionFromMimeType($matches[1]);
                    }
                }
            }
        } catch (Exception $e) {
            // If fails, go to the next method.
        }

        return null;
    }

    /**
     * Extract the closure code using Reflection.
     */
    protected function getClosureCode(ReflectionFunction $reflection): ?string
    {
        try {
            $filename = $reflection->getFileName();
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();

            if (!$filename || !$startLine || !$endLine) {
                return null;
            }

            // read only the necessary lines
            $file = new SplFileObject($filename);
            $file->seek($startLine - 1);

            $code = '';
            for ($i = $startLine; $i <= $endLine; $i++) {
                $code .= $file->current();
                $file->next();
            }

            return $code;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get file extension from a mime type with extended support.
     */
    public function getExtensionFromMimeType(string $mimeType): string
    {
        $map = [
            // Standard formats
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',

            // Extended formats supported by Intervention Image
            'image/avif' => 'avif',
            'image/tiff' => 'tiff',
            'image/tif' => 'tif',
            'image/jp2' => 'jp2',     // JPEG 2000
            'image/jpx' => 'jpx',     // JPEG 2000 Part 2
            'image/jpm' => 'jpm',     // JPEG 2000 Part 6
            'image/heic' => 'heic',   // HEIC (High-Efficiency Image Format)
            'image/heif' => 'heif',   // HEIF (High-Efficiency Image Format)

            // Alternative mime types
            'image/x-ms-bmp' => 'bmp',
            'image/vnd.adobe.photoshop' => 'psd',
            'image/x-photoshop' => 'psd',
            'image/x-windows-bmp' => 'bmp',
        ];

        return $map[$mimeType] ?? 'jpg';
    }

    /**
     * Detect the format based on conversion name.
     */
    protected function detectFormatFromConversionName(string $conversion): ?string
    {
        $conversion = strtolower($conversion);

        $patterns = [
            '/webp/' => 'webp',
            '/avif/' => 'avif',
            '/png/' => 'png',
            '/jpg|jpeg/' => 'jpg',
            '/gif/' => 'gif',
            '/bmp/' => 'bmp',
            '/tiff|tif/' => 'tiff',
            '/heic/' => 'heic',
            '/heif/' => 'heif',
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
        $formats = ['webp', 'avif', 'png', 'jpg', 'gif', 'bmp', 'tiff', 'heic', 'heif'];
        $baseDirectory = $this->getDirectory() . '/conversions/' . $conversion;
        $baseFileName = pathinfo($this->file_name, PATHINFO_FILENAME);

        foreach ($formats as $format) {
            $testPath = $baseDirectory . '/' . $baseFileName . '.' . $format;
            if ($this->filesystem()->exists($testPath)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Get the filesystem where the associated file is stored.
     */
    public function filesystem()
    {
        return Storage::disk($this->disk);
    }

    /**
     * Replace the extension of a filename.
     */
    public function replaceFileExtension(string $fileName, string $newExtension): string
    {
        $pathInfo = pathinfo($fileName);
        return $pathInfo['filename'] . '.' . $newExtension;
    }

    /**
     * Ensure the specified disk exists and is writable.
     */
    protected static function ensureDiskUsability(string $diskName): void
    {
        $allDisks = config('filesystems.disks');

        if (!array_key_exists($diskName, $allDisks)) {
            throw new InvalidArgumentException("Disk [$diskName] is not defined in the filesystems configuration.");
        }

        // Early return if the accessibility check is disabled
        if (!config('mediaman.check_disk_accessibility', false)) {
            return;
        }

        // Accessibility checks for read-write operations
        $disk = Storage::disk($diskName);
        $tempFileName = 'temp_check_file_' . uniqid();

        try {
            // Attempt to write to the disk
            $disk->put($tempFileName, 'check');

            // Now, attempt to delete the temporary file
            $disk->delete($tempFileName);
        } catch (Exception $e) {
            throw new Exception("Failed to write or delete on the disk [$diskName]. Error: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The table associated with the model.
     */
    public function getTable()
    {
        return config('mediaman.tables.media');
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
            return '0 ' . $units[1];
        }

        for ($i = 0; $this->size > 1024; $i++) {
            $this->size /= 1024;
        }

        return round($this->size, 2) . ' ' . $units[$i];
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
     * Get the url to the file with automatic format detection for conversions.
     */
    public function getUrl(string $conversion = ''): string
    {
        return $this->filesystem()->url(
            $this->getPathWithCorrectExtension($conversion)
        );
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
        $directory = $this->getDirectory();

        if ($conversion) {
            $directory .= '/conversions/' . $conversion;
        }

        return $directory . '/' . $this->file_name;
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
     * Clear the conversion format cache.
     */
    public function clearConversionFormatCache(): void
    {
        $this->conversionFormatCache = [];
    }

    /**
     * Find a media by media name
     */
    public function scopeFindByName($query, $names, array $columns = ['*'])
    {
        if (is_array($names)) {
            return $query->select($columns)->whereIn('name', $names)->get();
        }

        return $query->select($columns)->where('name', $names)->first();
    }

    /**
     * Sync collections of a media
     */
    public function syncCollections($collections, $detaching = true)
    {
        if ($this->shouldDetachAll($collections)) {
            return $this->collections()->sync([]);
        }

        $fetch = $this->fetchCollections($collections);

        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            $ids = $fetch->pluck('id')->all();

            return $this->collections()->sync($ids, $detaching);
        } else {
            return $this->collections()->sync($fetch->id, $detaching);
        }

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
            MediaCollection::class,
            config('mediaman.tables.collection_media'),
            'collection_id',
            'media_id');
    }

    /**
     * Fetch collections
     */
    private function fetchCollections($collections)
    {
        // an eloquent collection doesn't need to be fetched again;
        // it's treated as a valid source of MediaCollection resource
        if ($collections instanceof Collection) {
            return $collections;
        }
        // todo: check for instance of media model / collection instead?
        if ($collections instanceof BaseCollection) {
            $ids = $collections->pluck('id')->all();

            return MediaCollection::find($ids);
        }

        if (is_object($collections) && isset($collections->id)) {
            return MediaCollection::find($collections->id);
        }

        if (is_numeric($collections)) {
            return MediaCollection::find($collections);
        }

        if (is_string($collections)) {
            return MediaCollection::findByName($collections);
        }

        // all array items should be of the same type
        // find by id or name based on the type of the first item in the array
        if (is_array($collections) && isset($collections[0])) {
            if (is_numeric($collections[0])) {
                return MediaCollection::find($collections);
            }

            if (is_string($collections[0])) {
                return MediaCollection::findByName($collections);
            }
        }

        return null;
    }

    /**
     * Attach media to collections
     */
    public function attachCollections($collections)
    {
        $fetch = $this->fetchCollections($collections);
        if ($fetch->count()) {

            $ids = $fetch->pluck('id');
            $res = $this->collections()->sync($ids, false);
            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        } else {
            $res = $this->collections()->sync($fetch->id, false);
            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        }

    }

    /**
     * Detach media from collections
     */
    public function detachCollections(Collection|int|bool|array|string|MediaCollection|null $collections): ?int
    {
        if ($this->shouldDetachAll($collections)) {
            return $this->collections()->detach();
        }

        // todo: check if null is returned on failure
        $fetch = $this->fetchCollections($collections);

        if ($fetch->count()) {
            $ids = $fetch->pluck('id')->all();

            return $this->collections()->detach($ids);
        } else {
            /* @var $fetch Media */
            return $this->collections()->detach($fetch->id);
        }

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

    public function forgetCustomProperty(string $name): self
    {
        $customProperties = $this->custom_properties;

        Arr::forget($customProperties, $name);

        $this->custom_properties = $customProperties;

        return $this;
    }

}