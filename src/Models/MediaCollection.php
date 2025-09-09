<?php

namespace Emaia\MediaMan\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;

/**
 * @property int $id
 *
 * @method findByName($name)
 */
class MediaCollection extends Model
{
    protected $fillable = [
        'name', 'created_at', 'updated_at',
    ];

    public function getTable()
    {
        return config('mediaman.tables.collections');
    }

    public function scopeFindByName($query, $names, array $columns = ['*'])
    {
        if (is_array($names)) {
            return $query->select($columns)->whereIn('name', $names)->get();
        }

        return $query->select($columns)->where('name', $names)->first();
    }

    public function media()
    {
        return $this->belongsToMany(Media::class, config('mediaman.tables.collection_media'), 'media_id', 'collection_id');
    }

    /**
     * Sync media of a collection
     */
    public function syncMedia($media, bool $detaching = true): ?array
    {
        if ($this->shouldDetachAll($media)) {
            return $this->media()->sync([]);
        }

        if (! $fetch = $this->fetchMedia($media)) {
            return null;
        }

        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            $ids = $fetch->pluck('id')->all();

            return $this->media()->sync($ids, $detaching);
        }

        if (isset($fetch->id)) {
            return $this->media()->sync($fetch->id);
        }

        return null;
    }

    /**
     * Attach media to a collection
     *
     * @param  null|int|string|array|Media|EloquentCollection  $media
     * @return int|null number of attached media or null
     */
    public function attachMedia($media): ?int
    {
        $fetch = $this->fetchMedia($media);

        if (! $fetch = $this->fetchMedia($media)) {
            return null;
        }

        // to be consistent with the return type of detach method
        // which returns number of detached model, we're using sync without detachment
        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            $ids = $fetch->pluck('id')->all();
            $res = $this->media()->sync($ids, false);

            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        }

        if (isset($fetch->id)) {
            $res = $this->media()->sync($fetch->id, false);

            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        }

        return null;
    }

    /**
     * Detach media from a collection
     */
    public function detachMedia($media)
    {
        if ($this->shouldDetachAll($media)) {
            return $this->media()->detach();
        }

        if (! $fetch = $this->fetchMedia($media)) {
            return null;
        }

        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            $ids = $fetch->pluck('id')->all();

            return $this->media()->detach($ids);
        }

        if (isset($fetch->id)) {
            return $this->media()->detach($fetch->id);
        }

        return null;
    }

    /**
     * Check if all media should be detached
     */
    private function shouldDetachAll($media): bool
    {
        if (is_bool($media) || empty($media)) {
            return true;
        }

        if (is_countable($media) && count($media) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Fetch media
     *
     * returns single collection for single item
     * and multiple collections for multiple items
     * todo: exception / strict return types
     */
    private function fetchMedia($media)
    {
        // an eloquent collection doesn't need to be fetched again
        // it's treated as a valid source of Media resource
        if ($media instanceof EloquentCollection) {
            return $media;
        }

        // todo: check for instance of media model / collection instead?
        if ($media instanceof BaseCollection) {
            $ids = $media->pluck('id')->all();

            return Media::find($ids);
        }

        if (is_object($media) && isset($media->id)) {
            return Media::find($media->id);
        }

        if (is_numeric($media)) {
            return MediaCollection::find($media);
        }

        if (is_string($media)) {
            return Media::findByName($media);
        }

        // all array items should be of same type
        // find by id or name based on the type of first item in the array
        if (is_array($media) && isset($media[0])) {
            if ($media[0] instanceof BaseCollection) {
                return $media;
            }

            if (is_numeric($media[0])) {
                return Media::find($media);
            }

            if (is_string($media[0])) {
                return Media::findByName($media);
            }
        }

        return null;
    }
}
