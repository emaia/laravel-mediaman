<?php

namespace Emaia\MediaMan\Traits;

use Emaia\MediaMan\Exceptions\MediaNotAcceptedByCollection;
use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaChannel;
use Emaia\MediaMan\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

trait HasMedia
{
    use ResolvesModels;

    /** @var MediaChannel[] */
    protected array $mediaChannels = [];

    /** @var array Cache para media por channel */
    protected array $mediaCache = [];

    /**
     * Determine if there is any media in the specified channel.
     */
    public function hasMedia(string $channel = Media::DEFAULT_CHANNEL): bool
    {
        return $this->getMedia($channel)->isNotEmpty();
    }

    /**
     * Sentinel cache key for the "all channels" (null) lookup so it does not
     * collide with the explicit DEFAULT_CHANNEL key. Pivot data never contains
     * a channel literally equal to this string.
     */
    private const ALL_CHANNELS_CACHE_KEY = '__all_channels__';

    /**
     * Get all the media in the specified channel.
     */
    public function getMedia(?string $channel = Media::DEFAULT_CHANNEL): Collection
    {
        $cacheKey = $channel ?? self::ALL_CHANNELS_CACHE_KEY;

        if (! isset($this->mediaCache[$cacheKey])) {
            if ($this->relationLoaded('media')) {
                $media = $this->getRelation('media');
                if ($channel === null) {
                    $this->mediaCache[$cacheKey] = $media;
                } else {
                    $this->mediaCache[$cacheKey] = $media->filter(fn ($m) => $m->pivot->channel === $channel)->values();
                }
            } else {
                if ($channel === null) {
                    $this->mediaCache[$cacheKey] = $this->media()->get();
                } else {
                    $table = config('mediaman.tables.mediables');
                    $this->mediaCache[$cacheKey] = $this->media()
                        ->wherePivot('channel', $channel)
                        ->orderByRaw("({$table}.order_column IS NULL) ASC, {$table}.order_column ASC")
                        ->get();
                }
            }
        }

        return $this->mediaCache[$cacheKey];
    }

    public function media(): MorphToMany
    {
        $table = config('mediaman.tables.mediables');

        return $this
            ->morphToMany($this->mediaModel(), 'mediable', $table)
            ->withPivot('channel', 'order_column')
            ->orderByRaw("({$table}.order_column IS NULL) ASC, {$table}.order_column ASC");
    }

    /**
     * Get the URL of the first media item with automatic format detection.
     */
    public function getFirstMediaUrl(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): string
    {
        if (! $media = $this->getFirstMedia($channel)) {
            return $this->getChannelFallbackUrl($channel, $conversion);
        }

        return $media->getUrl($conversion);
    }

    /**
     * Get the first media item in the specified channel.
     */
    public function getFirstMedia(?string $channel = Media::DEFAULT_CHANNEL)
    {
        return $this->getMedia($channel)->first();
    }

    /**
     * Attach media to the specified channel.
     *
     * If $order is null, new attaches receive the next sequential position
     * in the channel. Pass an explicit integer to set the starting position.
     */
    public function attachMedia($media, string $channel = Media::DEFAULT_CHANNEL, array $conversions = [], ?int $order = null): ?int
    {
        $syncResult = $this->syncMedia($media, $channel, $conversions, false, $order);

        $attachedCount = count($syncResult['attached'] ?? []);

        return $attachedCount > 0 ? $attachedCount : null;
    }

