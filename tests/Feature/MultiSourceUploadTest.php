<?php

use Emaia\MediaMan\Downloaders\Downloader;
use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Emaia\MediaMan\Exceptions\InvalidBase64Data;
use Emaia\MediaMan\Exceptions\UrlNotAllowed;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fakeTmpFile(string $content = 'hello world'): string
{
    $path = tempnam(sys_get_temp_dir(), 'mediaman_test_');
    file_put_contents($path, $content);

    return $path;
}

beforeEach(function () {
    // Most tests in this file mock the Downloader and return metadata, but the
    // actual file UploadedFile is built from is the empty `tempnam()` path that
    // `fromUrl()` pre-created. Disable the min_file_size guard so the orchestration
    // tests keep validating what they care about (mock contract, not real bytes).
    config()->set('mediaman.min_file_size', 0);
});

// --- fromRequest ---

it('fromRequest creates a Media from the default request field', function () {
    $request = Request::create('/upload', 'POST', [], [], [
        'file' => UploadedFile::fake()->image('photo.jpg'),
    ]);

    $media = MediaUploader::fromRequest('file', $request)->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->toEndWith('.jpg');
});

it('fromRequest accepts a custom field name', function () {
    $request = Request::create('/upload', 'POST', [], [], [
        'avatar' => UploadedFile::fake()->image('avatar.png'),
    ]);

    $media = MediaUploader::fromRequest('avatar', $request)->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('fromRequest resolves the current request from the container when none is passed', function () {
    $request = Request::create('/upload', 'POST', [], [], [
        'file' => UploadedFile::fake()->image('photo.jpg'),
    ]);

    app()->instance('request', $request);

    $media = MediaUploader::fromRequest()->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('fromRequest throws when the field is missing', function () {
    $request = Request::create('/upload', 'POST');

    MediaUploader::fromRequest('file', $request);
})->throws(InvalidArgumentException::class, 'No uploaded file');

it('fromRequest throws when the field is an array of files', function () {
    $request = Request::create('/upload', 'POST', [], [], [
        'photos' => [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ],
    ]);

    MediaUploader::fromRequest('photos', $request);
})->throws(InvalidArgumentException::class);

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

// --- fromString ---

it('fromString uploads raw bytes under the given file name', function () {
    $media = MediaUploader::fromString('hello world', 'note.txt')->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->toBe('note.txt')
        ->and($media->mime_type)->toBe('text/plain')
        ->and($media->filesystem()->get($media->getPath()))->toBe('hello world');
});

it('fromString sniffs MIME from content, not from the extension', function () {
    // A 1×1 transparent PNG — sniffed from magic bytes regardless of the name we pass
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

    $media = MediaUploader::fromString($pngBytes, 'mystery.bin')->upload();

    expect($media->mime_type)->toBe('image/png');
});

it('fromString accepts a custom display name', function () {
    $media = MediaUploader::fromString('hi', 'note.txt', 'My Custom Name')->upload();

    expect($media->name)->toBe('My Custom Name');
});

// --- fromStream ---

it('fromStream uploads from an open resource handle', function () {
    $tmp = fakeTmpFile('streamed content');
    $stream = fopen($tmp, 'rb');

    try {
        $media = MediaUploader::fromStream($stream, 'streamed.txt')->upload();

        expect($media->file_name)->toBe('streamed.txt')
            ->and($media->mime_type)->toBe('text/plain')
            ->and($media->filesystem()->get($media->getPath()))->toBe('streamed content');
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
        @unlink($tmp);
    }
});

it('fromStream does not close the caller-owned stream', function () {
    $tmp = fakeTmpFile('streamed');
    $stream = fopen($tmp, 'rb');

    MediaUploader::fromStream($stream, 'streamed.txt')->upload();

    expect(is_resource($stream))->toBeTrue();

    fclose($stream);
    @unlink($tmp);
});

it('fromStream accepts a custom display name', function () {
    $tmp = fakeTmpFile('hi');
    $stream = fopen($tmp, 'rb');

    try {
        $media = MediaUploader::fromStream($stream, 'streamed.txt', 'Streamed Title')->upload();

        expect($media->name)->toBe('Streamed Title');
    } finally {
        fclose($stream);
        @unlink($tmp);
    }
});

it('fromStream throws when input is not a resource', function () {
    MediaUploader::fromStream('not-a-resource', 'foo.txt');
})->throws(InvalidArgumentException::class, 'expects a PHP stream resource');
