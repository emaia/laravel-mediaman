<?php

namespace Emaia\MediaMan\Generators;

use DateTimeInterface;
use Emaia\MediaMan\Models\Media;

interface UrlGenerator
{
    /**
     * Build the public URL for a Media item, optionally for a specific conversion.
     */
    public function getUrl(Media $media, ?string $conversion = null): string;

    /**
     * Build a signed temporary URL for cloud disks. Implementations should not
     * apply the `url.prefix` or `version_query` config — temporary URLs are
     * already fully qualified and signed.
     */
    public function getTemporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string;
}
