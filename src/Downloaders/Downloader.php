<?php

namespace Emaia\MediaMan\Downloaders;

interface Downloader
{
    /**
     * Download a file from a URL to a local path.
     *
     * @param  array{host: string, port: int, ips: string[]}|null  $resolved
     * @return array{path: string, mime: string, size: int}
     */
    public function download(string $url, string $destinationPath, ?array $resolved = null): array;
}
