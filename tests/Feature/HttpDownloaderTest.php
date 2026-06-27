<?php

use Emaia\MediaMan\Downloaders\Downloader;
use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

function makeDestPath(): string
{
    return tempnam(sys_get_temp_dir(), 'mediaman_dl_');
}

it('downloads the body to the sink and returns path/mime/size', function () {
    $body = 'pretend-image-bytes';

    Http::fake([
        'https://example.com/photo.jpg' => function (Request $request) use ($body) {
            return $request->method() === 'HEAD'
                ? Http::response(null, 200)
                : Http::response($body, 200, ['Content-Type' => 'image/jpeg']);
        },
    ]);

    $dest = makeDestPath();
    $result = app(Downloader::class)->download('https://example.com/photo.jpg', $dest);

    expect($result['path'])->toBe($dest)
        ->and($result['mime'])->toBe('image/jpeg')
        ->and($result['size'])->toBe(strlen($body))
        ->and(file_get_contents($dest))->toBe($body);

    @unlink($dest);
});

it('strips a charset attribute from Content-Type', function () {
    Http::fake([
        'https://example.com/page.html' => function (Request $request) {
            return $request->method() === 'HEAD'
                ? Http::response(null, 200)
                : Http::response('<html></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
        },
    ]);

    $dest = makeDestPath();
    $result = app(Downloader::class)->download('https://example.com/page.html', $dest);

    expect($result['mime'])->toBe('text/html');

    @unlink($dest);
});

it('falls back to application/octet-stream when Content-Type is missing', function () {
    Http::fake([
        'https://example.com/blob.bin' => function (Request $request) {
            return $request->method() === 'HEAD'
                ? Http::response(null, 200)
                : Http::response('opaque-bytes', 200);
        },
    ]);

    $dest = makeDestPath();
    $result = app(Downloader::class)->download('https://example.com/blob.bin', $dest);

    expect($result['mime'])->toBe('application/octet-stream');

    @unlink($dest);
});

it('passes a CURLOPT_RESOLVE entry per IP when $resolved is provided', function () {
    Http::fake([
        'https://example.com/pinned.jpg' => function (Request $request) {
            return $request->method() === 'HEAD'
                ? Http::response(null, 200)
                : Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']);
        },
    ]);

    $dest = makeDestPath();
    $resolved = ['host' => 'example.com', 'port' => 443, 'ips' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']];

    $result = app(Downloader::class)->download('https://example.com/pinned.jpg', $dest, $resolved);

    expect($result['size'])->toBe(strlen('bytes'));

    @unlink($dest);
});

it('throws when the response status is not successful and removes the destination file', function () {
    Http::fake([
        'https://example.com/missing.jpg' => function (Request $request) {
            return $request->method() === 'HEAD'
                ? Http::response(null, 200)
                : Http::response('not found', 404);
        },
    ]);

    $dest = makeDestPath();

    try {
        app(Downloader::class)->download('https://example.com/missing.jpg', $dest);
        $this->fail('Expected RuntimeException for non-2xx response.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('404')
            ->and(file_exists($dest))->toBeFalse();
    }
});

it('wraps a ConnectionException raised during the GET as RuntimeException', function () {
    Http::fake([
        'https://example.com/down.jpg' => function (Request $request) {
            if ($request->method() === 'HEAD') {
                return Http::response(null, 200);
            }

            throw new ConnectionException('cURL error 7: Failed to connect');
        },
    ]);

    $dest = makeDestPath();

    try {
        app(Downloader::class)->download('https://example.com/down.jpg', $dest);
        $this->fail('Expected RuntimeException wrapping ConnectionException.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toStartWith('Failed to connect to URL:')
            ->and($e->getMessage())->toContain('cURL error 7')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }

    @unlink($dest);
});

it('rejects oversized files after download via filesize check', function () {
    Config::set('mediaman.url_sources.max_size_bytes', 5);

    Http::fake([
        'https://example.com/big.jpg' => function (Request $request) {
            // HEAD: no Content-Length so the precheck is skipped; size cap only
            // fires after the body lands on disk.
            return $request->method() === 'HEAD'
                ? Http::response(null, 200)
                : Http::response('this body is definitely longer than five bytes', 200, ['Content-Type' => 'image/jpeg']);
        },
    ]);

    $dest = makeDestPath();

    try {
        app(Downloader::class)->download('https://example.com/big.jpg', $dest);
        $this->fail('Expected FileSizeExceeded.');
    } catch (FileSizeExceeded $e) {
        expect(file_exists($dest))->toBeFalse();
    }
});

it('skips the HEAD pre-check when the HEAD response is not successful', function () {
    Http::fake([
        'https://example.com/picky.jpg' => function (Request $request) {
            return $request->method() === 'HEAD'
                ? Http::response(null, 500)
                : Http::response('ok', 200, ['Content-Type' => 'image/jpeg']);
        },
    ]);

    $dest = makeDestPath();
    $result = app(Downloader::class)->download('https://example.com/picky.jpg', $dest);

    expect($result['size'])->toBe(2);

    @unlink($dest);
});

it('skips the HEAD pre-check when Content-Length is absent or empty', function () {
    Http::fake([
        'https://example.com/no-length.jpg' => function (Request $request) {
            return $request->method() === 'HEAD'
                ? Http::response(null, 200)
                : Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']);
        },
    ]);

    $dest = makeDestPath();
    $result = app(Downloader::class)->download('https://example.com/no-length.jpg', $dest);

    expect($result['size'])->toBe(5);

    @unlink($dest);
});

it('swallows a ConnectionException raised during the HEAD pre-check and proceeds to GET', function () {
    Http::fake([
        'https://example.com/head-down.jpg' => function (Request $request) {
            if ($request->method() === 'HEAD') {
                throw new ConnectionException('HEAD timed out');
            }

            return Http::response('ok', 200, ['Content-Type' => 'image/jpeg']);
        },
    ]);

    $dest = makeDestPath();
    $result = app(Downloader::class)->download('https://example.com/head-down.jpg', $dest);

    expect($result['size'])->toBe(2)
        ->and($result['mime'])->toBe('image/jpeg');

    @unlink($dest);
});
