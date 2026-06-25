<?php

namespace Emaia\MediaMan\Models;

use Emaia\MediaMan\Events\MediaPrunedFromCollection;
use Emaia\MediaMan\Exceptions\MediaNotAcceptedByCollection;
use Emaia\MediaMan\Traits\ResolvesModels;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as BaseCollection;

/**
 * @property int $id
 * @property string $name
 * @property int|null $max_items
 * @property array|null $allowed_mime_types
 * @property string|null $fallback_url
 * @property string|null $fallback_path
 */
class MediaCollection extends Model
{
    use ResolvesModels;

    protected $fillable = [
        'name', 'max_items', 'allowed_mime_types', 'fallback_url', 'fallback_path',
        'created_at', 'updated_at',
    ];

    protected $casts = [
        'max_items' => 'integer',
        'allowed_mime_types' => 'json',
    ];

    public function getTable(): string
    {
        return config('mediaman.tables.collections', 'mediaman_collections');
    }

    public static function findByName(string|array $names, array $columns = ['*']): Collection|static|null
    {
        $query = static::query()->select($columns);

        if (is_array($names)) {
            return $query->whereIn('name', $names)->get();
        }

        return $query->where('name', $names)->first();
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->mediaModel(),
            config('mediaman.tables.collection_media'),
            'collection_id',
            'media_id'
        );
    }

    public function singleFile(): self
    {
        return $this->onlyKeepLatest(1);
    }

    public function onlyKeepLatest(int $count): self
    {
        $this->max_items = $count;

        return $this;
    }

    public function acceptsMimeTypes(array $types): self
    {
        $this->allowed_mime_types = $types;

        return $this;
    }

    public function validateMedia(Media $media): void
    {
        $allowed = $this->allowed_mime_types;

        if ($allowed === null || $allowed === []) {
            return;
        }

        $mimeType = $media->mime_type;

        foreach ($allowed as $pattern) {
            if ($this->mimeTypeMatches($mimeType, $pattern)) {
                return;
            }
        }

        throw MediaNotAcceptedByCollection::mimeTypeNotAllowed($mimeType, $this->name);
    }

    private function mimeTypeMatches(string $mimeType, string $pattern): bool
    {
        if ($pattern === $mimeType) {
            return true;
        }

        if (str_ends_with($pattern, '/*')) {
            return str_starts_with($mimeType, substr($pattern, 0, -1));
        }

        return false;
    }

    /**
     * TODO: for collections with very large item counts, consider chunking
     * or a sub-query approach instead of loading all media into memory.
     */
    public function enforceMaxItems(): void
    {
        $max = $this->max_items;

        if ($max === null || $max < 1) {
            return;
        }

        $table = config('mediaman.tables.media');

        $attached = $this->media()
            ->orderBy("$table.created_at", 'asc')
            ->orderBy("$table.id", 'asc')
            ->get();

        if ($attached->count() <= $max) {
            return;
        }

        $toDetach = $attached->take($attached->count() - $max);
        $detachedIds = $toDetach->modelKeys();

        $this->media()->detach($detachedIds);

        event(new MediaPrunedFromCollection($this, $detachedIds));
    }

    public function attachMedia(mixed $media): ?int
    {
        if (! $fetch = $this->fetchMedia($media)) {
            return null;
        }

        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            $mediaItems = $fetch;

            foreach ($mediaItems as $item) {
                if ($item instanceof Media) {
                    $this->validateMedia($item);
                }
            }

            $ids = $mediaItems->modelKeys();
            $res = $this->media()->sync($ids, false);

            $attached = count($res['attached']);
        } elseif (is_object($fetch) && method_exists($fetch, 'getKey')) {
            if ($fetch instanceof Media) {
                $this->validateMedia($fetch);
            }

            $res = $this->media()->sync($fetch->getKey(), false);

            $attached = count($res['attached']);
        } else {
            return null;
        }

        $this->enforceMaxItems();

        return $attached > 0 ? $attached : null;
    }

    public function syncMedia(mixed $media, bool $detaching = true): ?array
    {
        if ($this->shouldDetachAll($media)) {
            return $this->media()->sync([]);
        }

        if (! $fetch = $this->fetchMedia($media)) {
            return null;
        }

        if (is_countable($fetch)) {
            /** @var Collection $fetch */
            foreach ($fetch as $item) {
                if ($item instanceof Media) {
                    $this->validateMedia($item);
                }
            }

            $ids = $fetch->modelKeys();
            $result = $this->media()->sync($ids, $detaching);

            $this->enforceMaxItems();

            return $result;
        }

        if (is_object($fetch) && method_exists($fetch, 'getKey')) {
            if ($fetch instanceof Media) {
                $this->validateMedia($fetch);
            }

            $result = $this->media()->sync($fetch->getKey(), $detaching);

            $this->enforceMaxItems();

            return $result;
        }

        return null;
    }

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

        if (is_object($fetch) && method_exists($fetch, 'getKey')) {
            return $this->media()->detach($fetch->getKey());
        }

        return null;
    }

    private function shouldDetachAll(mixed $media): bool
    {
        if (is_bool($media) || empty($media)) {
            return true;
        }

        if (is_countable($media) && count($media) === 0) {
            return true;
        }

        return false;
    }

    private function fetchMedia(mixed $media): mixed
    {
        $model = $this->mediaModel();

        if ($media instanceof EloquentCollection) {
            return $media;
        }

        if ($media instanceof BaseCollection) {
            $ids = $media->map(
                fn ($item) => is_object($item) && method_exists($item, 'getKey')
                    ? $item->getKey()
                    : $item
            )->all();

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

        if (is_array($media) && isset($media[0])) {
            if ($media[0] instanceof BaseCollection) {
                return $media;
            }

            if ($media[0] instanceof Model) {
                return $model::find(collect($media)->map(fn ($m) => $m->getKey())->all());
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
