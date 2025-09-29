<?php

namespace Emaia\MediaMan\Traits;

use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaChannel;
use Emaia\MediaMan\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Throwable;

trait HasMedia
{
    /** @var MediaChannel[] */
    protected array $mediaChannels = [];

    /** @var array Cache para media por channel */
    protected array $mediaCache = [];

    /**
     * Determine if there is any media in the specified group.
     */
    public function hasMedia(string $channel = 'default'): bool
    {
        return $this->getMedia($channel)->isNotEmpty();
    }

    /**
     * Get all the media in the specified group.
     */
    public function getMedia(?string $channel = 'default')
    {
        $cacheKey = $channel ?? 'default';

        if (!isset($this->mediaCache[$cacheKey])) {
            if ($channel === null) {
                $this->mediaCache[$cacheKey] = $this->media()->get();
            } else {
                $this->mediaCache[$cacheKey] = $this->media()->wherePivot('channel', $channel)->get();
            }
        }

        return $this->mediaCache[$cacheKey];
    }

    public function media(): MorphToMany
    {
        return $this
            ->morphToMany(config('mediaman.models.media'), 'mediable', config('mediaman.tables.mediables'))
            ->withPivot('channel');
    }

    /**
     * Get the URL of the first media item with automatic format detection.
     */
    public function getFirstMediaUrl(?string $channel = 'default', string $conversion = ''): string
    {
        if (!$media = $this->getFirstMedia($channel)) {
            return '';
        }

        return $media->getUrl($conversion);
    }

    /**
     * Get the first media item in the specified channel.
     */
    public function getFirstMedia(?string $channel = 'default')
    {
        return $this->getMedia($channel)->first();
    }

    /**
     * Attach media to the specified channel.
     */
    public function attachMedia($media, string $channel = 'default', array $conversions = []): ?int
    {
        $syncResult = $this->syncMedia($media, $channel, $conversions, false);

        $attachedCount = count($syncResult['attached'] ?? []);

        return $attachedCount > 0 ? $attachedCount : null;
    }

    /**
     * Sync media to the specified channel.
     *
     * This will remove the media that aren't in the provided list
     * and add those which aren't already attached if $detaching is truthy.
     *
     * @param mixed $media
     * @param string $channel
     * @param array $conversions
     * @param bool $detaching
     * @return array|null
     */
    public function syncMedia(mixed $media, string $channel = 'default', array $conversions = [], bool $detaching = true): ?array
    {
        $this->registerMediaChannels();

        if ($detaching === true && $this->shouldDetachAll($media)) {
            $detachedIds = $this->getMedia($channel)->pluck('id')->toArray();
            $this->clearMediaChannel($channel);
            return ['attached' => [], 'detached' => $detachedIds, 'updated' => []];
        }

        $ids = $this->parseMediaIds($media);

        $mediaChannel = $this->getMediaChannel($channel);

        if ($mediaChannel && $mediaChannel->hasConversions()) {
            $conversions = array_merge(
                $conversions,
                $mediaChannel->getConversions()
            );
        }

        if (!empty($conversions)) {
            $model = config('mediaman.models.media');

            $mediaInstances = $model::findMany($ids);

            $mediaInstances->each(function ($mediaInstance) use ($conversions) {
                PerformConversions::dispatch(
                    $mediaInstance,
                    $conversions
                );
            });
        }

        try {
            $currentMediaIds = $this->getMedia($channel)->pluck('id')->toArray();

            $attached = [];
            $detached = [];
            $updated = [];


            if ($detaching) {
                $toDetach = array_diff($currentMediaIds, $ids);
                if (!empty($toDetach)) {
                    $this->media()->wherePivot('channel', $channel)->detach($toDetach);
                    $detached = array_values($toDetach);
                }
            }

            foreach ($ids as $id) {
                if (!in_array($id, $currentMediaIds)) {
                    $this->media()->attach($id, ['channel' => $channel]);
                    $attached[] = $id;
                } else {
                    $updated[] = $id;
                }
            }

            $this->clearMediaCache($channel);

            return ['attached' => $attached, 'detached' => $detached, 'updated' => $updated];
        } catch (Throwable $th) {
            return null;
        }
    }

    /**
     * Register all the model's media channels.
     *
     * @return void
     */
    public function registerMediaChannels()
    {
        //
    }

    /**
     * Check if all media should be detached
     *
     * bool|null|empty-string|empty-array to detach all media
     */
    protected function shouldDetachAll($media): bool
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
     * Detach all the media in the specified channel.
     */
    public function clearMediaChannel(string $channel = 'default'): void
    {
        $this->media()->wherePivot('channel', $channel)->detach();
        $this->clearMediaCache($channel);
    }

    /**
     * Limpar cache de media para um channel específico ou todos.
     */
    protected function clearMediaCache(?string $channel = null): void
    {
        if ($channel === null) {
            $this->mediaCache = [];
        } else {
            $cacheKey = $channel;
            unset($this->mediaCache[$cacheKey]);
        }
    }

    /**
     * Parse the media id's from the mixed input.
     *
     * @param mixed $media
     * @return array
     */
    protected function parseMediaIds($media)
    {
        if ($media instanceof Collection) {
            return $media->modelKeys();
        }

        if ($media instanceof Media) {
            return [$media->getKey()];
        }

        return (array)$media;
    }

    /**
     * Get the media channel with the specified name.
     *
     * @return MediaChannel|null
     */
    public function getMediaChannel(string $name)
    {
        return $this->mediaChannels[$name] ?? null;
    }

    /**
     * Detach the specified media.
     */
    public function detachMedia(mixed $media = null): ?int
    {
        $count = $this->media()->detach($media);

        $this->mediaCache = [];

        return $count > 0 ? $count : null;
    }

    /**
     * Get the URL with fallback for the first media item.
     */
    public function getFirstMediaUrlWithFallback(?string $channel = 'default', string $conversion = ''): string
    {
        if (!$media = $this->getFirstMedia($channel)) {
            return '';
        }

        return $media->getUrlWithFallback($conversion);
    }

    /**
     * Get conversion URL only if it exists for the first media item.
     */
    public function getFirstMediaConversionUrl(?string $channel = 'default', string $conversion = ''): ?string
    {
        if (!$media = $this->getFirstMedia($channel)) {
            return null;
        }

        return $media->getConversionUrl($conversion);
    }

    /**
     * Check if the first media item has a specific conversion.
     */
    public function hasMediaConversion(?string $channel = 'default', string $conversion = ''): bool
    {
        if (!$media = $this->getFirstMedia($channel)) {
            return false;
        }

        return $media->hasConversion($conversion);
    }

    /**
     * Register a new media group.
     *
     * @return MediaChannel
     */
    protected function addMediaChannel(string $name)
    {
        $channel = new MediaChannel;

        $this->mediaChannels[$name] = $channel;

        return $channel;
    }
}
