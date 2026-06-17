<?php

namespace Emaia\MediaMan\Generators;

use DateTimeInterface;
use Emaia\MediaMan\Models\Media;

class DefaultUrlGenerator implements UrlGenerator
{
    public function getUrl(Media $media, ?string $conversion = null): string
    {
        $path = $media->getPath($conversion ?? '');
        $url = $media->filesystem()->url($path);

        $url = $this->applyPrefix($url);
        $url = $this->applyVersionQuery($url, $media);

        return $url;
    }

    public function getTemporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string
    {
        return $media->filesystem()->temporaryUrl(
            $media->getPath($conversion ?? ''),
            $expiration
        );
    }

    /**
     * Prepend the configured URL prefix. If the storage URL is absolute,
     * its path (and query, if any) is extracted before prefixing.
     */
    protected function applyPrefix(string $url): string
    {
        $prefix = config('mediaman.url.prefix');

        if ($prefix === null) {
            return $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);
            $url = $parts['path'] ?? '/';

            if (isset($parts['query'])) {
                $url .= '?'.$parts['query'];
            }
        }

        return rtrim($prefix, '/').'/'.ltrim($url, '/');
    }

    protected function applyVersionQuery(string $url, Media $media): string
    {
        if (! config('mediaman.url.version_query', false)) {
            return $url;
        }

        $updatedAt = $media->getAttribute($media->getUpdatedAtColumn());

        if (! $updatedAt instanceof DateTimeInterface) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.$updatedAt->getTimestamp();
    }
}
