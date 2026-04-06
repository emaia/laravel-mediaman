<?php

namespace Emaia\MediaMan\Models;

use Emaia\MediaMan\Traits\ResolvesModels;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as BaseCollection;

/**
 * @property int $id
 *
 * @method findByName($name)
 */
class MediaCollection extends Model
{
    use ResolvesModels;
    protected $fillable = [
        'name', 'created_at', 'updated_at',
    ];

    public function getTable(): string
    {
        return config('mediaman.tables.collections', 'mediaman_collections');
    }

    public function scopeFindByName($query, string|array $names, array $columns = ['*'])
    {
        if (is_array($names)) {
            return $query->select($columns)->whereIn('name', $names)->get();
        }

        return $query->select($columns)->where('name', $names)->first();
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany($this->mediaModel(), config('mediaman.tables.collection_media'), 'media_id', 'collection_id');
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
            $ids = $fetch->modelKeys();

            return $this->media()->sync($ids, $detaching);
        }

        if (method_exists($fetch, 'getKey')) {
            return $this->media()->sync($fetch->getKey());
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
            $ids = $fetch->modelKeys();
            $res = $this->media()->sync($ids, false);

            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        }

        if (method_exists($fetch, 'getKey')) {
            $res = $this->media()->sync($fetch->getKey(), false);

            $attached = count($res['attached']);

            return $attached > 0 ? $attached : null;
        }

        return null;
    }

    /**
     * Detach media from a collection
     */
    public function detachMedia(mixed $media): ?int
    {
        if ($this->shouldDetachAll($media)) {
            return $this->media()->detach();
        }

        if (! $fetch = $this->fetchMedia($media)) {
            return null;
        }

        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            $ids = $fetch->modelKeys();

            return $this->media()->detach($ids);
        }

        if (method_exists($fetch, 'getKey')) {
            return $this->media()->detach($fetch->getKey());
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
    private function fetchMedia(mixed $media): mixed
    {
        $model = $this->mediaModel();

        // an eloquent collection doesn't need to be fetched again
        // it's treated as a valid source of Media resource
        if ($media instanceof EloquentCollection) {
            return $media;
        }

        if ($media instanceof BaseCollection) {
            $ids = $media->map(fn ($item) => method_exists($item, 'getKey') ? $item->getKey() : $item)->all();

            return $model::find($ids);
        }

        if (is_object($media) && method_exists($media, 'getKey')) {
            return $model::find($media->getKey());
        }

        if (is_numeric($media)) {
            return $model::find($media);
        }

        if (is_string($media)) {
            return $model::findByName($media);
        }

        // all array items should be of same type
        // find by id or name based on the type of first item in the array
        if (is_array($media) && isset($media[0])) {
            if ($media[0] instanceof BaseCollection) {
                return $media;
            }

            if (is_numeric($media[0])) {
                return $model::find($media);
            }

            if (is_string($media[0])) {
                return $model::findByName($media);
            }
        }

        return null;
    }
}
