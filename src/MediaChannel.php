<?php

namespace Emaia\MediaMan;

class MediaChannel
{
    /** @var array */
    protected array $conversions = [];

    /**
     * Register the conversions to be performed when media is attached.
     *
     * @param  string  ...$conversions
     * @return $this
     */
    public function performConversions(...$conversions): MediaChannel
    {
        $this->conversions = $conversions;

        return $this;
    }

    /**
     * Determine if there are any registered conversions.
     *
     * @return bool
     */
    public function hasConversions(): bool
    {
        return ! empty($this->conversions);
    }

    /**
     * Get all the registered conversions.
     *
     * @return array
     */
    public function getConversions(): array
    {
        return $this->conversions;
    }
}
