# Recipes

[← Back to README](../README.md)

- [Image optimization](#image-optimization)
- [PDF first-page thumbnail](#pdf-first-page-thumbnail)
- [Video thumbnail](#video-thumbnail)
- [SVG to PNG fallback](#svg-to-png-fallback)
- [ZIP download of multiple media](#zip-download-of-multiple-media)
- [Multi-file form upload](#multi-file-form-upload)
- [Best-effort (partial) attach](#best-effort-partial-attach)
- [String or stream upload](#string-or-stream-upload)

Common needs that MediaMan deliberately doesn't ship out-of-the-box — here's how to bolt them onto the existing event/queue/custom-properties flow.

Most recipes follow the same shape:

1. Listen to a MediaMan event (`MediaUploaded`, `ConversionCompleted`, `ResponsiveImagesGenerated`).
2. Dispatch a queued job that runs a third-party library.
3. Write any generated files into the Media's directory via `PathGenerator` (so paths remain pluggable).
4. Persist metadata in `custom_properties`.

---

## Image optimization

**Use case:** Run jpegoptim / pngquant / cwebp / avifenc against uploaded images and their conversions to shrink file sizes without changing the pipeline.

**Approach:** Pair the package with [`spatie/image-optimizer`](https://github.com/spatie/image-optimizer), which wraps the CLI tools. Trigger it from `MediaUploaded` (originals) and `ConversionCompleted` (variants).

```bash
composer require spatie/image-optimizer
# install the underlying binaries on your servers (jpegoptim, optipng, pngquant, cwebp, avifenc)
```

```php
use Emaia\MediaMan\Events\ConversionCompleted;
use Emaia\MediaMan\Events\MediaUploaded;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class OptimizeUploadedImage implements ShouldQueue
{
    use Queueable;

    public function handleMediaUploaded(MediaUploaded $event): void
    {
        $media = $event->media;

        if (! $media->isOfType('image')) {
            return;
        }

        $this->optimize($media, $media->getPath());
    }

    public function handleConversionCompleted(ConversionCompleted $event): void
    {
        $media = $event->media;

        foreach ($event->conversions as $conversion) {
            $this->optimize($media, $media->getPath($conversion));
        }
    }

    private function optimize($media, string $path): void
    {
        $disk = $media->filesystem();

        // Pull to tmp, optimize in place, push back.
        $tmp = tempnam(sys_get_temp_dir(), 'opt_');
        file_put_contents($tmp, $disk->get($path));

        OptimizerChainFactory::create()->optimize($tmp);

        $disk->put($path, file_get_contents($tmp));
        @unlink($tmp);
    }
}
```

Register both methods in `EventServiceProvider`:

```php
protected $listen = [
    MediaUploaded::class       => [[OptimizeUploadedImage::class, 'handleMediaUploaded']],
    ConversionCompleted::class => [[OptimizeUploadedImage::class, 'handleConversionCompleted']],
];
```

**Trade-offs:** Requires CLI binaries on each server (or in your container image). Cloud disks pay one read + write per file. For massive batches, schedule with rate-limiting.

---

## PDF first-page thumbnail

**Use case:** Show a preview image for uploaded PDFs.

**Approach:** Listen to `MediaUploaded`, render page 1 with `spatie/pdf-to-image` (Imagick + Ghostscript), write the result as a sibling file under the Media's directory, and reference it via `custom_properties`.

```bash
composer require spatie/pdf-to-image
# Ghostscript must be installed on the server.
```

```php
use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Generators\PathGenerator;
use Spatie\PdfToImage\Pdf;

class GeneratePdfThumbnail implements ShouldQueue
{
    public function handle(MediaUploaded $event): void
    {
        $media = $event->media;

        if ($media->mime_type !== 'application/pdf') {
            return;
        }

        $disk = $media->filesystem();
        $tmpPdf = tempnam(sys_get_temp_dir(), 'pdf_').'.pdf';
        $tmpJpg = tempnam(sys_get_temp_dir(), 'pdf_').'.jpg';

        file_put_contents($tmpPdf, $disk->get($media->getPath()));

        (new Pdf($tmpPdf))->saveImage($tmpJpg);

        $thumbPath = app(PathGenerator::class)->getDirectory($media).'/preview.jpg';
        $disk->put($thumbPath, file_get_contents($tmpJpg));

        $media->setCustomProperty('preview_path', $thumbPath);
        $media->save();

        @unlink($tmpPdf);
        @unlink($tmpJpg);
    }
}
```

Render:

```php
$previewUrl = $media->filesystem()->url($media->getCustomProperty('preview_path'));
```

**Trade-offs:** Ghostscript + Imagick must be installed. For multi-page PDFs you may want to expose page selection or generate a stitched contact-sheet — start by handling page 1.

---

## Video thumbnail

**Use case:** Show a still image for uploaded videos.

**Approach:** Use [`pbmedia/laravel-ffmpeg`](https://github.com/protonemedia/laravel-ffmpeg) to grab a frame, written under the Media directory in the same pattern as the PDF recipe.

```bash
composer require pbmedia/laravel-ffmpeg
# ffmpeg must be installed.
```

```php
use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Generators\PathGenerator;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class GenerateVideoThumbnail implements ShouldQueue
{
    public function handle(MediaUploaded $event): void
    {
        $media = $event->media;

        if (! str_starts_with($media->mime_type, 'video/')) {
            return;
        }

        $disk = $media->filesystem();
        $thumbPath = app(PathGenerator::class)->getDirectory($media).'/poster.jpg';

        // Read on the source disk, write the frame on the same disk.
        FFMpeg::fromDisk($media->disk)
            ->open($media->getPath())
            ->getFrameFromSeconds(1)
            ->export()
            ->toDisk($media->disk)
            ->save($thumbPath);

        $media->setCustomProperty('poster_path', $thumbPath);
        $media->save();
    }
}
```

**Trade-offs:** ffmpeg must be available on every worker. For long videos, dispatch a separate job with a longer queue timeout; the frame-extraction time grows with seek position.

---

## SVG to PNG fallback

**Use case:** Convert SVG uploads to PNG so the rest of the conversion pipeline can resize them.

**Approach:** In a `MediaUploaded` listener, rasterize the SVG with Imagick, store the PNG, and update `file_name` / `mime_type` on the original Media.

```php
use Emaia\MediaMan\Events\MediaUploaded;
use Imagick;

class RasterizeSvg implements ShouldQueue
{
    public function handle(MediaUploaded $event): void
    {
        $media = $event->media;

        if ($media->mime_type !== 'image/svg+xml') {
            return;
        }

        $disk = $media->filesystem();
        $svg = $disk->get($media->getPath());

        $imagick = new Imagick();
        $imagick->setResolution(150, 150);
        $imagick->readImageBlob($svg);
        $imagick->setImageFormat('png');

        // Save as a sibling, then update the Media to point at it.
        $newName = pathinfo($media->file_name, PATHINFO_FILENAME).'.png';
        $disk->put($media->getDirectory().'/'.$newName, $imagick->getImageBlob());

        $imagick->clear();

        $media->file_name = $newName;          // triggers automatic rename on disk
        $media->mime_type = 'image/png';
        $media->save();
    }
}
```

**Trade-offs:** Imagick must be installed. Be careful with untrusted SVGs — they can execute arbitrary network requests via `<image href="...">` during rasterization. Sanitize or sandbox in production.

---

## ZIP download of multiple media

**Use case:** Let a user download a selection of media as a single archive.

**Approach:** Stream the ZIP directly from a controller using [`maennchen/zipstream-php`](https://github.com/maennchen/ZipStream-PHP) — no temp file, scales to large archives.

```bash
composer require maennchen/zipstream-php
```

```php
use Emaia\MediaMan\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

Route::get('/media/zip', function () {
    $ids = request('ids', []);                   // ?ids[]=1&ids[]=2&...
    $media = Media::whereIn('id', $ids)->get();

    return new StreamedResponse(function () use ($media) {
        $zip = new ZipStream(outputName: 'media.zip');

        foreach ($media as $item) {
            $zip->addFileFromStream($item->file_name, $item->getStream());
        }

        $zip->finish();
    }, headers: ['Content-Type' => 'application/zip']);
});
```

**Trade-offs:** Long-running downloads tie up a PHP worker. For 10+ GB archives, consider a queued S3 multipart upload + pre-signed URL instead.

---

## Multi-file form upload

**Use case:** Handle `<input type="file" name="photos[]" multiple>` cleanly.

**Approach:** Iterate `$request->file()` and call `source()` for each upload. Wrap in a transaction if you want all-or-nothing behavior.

```php
use Emaia\MediaMan\MediaUploader;
use Illuminate\Support\Facades\DB;

Route::post('/photos', function () {
    $files = collect(request()->file('photos', []));

    $media = DB::transaction(fn () => $files
        ->map(fn ($file) => MediaUploader::source($file)
            ->useCollection('Gallery')
            ->upload())
        ->all());

    return ['attached' => count($media)];
});
```

For attach to a model with a starting order:

```php
$post = Post::find(1);

$startOrder = $post->getMedia('gallery')->count();

foreach ($files as $i => $file) {
    $media = MediaUploader::source($file)->upload();
    $post->attachMedia($media, 'gallery', [], $startOrder + $i);
}
```

**Trade-offs:** No special API for multi-file — but the iteration is one line, and you keep full control over per-file fluent options (different collections, custom properties, etc.).

---

## Best-effort (partial) attach

**Use case:** Bulk ingestion (imports, seeders) into a channel with aggregate `acceptsFile` rules, where you want to keep every item that fits and skip the rejected ones — instead of the default all-or-nothing batch.

**Approach:** `attachMedia()` is atomic per call, so make each item its own call and catch the rejection. Atomic is the primitive; partial is one loop on top.

```php
use Emaia\MediaMan\Exceptions\MediaNotAcceptedByChannel;

$attached = $skipped = [];

foreach ($mediaIds as $id) {
    try {
        $product->attachMedia($id, 'gallery');
        $attached[] = $id;
    } catch (MediaNotAcceptedByChannel $e) {
        $skipped[] = ['id' => $e->mediaId, 'rule' => $e->rule];
    }
}
```

**Trade-offs:** One `attachMedia` call (and, for aggregate channels, one short transaction + row lock) per item instead of one for the whole batch — fine for ingestion, and you get a precise per-item report. For all-or-nothing interactive flows, pass the whole array to a single `attachMedia([...])` and let the batch roll back as a unit.

---

## String or stream upload

**Use case:** Persist programmatically generated data (a CSV string, a base64 payload from a job result, a stream from another API) as a Media without an `UploadedFile` instance.

**Approach:** Write to a temp file, then funnel through `fromDisk()`.

```php
use Emaia\MediaMan\MediaUploader;
use Illuminate\Support\Facades\Storage;

// From a string
function uploadFromString(string $content, string $filename): \Emaia\MediaMan\Models\Media
{
    Storage::disk('local')->put('tmp/'.$filename, $content);

    $media = MediaUploader::fromDisk('tmp/'.$filename, 'local')
        ->useFileName($filename)
        ->upload();

    Storage::disk('local')->delete('tmp/'.$filename);

    return $media;
}

// From a stream
function uploadFromStream($stream, string $filename): \Emaia\MediaMan\Models\Media
{
    Storage::disk('local')->writeStream('tmp/'.$filename, $stream);

    $media = MediaUploader::fromDisk('tmp/'.$filename, 'local')
        ->useFileName($filename)
        ->upload();

    Storage::disk('local')->delete('tmp/'.$filename);

    return $media;
}
```

**Trade-offs:** Two extra disk operations vs. a hypothetical native API. For small payloads the overhead is negligible; for huge streams, look at chunked uploads via `Storage::disk()->writeStream()` directly into a custom `MediaUploader` subclass.
