<?php

namespace Emaia\MediaMan\Models;

use Emaia\MediaMan\Casts\Json;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    protected $fillable = [
        'name', 'file_name', 'mime_type', 'size', 'disk', 'data',
    ];

    protected $casts = [
        'data' => Json::class,
    ];

    protected $appends = ['friendly_size',  'media_uri', 'media_url', 'type', 'extension'];

    public static function booted()
    {
        static::deleted(static function ($media) {
            // delete the media directory
            $deleted = Storage::disk($media->disk)->deleteDirectory($media->getDirectory());
            // if failed, try deleting the file then
            ! $deleted && Storage::disk($media->disk)->delete($media->getPath());
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
     * The table associated with the model.
     *
     */
    public function getTable()
    {
        return config('mediaman.tables.media');
    }

    /**
     * Get the file extension.
     *
     */
    public function getExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    /**
     * Get the file type.
     *
     */
    public function getTypeAttribute(): string
    {
        return Str::before($this->mime_type, '/');
    }

    /**
     * Determine if the file is of the specified type.
     *
     */
    public function isOfType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * Get the file size in human-readable format.
     *
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
     *
     */
    public function getMediaUrlAttribute(): string
    {
        return asset($this->filesystem()->url($this->getPath()));
    }

    /**
     * Get the original media uri.
     *
     */
    public function getMediaUriAttribute(): string
    {
        return $this->filesystem()->url($this->getPath());
    }

    /**
     * Get the url to the file.
     *
     */
    public function getUrl(string $conversion = ''): string
    {
        return $this->filesystem()->url(
            $this->getPath($conversion)
        );
    }

    /**
     * Get the full path to the file.
     *
     */
    public function getFullPath(string $conversion = ''): string
    {
        return $this->filesystem()->path(
            $this->getPath($conversion)
        );
    }

    /**
     * Get the path to the file on disk.
     *
     */
    public function getPath(string $conversion = ''): string
    {
        $directory = $this->getDirectory();

        if ($conversion) {
            $directory .= '/conversions/'.$conversion;
        }

        return $directory.'/'.$this->file_name;
    }

    /**
     * Get the directory for files on disk.
     *
     */
    public function getDirectory(): string
    {
        return $this->getKey().'-'.md5($this->getKey().config('app.key'));
    }

    /**
     * Get the filesystem where the associated file is stored.
     *
     */
    public function filesystem()
    {
        return Storage::disk($this->disk);
    }

    /**
     * Find a media by media name
     *
     */
    public function scopeFindByName($query, $names, array $columns = ['*'])
    {
        if (is_array($names)) {
            return $query->select($columns)->whereIn('name', $names)->get();
        }

        return $query->select($columns)->where('name', $names)->first();
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
     * Sync collections of a media
     *
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
     * Attach media to collections
     *
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
     *
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

    /**
     * Ensure the specified disk exists and is writable.
     *
     * This method first checks if the provided disk name exists in the
     * filesystems configuration. Next, it ensures that the disk is accessible
     * by attempting to write and then delete a temporary file.
     *
     * @param string $diskName  The name of the disk as defined in the filesystems configuration.
     * @return void
     *
     * @throws \InvalidArgumentException If the disk is not defined in the filesystems configuration.
     * @throws \Exception If there's an error writing to or deleting from the disk.
     */
    protected static function ensureDiskUsability(string $diskName): void
    {
        $allDisks = config('filesystems.disks');

        if (! array_key_exists($diskName, $allDisks)) {
            throw new \InvalidArgumentException("Disk [{$diskName}] is not defined in the filesystems configuration.");
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
        } catch (\Exception $e) {
            throw new \Exception("Failed to write or delete on the disk [{$diskName}]. Error: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if all collections should be detached
     *
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
     * Fetch collections
     *
     * returns single collection for single item
     * and multiple collections for multiple items
     * todo: exception / strict return types
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
}