    /**
     * Sync media to the specified channel.
     *
     * Removes media not in the provided list and attaches new ones (when
     * $detaching is truthy). New attaches receive an order_column based on
     * the channel's current max(order_column)+1, unless $startOrder is set.
     */
    public function syncMedia(mixed $media, string $channel = Media::DEFAULT_CHANNEL, array $conversions = [], bool $detaching = true, ?int $startOrder = null): ?array
    {
        $this->registerMediaChannels();

        if ($detaching === true && $this->shouldDetachAll($media)) {
            $detachedIds = $this->getMedia($channel)->modelKeys();
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

        if (! empty($conversions)) {
            $model = $this->mediaModel();

            $mediaInstances = $model::findMany($ids);

            $mediaInstances->each(function ($mediaInstance) use ($conversions) {
                PerformConversions::dispatch(
                    $mediaInstance,
                    $conversions
                );
            });
        }

        try {
            $currentMediaIds = $this->getMedia($channel)->modelKeys();

            $attached = [];
            $detached = [];
            $updated = [];

            if ($detaching) {
                $toDetach = array_diff($currentMediaIds, $ids);
                if (! empty($toDetach)) {
                    $this->media()->wherePivot('channel', $channel)->detach($toDetach);
                    $detached = array_values($toDetach);
                }
            }

            $toAttach = [];
            foreach ($ids as $id) {
                if (in_array($id, $currentMediaIds)) {
                    $updated[] = $id;
                } else {
                    $toAttach[] = $id;
                }
            }

            if (! empty($toAttach)) {
                $baseOrder = $startOrder ?? $this->getNextOrder($channel);
                $pivotData = [];

                foreach ($toAttach as $index => $attachId) {
                    $pivotData[$attachId] = [
                        'channel' => $channel,
                        'order_column' => $baseOrder + $index,
                    ];
                }

                $this->media()->attach($pivotData);
                $attached = $toAttach;
            }

            $this->clearMediaCache($channel);

            return ['attached' => $attached, 'detached' => $detached, 'updated' => $updated];
        } catch (MediaNotAcceptedByCollection|QueryException|InvalidArgumentException $e) {
            // Domain and database-level exceptions must propagate so callers
            // can react (validation feedback, deadlock retries, etc.). Silently
            // returning null here hid validation failures behind a no-op
            // success indistinguishable from "nothing to sync".
            throw $e;
        } catch (Throwable $th) {
            Log::warning('MediaMan: Failed to sync media', [
                'channel' => $channel,
                'error' => $th->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reorder media in the specified channel by the given array of ids.
     * Positions are assigned 0..N-1 in the order of $ids.
     *
     * Throws InvalidArgumentException if any id is not currently attached
     * to this model in the given channel.
     */
    public function setMediaOrder(array $ids, string $channel = Media::DEFAULT_CHANNEL): void
    {
        $table = config('mediaman.tables.mediables');
        $morphType = $this->getMorphClass();

        $attachedIds = DB::table($table)
            ->where('mediable_type', $morphType)
            ->where('mediable_id', $this->getKey())
            ->where('channel', $channel)
            ->pluck('media_id')
            ->all();

        $invalid = array_diff($ids, $attachedIds);

        if (! empty($invalid)) {
            throw new \InvalidArgumentException(
                'Media ids not attached to this model in channel ['.$channel.']: '.implode(', ', $invalid)
            );
        }

        DB::transaction(function () use ($ids, $channel, $table, $morphType) {
            foreach ($ids as $index => $mediaId) {
                DB::table($table)
                    ->where('mediable_type', $morphType)
                    ->where('mediable_id', $this->getKey())
                    ->where('channel', $channel)
                    ->where('media_id', $mediaId)
                    ->update(['order_column' => $index]);
            }
        });

        $this->clearMediaCache($channel);
    }

    protected function getChannelFallbackUrl(?string $channel, string $conversion): string
    {
        $mediaChannel = $this->getMediaChannel($channel ?? Media::DEFAULT_CHANNEL);

        if ($mediaChannel === null) {
            return '';
        }

        return $mediaChannel->getFallbackUrl($conversion !== '' ? $conversion : null);
    }

    protected function getChannelFallbackPath(?string $channel, string $conversion): string
    {
        $mediaChannel = $this->getMediaChannel($channel ?? Media::DEFAULT_CHANNEL);

        if ($mediaChannel === null) {
            return '';
        }

        return $mediaChannel->getFallbackPath($conversion !== '' ? $conversion : null);
    }

    /**
     * Get the next sequential order_column value for a channel.
     */
    protected function getNextOrder(string $channel): int
    {
        $table = config('mediaman.tables.mediables');
        $morphType = $this->getMorphClass();

        $max = DB::table($table)
            ->where('mediable_type', $morphType)
            ->where('mediable_id', $this->getKey())
            ->where('channel', $channel)
            ->max('order_column');

        return $max !== null ? (int) $max + 1 : 0;
    }

    /**
     * Register all the model's media channels.
     */
    public function registerMediaChannels(): void
    {
        //
    }

    /**
     * Detach all media when given bool, null, empty string, or empty array.
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
    public function clearMediaChannel(string $channel = Media::DEFAULT_CHANNEL): void
    {
        $this->media()->wherePivot('channel', $channel)->detach();
        $this->clearMediaCache($channel);
    }

    /**
     * Clear cached media for a channel, or all channels when null. Clearing a
     * single channel also invalidates the all-channels cache (it would include
     * the cleared channel's media and otherwise stay stale).
     */
    protected function clearMediaCache(?string $channel = null): void
    {
        if ($channel === null) {
            $this->mediaCache = [];

            return;
        }

        unset(
            $this->mediaCache[$channel],
            $this->mediaCache[self::ALL_CHANNELS_CACHE_KEY],
        );
    }

    /**
     * Parse the media id's from the mixed input.
     *
     * @param  mixed  $media
     */
    protected function parseMediaIds($media): array
    {
        if ($media instanceof Collection) {
            return $media->modelKeys();
        }

        $mediaModel = $this->mediaModel();
        if ($media instanceof $mediaModel) {
            return [$media->getKey()];
        }

        $ids = (array) $media;

        return array_map(function ($id) {
            if (is_object($id) && method_exists($id, 'getKey')) {
                return $id->getKey();
            }

            return $id;
        }, $ids);
    }

    /**
     * Get the media channel with the specified name.
     */
    public function getMediaChannel(string $name): ?MediaChannel
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
    public function getFirstMediaUrlWithFallback(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): string
    {
        if (! $media = $this->getFirstMedia($channel)) {
            return $this->getChannelFallbackUrl($channel, $conversion);
        }

        return $media->getUrlWithFallback($conversion);
    }

    /**
     * Get conversion URL only if it exists for the first media item.
     */
    public function getFirstMediaConversionUrl(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): ?string
    {
        if (! $media = $this->getFirstMedia($channel)) {
            return null;
        }

        return $media->getConversionUrl($conversion);
    }

    /**
     * Check if the first media item has a specific conversion.
     */
    public function hasMediaConversion(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): bool
    {
        if (! $media = $this->getFirstMedia($channel)) {
            return false;
        }

        return $media->hasConversion($conversion);
    }

    /**
     * Get the absolute path of the first media item, or the channel fallback path.
     */
    public function getFirstMediaPath(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): string
    {
        if (! $media = $this->getFirstMedia($channel)) {
            return $this->getChannelFallbackPath($channel, $conversion);
        }

        return $media->getFullPath($conversion);
    }

    /**
     * Get the last media item in the specified channel.
     */
    public function getLastMedia(?string $channel = Media::DEFAULT_CHANNEL)
    {
        return $this->getMedia($channel)->last();
    }

    /**
     * Get the URL of the last media item with automatic format detection.
     */
    public function getLastMediaUrl(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): string
    {
        if (! $media = $this->getLastMedia($channel)) {
            return $this->getChannelFallbackUrl($channel, $conversion);
        }

        return $media->getUrl($conversion);
    }

    /**
     * Get the URL with fallback for the last media item.
     */
    public function getLastMediaUrlWithFallback(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): string
    {
        if (! $media = $this->getLastMedia($channel)) {
            return $this->getChannelFallbackUrl($channel, $conversion);
        }

        return $media->getUrlWithFallback($conversion);
    }

    /**
     * Get conversion URL only if it exists for the last media item.
     */
    public function getLastMediaConversionUrl(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): ?string
    {
        if (! $media = $this->getLastMedia($channel)) {
            return null;
        }

        return $media->getConversionUrl($conversion);
    }

    /**
     * Check if the last media item has a specific conversion.
     */
    public function hasLastMediaConversion(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): bool
    {
        if (! $media = $this->getLastMedia($channel)) {
            return false;
        }

        return $media->hasConversion($conversion);
    }

    /**
     * Get the absolute path of the last media item, or the channel fallback path.
     */
    public function getLastMediaPath(?string $channel = Media::DEFAULT_CHANNEL, string $conversion = ''): string
    {
        if (! $media = $this->getLastMedia($channel)) {
            return $this->getChannelFallbackPath($channel, $conversion);
        }

        return $media->getFullPath($conversion);
    }

    /**
     * Register a new media channel.
     */
    public function addMediaChannel(string $name): MediaChannel
    {
        $channel = app(MediaChannel::class);

        $this->mediaChannels[$name] = $channel;

        return $channel;
    }
}
