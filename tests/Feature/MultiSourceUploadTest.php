<?php

use Emaia\MediaMan\Downloaders\Downloader;
use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Emaia\MediaMan\Exceptions\InvalidBase64Data;
use Emaia\MediaMan\Exceptions\UrlNotAllowed;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fakeTmpFile(string $content = 'hello world'): string
{
    $path = tempnam(sys_get_temp_dir(), 'mediaman_test_');
    file_put_contents($path, $content);

    return $path;
}

// --- fromDisk ---

it('can upload from a file on another disk', function () {
    Storage::fake('source-disk');
    Storage::disk('source-disk')->put('photos/image.jpg', 'fake image content');

    $media = MediaUploader::fromDisk('photos/image.jpg', 'source-disk')->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->toEqual('image.jpg')
        ->and($media->disk)->toEqual(self::DEFAULT_DISK);

    expect(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath()))->toBeTrue();
});

it('fromDisk preserves the source file on the original disk', function () {
    Storage::fake('source-disk');
    Storage::disk('source-disk')->put('photos/image.jpg', 'original content');

    MediaUploader::fromDisk('photos/image.jpg', 'source-disk')->upload();

    expect(Storage::disk('source-disk')->exists('photos/image.jpg'))->toBeTrue();
});

it('fromDisk throws when the file does not exist on the source disk', function () {
    Storage::fake('source-disk');

    MediaUploader::fromDisk('missing/file.jpg', 'source-disk');
})->throws(RuntimeException::class, 'not found');

// --- fromBase64 ---

it('can upload from a base64 string', function () {
    $b64 = base64_encode('hello world');

    $media = MediaUploader::fromBase64($b64, 'hello.txt')->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->toEndWith('.txt');
});

it('can upload from a data URI', function () {
    $dataUri = 'data:text/plain;base64,'.base64_encode('hello world');

    $media = MediaUploader::fromBase64($dataUri, 'hello.txt')->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('can set a custom name from base64', function () {
    $b64 = base64_encode('hello');

    $media = MediaUploader::fromBase64($b64, 'hello.txt', 'My Custom Name')->upload();

    expect($media->name)->toEqual('My Custom Name');
});

it('rejects base64 when payload exceeds max_size_bytes', function () {
    Config::set('mediaman.base64.max_size_bytes', 100);

    $bigData = str_repeat('A', 200);

    MediaUploader::fromBase64($bigData, 'big.txt');
})->throws(FileSizeExceeded::class);

it('allows base64 within size limit', function () {
    Config::set('mediaman.base64.max_size_bytes', 50 * 1024 * 1024);

    $b64 = base64_encode('hello');

    $media = MediaUploader::fromBase64($b64, 'hello.txt')->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('rejects invalid base64 data', function () {
    MediaUploader::fromBase64('not-valid-base64!!!', 'file.txt');
})->throws(InvalidBase64Data::class, 'Invalid base64');

it('rejects data URI with no comma', function () {
    MediaUploader::fromBase64('data:image/png;base64', 'file.png');
})->throws(InvalidBase64Data::class, 'Invalid data URI');

// --- fromUrl ---

it('validates URLs through UrlGuard', function () {
    MediaUploader::fromUrl('http://127.0.0.1/file.jpg');
})->throws(UrlNotAllowed::class);

it('downloads from a URL via the Downloader', function () {
    $tmpPath = fakeTmpFile('fake image content');

    $downloader = Mockery::mock(Downloader::class);
    $downloader->shouldReceive('download')
        ->once()
        ->with('https://example.com/photo.jpg', Mockery::any(), Mockery::any())
        ->andReturn([
            'path' => $tmpPath,
            'mime' => 'image/jpeg',
            'size' => strlen('fake image content'),
        ]);

    app()->instance(Downloader::class, $downloader);

    $media = MediaUploader::fromUrl('https://example.com/photo.jpg')->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->toEqual('photo.jpg');
});

it('preserves URL filename when downloading', function () {
    $tmpPath = fakeTmpFile('pdf content');

    $downloader = Mockery::mock(Downloader::class);
    $downloader->shouldReceive('download')
        ->andReturn([
            'path' => $tmpPath,
            'mime' => 'application/pdf',
            'size' => 11,
        ]);

    app()->instance(Downloader::class, $downloader);

    $media = MediaUploader::fromUrl('https://example.com/assets/report.pdf')->upload();

    expect($media->file_name)->toEqual('report.pdf');
});

it('derives extension from MIME when URL path is a bare domain', function () {
    $tmpPath = fakeTmpFile('png content');

    $downloader = Mockery::mock(Downloader::class);
    $downloader->shouldReceive('download')
        ->andReturn([
            'path' => $tmpPath,
            'mime' => 'image/png',
            'size' => 11,
        ]);

    app()->instance(Downloader::class, $downloader);

    $media = MediaUploader::fromUrl('https://example.com/')->upload();

    expect($media->file_name)->toEqual('download.png');
});

it('passes resolved host/port/ips to the downloader', function () {
    $tmpPath = fakeTmpFile('jpg content');

    $downloader = Mockery::mock(Downloader::class);
    $downloader->shouldReceive('download')
        ->once()
        ->withArgs(function (string $url, string $path, ?array $resolved) {
            return $resolved !== null
                && $resolved['host'] === 'example.com'
                && $resolved['port'] === 443
                && is_array($resolved['ips']);
        })
        ->andReturn(['path' => $tmpPath, 'mime' => 'image/jpeg', 'size' => 11]);

    app()->instance(Downloader::class, $downloader);

    MediaUploader::fromUrl('https://example.com/photo.jpg')->upload();
});

it('lowercases the URL host before passing to the downloader', function () {
    $tmpPath = fakeTmpFile('jpg content');

    $downloader = Mockery::mock(Downloader::class);
    $downloader->shouldReceive('download')
        ->once()
        ->withArgs(function (string $url, string $path, ?array $resolved) {
            return str_contains($url, 'example.com')
                && ! str_contains($url, 'EXAMPLE.COM')
                && $resolved['host'] === 'example.com';
        })
        ->andReturn(['path' => $tmpPath, 'mime' => 'image/jpeg', 'size' => 11]);

    app()->instance(Downloader::class, $downloader);

    MediaUploader::fromUrl('https://EXAMPLE.COM/photo.jpg')->upload();
});

it('rejects oversized files via HEAD Content-Length pre-check', function () {
    // Artificially small limit so the HEAD Content-Length triggers
    Config::set('mediaman.url_sources.max_size_bytes', 100);

    Http::fake([
        'https://example.com/huge.zip' => Http::response(null, 200, [
            'Content-Length' => '999999999',
        ]),
    ]);

    $downloader = app(Downloader::class);

    $downloader->download('https://example.com/huge.zip', fakeTmpFile());
})->throws(FileSizeExceeded::class);
