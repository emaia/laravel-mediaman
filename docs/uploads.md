# Uploads

[← Back to README](../README.md)

- [From an UploadedFile](#from-an-uploadedfile)
- [From an HTTP request](#from-an-http-request)
- [Fluent options](#fluent-options)
- [From a Laravel disk](#from-a-laravel-disk)
- [From base64 or a data URI](#from-base64-or-a-data-uri)
- [From a remote URL](#from-a-remote-url)
- [Custom downloader](#custom-downloader)
- [Generate responsive variants on upload](#generate-responsive-variants-on-upload)

`Emaia\MediaMan\MediaUploader` is the fluent entry point for **creating** Media records. It writes the file to disk, creates a database row, and optionally attaches to a collection and generates responsive variants — all in one call.

For everything you do with a Media **after** it exists (retrieve, update, delete, HTTP responses, mail, copy, custom properties), see [Media](media.md).

## From an UploadedFile

```php
$media = MediaUploader::source($request->file('file'))->upload();
```

The file goes to the default disk and is bundled in the default collection (both configurable in `config/mediaman.php`). The filename is sanitized automatically.

## From an HTTP request

A thin convenience wrapper for the most common case — pulling a single file off the current request:

```php
$media = MediaUploader::fromRequest()->upload();              // default field 'file'
$media = MediaUploader::fromRequest('avatar')->upload();      // custom field
$media = MediaUploader::fromRequest('avatar', $request)->upload(); // explicit Request (tests, jobs)
```

When no `Request` is passed, the current HTTP request is resolved from the container.

Throws `InvalidArgumentException` when the field is missing, empty, or contains an array. For multi-file inputs (`<input name="photos[]" multiple>`), iterate manually with `source()`:

```php
foreach ($request->file('photos') as $file) {
    MediaUploader::source($file)->useCollection('Gallery')->upload();
}
```

## Fluent options

```php
$media = MediaUploader::source($request->file('file'))
    ->useName('Custom display name')
    ->useFileName('custom-name.png')
    ->useCollection('Images')              // created on the fly if missing
    ->useDisk('media')
    ->withCustomProperties([
        'caption' => 'A picture',
        'author'  => 'Alice',
    ])
    ->allowMimeTypes(['image/jpeg', 'image/png'])
    ->maxFileSize(5 * 1024 * 1024)
    ->upload();
```

**Conflict-safe file naming**: each Media is stored in a directory named `{id}-{md5(id.app_key)}`. Two uploads with the same filename get separate directories, and the hash makes URLs unguessable.

> MediaMan treats any `Illuminate\Http\UploadedFile` as a media source. Validate file types in your form requests if you need stricter input rules.

## From a Laravel disk

```php
$media = MediaUploader::fromDisk('uploads/photo.jpg', 'public')->upload();
```

The source file stays on the origin disk; MediaMan copies it into the target disk through the standard upload pipeline. All fluent options still apply:

```php
$media = MediaUploader::fromDisk('imports/legacy.png', 'staging')
    ->useCollection('Imports')
    ->useDisk('media')
    ->upload();
```

## From base64 or a data URI

```php
// Raw base64
$media = MediaUploader::fromBase64(base64_encode($bytes), 'photo.jpg')->upload();

// Data URI (browser canvas, file API)
$media = MediaUploader::fromBase64('data:image/png;base64,iVBORw0...', 'photo.png')->upload();

// Optional custom display name (third argument)
$media = MediaUploader::fromBase64($data, 'photo.png', 'User Avatar')->upload();
```

Payload size is checked **before** decoding to prevent memory exhaustion (`config('mediaman.base64.max_size_bytes')`, default 50 MB). Oversized payloads throw `FileSizeExceeded`; malformed data throws `InvalidBase64Data`.

## From raw bytes (in-memory content)

```php
// Programmatic content (PDF generator, headless screenshot, webhook body)
$media = MediaUploader::fromString($pdfBytes, 'invoice.pdf')->upload();

// Optional custom display name (third argument)
$media = MediaUploader::fromString($bytes, 'invoice.pdf', 'August Invoice')->upload();
```

MIME type is sniffed from content (via `finfo`), so the `$fileName` extension doesn't have to be accurate. The package-level `max_file_size` validation still applies during upload.

## From a PHP stream

```php
$stream = fopen('php://temp', 'r+b');
fwrite($stream, $generatedBytes);
rewind($stream);

$media = MediaUploader::fromStream($stream, 'report.xlsx')->upload();

fclose($stream);   // caller owns the stream
```

Useful for SFTP wrappers, PSR-7 `StreamInterface::detach()`, or content piped from another process. `fromStream()` reads through the stream into a temp file but does **not** close it — the caller stays responsible for `fclose()`.

## From a remote URL

```php
$media = MediaUploader::fromUrl('https://example.com/photo.jpg')->upload();
```

`fromUrl()` requires **ext-curl**. The URL passes through SSRF validation (`UrlGuard`) before any HTTP request leaves your server — see [Security → SSRF protection](security.md#ssrf-protection-for-remote-urls). The resolved IPs are pinned via `CURLOPT_RESOLVE` so the download targets exactly what the guard validated.

Download safety runs in layers:

1. **Scheme + host validation** — only `http`/`https`; private/loopback/metadata IPs rejected
2. **HEAD `Content-Length` pre-check** — oversized files rejected before bytes are downloaded
3. **In-stream size guard** — download aborts mid-stream if `max_size_bytes` is exceeded
4. **Post-download filesize verification**

Configure timeouts, size limits, SSL verification, and User-Agent via `config('mediaman.url_sources')` (see [Configuration → URL sources](configuration.md#url-sources-for-fromurl)).

### Custom downloader

`fromUrl` dispatches through `Emaia\MediaMan\Downloaders\Downloader`, bound by default to `HttpDownloader` (Laravel HTTP client). Swap it for custom HTTP stacks, retries, or instrumentation:

```php
use Emaia\MediaMan\Downloaders\Downloader;

$this->app->bind(Downloader::class, MyCustomDownloader::class);
```

In tests, mock the interface:

```php
$mock = Mockery::mock(Downloader::class);
$mock->shouldReceive('download')->andReturn([
    'path' => $tmpPath,
    'mime' => 'image/jpeg',
    'size' => 12345,
]);

app()->instance(Downloader::class, $mock);
```

## Generate responsive variants on upload

Opt in per upload:

```php
$media = MediaUploader::source($request->file('file'))
    ->generateResponsive()
    ->withBreakpoints([480, 768, 1280])
    ->withFormats(['webp', 'avif'])
    ->withQuality(90)
    ->upload();
```

Or globally via `config('mediaman.responsive_images.auto_generate')`. See [Responsive Images](responsive-images.md).
