<?php

namespace Emaia\MediaMan\Resolvers;

use DateTimeInterface;
use Emaia\MediaMan\Models\Media;

class DefaultMediaResolver implements MediaResolver
{
    // ─── Paths ──────────────────────────────────────────────────────────

    public function directory(Media $media): string
    {
        return $media->getKey().'-'.md5($media->getKey().config('app.key'));
    }

    public function pathForConversion(Media $media, string $conversion): string
    {
        return $this->directory($media).'/'.Media::CONVERSIONS_DIR.'/'.$conversion;
    }

    public function pathForResponsive(Media $media): string
    {
        return $this->directory($media).'/'.Media::RESPONSIVE_DIR;
    }

    // ─── URLs ──────────────────────────────────────────────────────────

    public function url(Media $media, ?string $conversion = null): string
    {
        $path = $media->getPath($conversion ?? '');
        $url = $media->filesystem()->url($path);

        $url = $this->applyPrefix($url);
        $url = $this->applyVersionQuery($url, $media);

        return $url;
    }

    public function temporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string
    {
        return $media->filesystem()->temporaryUrl(
            $media->getPath($conversion ?? ''),
            $expiration
        );
    }

    // ─── Filenames ─────────────────────────────────────────────────────

    public function baseName(string $originalName): string
    {
        return $this->sanitizeName($originalName);
    }

    public function conversionFileName(string $originalName, string $conversion, string $extension): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        return $baseName.'.'.$extension;
    }

    public function responsiveFileName(string $originalName, int $width, string $format): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);

        return "{$baseName}_{$width}w.{$format}";
    }

    // ─── Internals ─────────────────────────────────────────────────────

    /**
     * Prepend the configured URL prefix. If the storage URL is absolute,
     * its path (and query, if any) is extracted before prefixing — this is
     * what lets `url.prefix` work cleanly for S3-style absolute URLs.
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

    protected function sanitizeName(string $fileName): string
    {
        $fileName = preg_replace('/[\x00\p{C}]/u', '', $fileName);

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        $name = str_replace(
            ['..', '#', '/', '\\', ' ', '?', '%', '*', ':', '|', '"', "'", '<', '>'],
            '-',
            $name
        );

        $name = str_replace('.', '-', $name);

        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        if ($name === '') {
            $name = 'unnamed';
        }

        return $extension !== '' ? "{$name}.{$extension}" : $name;
    }
}
