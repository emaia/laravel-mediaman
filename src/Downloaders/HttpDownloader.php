<?php

namespace Emaia\MediaMan\Downloaders;

use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpDownloader implements Downloader
{
    public function download(string $url, string $destinationPath, ?array $resolved = null): array
    {
        $timeout = (int) config('mediaman.url_sources.timeout_seconds', 30);
        $maxSize = (int) config('mediaman.url_sources.max_size_bytes', 100 * 1024 * 1024);
        $verifySsl = config('mediaman.url_sources.verify_ssl', true);
        $userAgent = config('mediaman.url_sources.user_agent', 'MediaMan/2.x');

        $this->checkContentLength($url, $maxSize, $timeout, $verifySsl, $userAgent);

        $curlOptions = [];

        if ($resolved !== null && ! empty($resolved['ips'])) {
            $curlOptions[\CURLOPT_RESOLVE] = array_map(
                fn (string $ip): string => "{$resolved['host']}:{$resolved['port']}:$ip",
                $resolved['ips']
            );
        }

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $verifySsl])
                ->withHeaders(['User-Agent' => $userAgent])
                ->withOptions([
                    'curl' => $curlOptions,
                    'sink' => $destinationPath,
                    'progress' => function (int $downloadTotal, int $downloadedBytes) use ($maxSize) {
                        if ($downloadedBytes > $maxSize) {
                            throw new RuntimeException('Download exceeds maximum allowed size.');
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Failed to connect to URL: {$e->getMessage()}", 0, $e);
        }

        if (! $response->successful()) {
            @unlink($destinationPath);

            throw new RuntimeException("Download failed with HTTP status {$response->status()}.");
        }

        /** @var string $mime */
        $mime = $response->header('Content-Type') ?: 'application/octet-stream';
        $mime = strtok($mime, ';') ?: 'application/octet-stream';

        $size = filesize($destinationPath);

        if ($size > $maxSize) {
            @unlink($destinationPath);

            throw FileSizeExceeded::forSize($size, $maxSize);
        }

        return [
            'path' => $destinationPath,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    private function checkContentLength(
        string $url,
        int $maxSize,
        int $timeout,
        bool $verifySsl,
        string $userAgent,
    ): void {
        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $verifySsl])
                ->withHeaders(['User-Agent' => $userAgent])
                ->head($url);

            if (! $response->successful()) {
                return;
            }

            /** @var string|null $contentLength */
            $contentLength = $response->header('Content-Length');

            if ($contentLength === null || $contentLength === '') {
                return;
            }

            if ((int) $contentLength > $maxSize) {
                throw FileSizeExceeded::forSize((int) $contentLength, $maxSize);
            }
        } catch (ConnectionException) {
            // HEAD request failed, proceed to download anyway
        }
    }
}
