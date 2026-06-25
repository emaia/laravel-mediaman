<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Downloaders\Downloader;
use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Exceptions\DisallowedExtension;
use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Emaia\MediaMan\Exceptions\InvalidBase64Data;
use Emaia\MediaMan\Exceptions\MediaFileWriteFailed;
use Emaia\MediaMan\Exceptions\MimeTypeNotAllowed;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Placeholders\PlaceholderGenerator;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\Support\UrlGuard;
use Emaia\MediaMan\Traits\ResolvesModels;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Symfony\Component\Mime\MimeTypes;

class MediaUploader
{
    use ResolvesModels;

    protected UploadedFile $file;

    protected ?string $name = null;

    /** @var string[] */
    protected array $collections = [];

    protected ?string $fileName = null;

    protected ?string $disk = null;

    protected array $custom_properties = [];

    protected ?array $allowedMimeTypes = null;

    protected ?int $maxFileSize = null;

    protected bool $generateResponsive = false;

    protected array $responsiveOptions = [];

    public function __construct(UploadedFile $file)
    {
        $this->setFile($file);
    }

    public static function source(UploadedFile $file): MediaUploader
    {
        return new self($file);
    }

    /**
     * Create an uploader from a file present on the current HTTP request.
     * The request is resolved from the container when not provided.
     *
     * @throws \InvalidArgumentException when the request field is missing,
     *                                   empty, or contains an array (multi-file inputs are not supported here).
     */
    public static function fromRequest(string $key = 'file', ?Request $request = null): MediaUploader
    {
        $request ??= app(Request::class);
        $file = $request->file($key);

        if (! $file instanceof UploadedFile) {
            throw new \InvalidArgumentException(
                "No uploaded file in request field [$key]."
            );
        }

        return new self($file);
    }

    /**
     * Create an uploader from a file on an existing filesystem disk.
     *
     * TODO: use readStream/writeStream to avoid loading the entire file
     * into memory at once (relevant for very large files on cloud disks).
     */
    public static function fromDisk(string $path, string $disk): MediaUploader
    {
        $filesystem = Storage::disk($disk);

        if (! $filesystem->exists($path)) {
            throw new \RuntimeException("File [$path] not found on disk [$disk].");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'mediaman_');

        try {
            file_put_contents($tmpPath, $filesystem->get($path));

            $uploadedFile = new UploadedFile(
                $tmpPath,
                basename($path),
                $filesystem->mimeType($path),
                null,
                true
            );

            return new self($uploadedFile);
        } catch (\Throwable $e) {
            @unlink($tmpPath);

            throw $e;
        }
    }

    /**
     * Create an uploader from a base64-encoded string.
     */
    public static function fromBase64(string $data, string $filename, ?string $name = null): MediaUploader
    {
        $maxBytes = (int) config('mediaman.base64.max_size_bytes', 50 * 1024 * 1024);

        if ($maxBytes > 0 && strlen($data) > $maxBytes) {
            throw FileSizeExceeded::forSize(strlen($data), $maxBytes);
        }

        if (str_starts_with($data, 'data:')) {
            $commaPos = strpos($data, ',');

            if ($commaPos === false) {
                throw InvalidBase64Data::invalidDataUri();
            }

            $data = substr($data, $commaPos + 1);
        }

        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            throw InvalidBase64Data::invalid();
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'mediaman_');

        try {
            file_put_contents($tmpPath, $decoded);

            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $tmpPath);
            finfo_close($fileInfo);

            $mimeType = $mimeType !== false ? $mimeType : 'application/octet-stream';

            $uploadedFile = new UploadedFile(
                $tmpPath,
                $filename,
                $mimeType,
                null,
                true
            );

            $instance = new self($uploadedFile);

            if ($name !== null) {
                $instance->setName($name);
            }

            return $instance;
        } catch (\Throwable $e) {
            @unlink($tmpPath);

            throw $e;
        }
    }

    /**
     * Create an uploader from a remote URL.
     */
    public static function fromUrl(string $url): MediaUploader
    {
        $resolved = UrlGuard::resolve($url);

        // Defensive normalization: rebuild the URL with the lowercased host so
        // curl's CURLOPT_RESOLVE entries (also lowercased) match unambiguously.
        $downloadUrl = self::normalizeUrlHost($url, $resolved['host']);

        $tmpPath = tempnam(sys_get_temp_dir(), 'mediaman_');

        try {
            $downloader = app(Downloader::class);
            $result = $downloader->download($downloadUrl, $tmpPath, $resolved);

            $suggestedName = basename(parse_url($url, PHP_URL_PATH) ?: '');

            if ($suggestedName === '') {
                $suggestedName = 'download';
            }

            if (pathinfo($suggestedName, PATHINFO_EXTENSION) === '') {
                $ext = self::extensionForMime($result['mime']);

                if ($ext !== '') {
                    $suggestedName .= '.'.$ext;
                }
            }

            $uploadedFile = new UploadedFile(
                $tmpPath,
                $suggestedName,
                $result['mime'],
                null,
                true
            );

            return new self($uploadedFile);
        } catch (\Throwable $e) {
            @unlink($tmpPath);

            throw $e;
        }
    }

    /**
     * Create an uploader from raw bytes. MIME sniffed from content — useful
     * for in-memory payloads (generated PDFs, headless screenshots, webhook bodies).
     */
    public static function fromString(string $content, string $fileName, ?string $name = null): MediaUploader
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'mediaman_');

        try {
            file_put_contents($tmpPath, $content);

            $uploadedFile = new UploadedFile(
                $tmpPath,
                $fileName,
                self::detectMimeFromFile($tmpPath),
                null,
                true
            );

            $instance = new self($uploadedFile);

            if ($name !== null) {
                $instance->setName($name);
            }

            return $instance;
        } catch (\Throwable $e) {
            @unlink($tmpPath);

            throw $e;
        }
    }

    /**
     * Create an uploader from a readable PHP stream resource. Caller owns the
     * stream — `fromStream` consumes the cursor but does not call `fclose()`.
     *
     * @param  resource  $stream
     *
     * @throws \InvalidArgumentException when `$stream` is not a resource.
     * @throws \RuntimeException when the temp file cannot be opened for writing.
     */
    public static function fromStream($stream, string $fileName, ?string $name = null): MediaUploader
    {
        if (! is_resource($stream)) {
            throw new \InvalidArgumentException('MediaUploader::fromStream() expects a PHP stream resource.');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'mediaman_');

        try {
            $target = fopen($tmpPath, 'wb');

            if ($target === false) {
                throw new \RuntimeException("Failed to open temp file [$tmpPath] for writing.");
            }

            try {
                stream_copy_to_stream($stream, $target);
            } finally {
                fclose($target);
            }

            $uploadedFile = new UploadedFile(
                $tmpPath,
                $fileName,
                self::detectMimeFromFile($tmpPath),
                null,
                true
            );

            $instance = new self($uploadedFile);

            if ($name !== null) {
                $instance->setName($name);
            }

            return $instance;
        } catch (\Throwable $e) {
            @unlink($tmpPath);

            throw $e;
        }
    }

    /** Sniff MIME via finfo; falls back to `application/octet-stream`. */
    private static function detectMimeFromFile(string $path): string
    {
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($fileInfo === false) {
            return 'application/octet-stream';
        }

        $mimeType = finfo_file($fileInfo, $path);
        finfo_close($fileInfo);

        return is_string($mimeType) ? $mimeType : 'application/octet-stream';
    }

    /** Canonical extension for a MIME type via Symfony's MimeTypes registry. */
    private static function extensionForMime(string $mimeType): string
    {
        return MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? '';
    }

    /**
     * Rebuild a URL with a lowercased host component, preserving the rest.
     */
    private static function normalizeUrlHost(string $url, string $lowercaseHost): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === $lowercaseHost) {
            return $url;
        }

        $rebuilt = ($parts['scheme'] ?? 'http').'://';

        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];

            if (isset($parts['pass'])) {
                $rebuilt .= ':'.$parts['pass'];
            }

            $rebuilt .= '@';
        }

        $rebuilt .= $lowercaseHost;

        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }

        $rebuilt .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            $rebuilt .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }

    /** Replace the source file mid-chain, re-deriving default `name` / `fileName` from it. */
    public function setFile(UploadedFile $file): MediaUploader
    {
        $this->file = $file;

        $fileName = $file->getClientOriginalName();
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        $this->setName($name);
        $this->setFileName($fileName);

        return $this;
    }

    /** Display name persisted in the `name` column. Defaults to the file basename. */
    public function setName(string $name): MediaUploader
    {
        $this->name = $name;

        return $this;
    }

    public function useName(string $name): MediaUploader
    {
        return $this->setName($name);
    }

    /** Attach the upload to a collection (created if missing). */
    public function setCollection(string $name): MediaUploader
    {
        $this->collections[] = $name;

        return $this;
    }

    public function useCollection(string $name): MediaUploader
    {
        return $this->setCollection($name);
    }

    public function toCollection(string $name): MediaUploader
    {
        return $this->setCollection($name);
    }

    /**
     * Override the on-disk file name (sanitized through the resolver's `baseName()`).
     */
    public function setFileName(string $fileName): MediaUploader
    {
        $this->fileName = $this->sanitizeFileName($fileName);

        return $this;
    }

    public function useFileName(string $fileName): MediaUploader
    {
        return $this->setFileName($fileName);
    }

    protected function sanitizeFileName(string $fileName): string
    {
        return app(MediaResolver::class)->baseName($fileName);
    }

    /** Disk where the file lands on success. Defaults to `mediaman.disk`. */
    public function setDisk(string $disk): MediaUploader
    {
        $this->disk = $disk;

        return $this;
    }

    public function toDisk(string $disk): MediaUploader
    {
        return $this->setDisk($disk);
    }

    public function useDisk(string $disk): MediaUploader
    {
        return $this->setDisk($disk);
    }

    /** Custom properties (free-form JSON column) persisted on the media row. */
    public function withCustomProperties(array $custom_properties): MediaUploader
    {
        $this->custom_properties = $custom_properties;

        return $this;
    }

    public function useCustomProperties(array $custom_properties): MediaUploader
    {
        return $this->withCustomProperties($custom_properties);
    }

    /** Overrides `mediaman.allowed_mime_types` for this upload. Wildcards (`image/*`) supported. */
    public function allowMimeTypes(array $mimeTypes): MediaUploader
    {
        $this->allowedMimeTypes = $mimeTypes;

        return $this;
    }

    /** Overrides `mediaman.max_file_size` for this upload. Pass 0 to disable the check. */
    public function maxFileSize(int $bytes): MediaUploader
    {
        $this->maxFileSize = $bytes;

        return $this;
    }

    /**
     * Atomic: row + file write + collection attach run in a single transaction;
     * a partial failure rolls back the row and cleans the written file.
     * Responsive generation + `MediaUploaded` event fire after commit.
     */
    public function upload(): Media
    {
        $this->validateExtension();
        $this->validateMimeType();
        $this->validateFileSize();

        $media = DB::transaction(function () {
            $media = $this->persistMediaModel();

            try {
                $this->writeMediaFile($media);
                $this->attachToCollections($media);
            } catch (\Throwable $e) {
                $this->cleanupFailedUpload($media);

                throw $e;
            }

            return $media;
        });

        $this->generateResponsiveImagesIfRequested($media);

        event(new MediaUploaded($media));

        return $media;
    }

    /**
     * Build and persist the media row. Does not touch storage.
     */
    protected function persistMediaModel(): Media
    {
        $model = $this->mediaModel();

        $media = new $model;

        $media->name = $this->name;
        $media->file_name = $this->fileName;
        $media->disk = $this->disk
            ?: config('mediaman.disk')
            ?: config('filesystems.default');
        $media->mime_type = $this->file->getMimeType();
        $media->size = $this->file->getSize();
        $properties = $this->custom_properties;

        if ($this->isImage()) {
            $meta = $this->readImageMeta($this->file->getPathname());

            if ($meta !== null) {
                if ($this->shouldGeneratePlaceholder()) {
                    try {
                        $image = app(ImageManager::class)
                            ->decode(file_get_contents($this->file->getPathname()));
                        $color = $image->resize(width: 1, height: 1)
                            ->colorAt(0, 0)
                            ->toHex(prefix: true);

                        $meta['dominant_color'] = $color;
                    } catch (\Throwable) {
                    }

                    $placeholder = app(PlaceholderGenerator::class)->generate(
                        $this->file->getPathname(),
                        $meta['width'],
                        $meta['height']
                    );

                    if ($placeholder !== null) {
                        $properties['placeholder'] = $placeholder;
                    }
                }

                $properties[Media::PROPERTY_IMAGE_META] = $meta;
            }
        }

        $media->custom_properties = $properties;

        $media->save();

        return $media;
    }

    /**
     * Treats a `false` return from the filesystem driver as a hard failure
     * (S3 deny, full disk) instead of silently leaving an orphan row.
     *
     * @throws MediaFileWriteFailed
     */
    protected function writeMediaFile(Media $media): void
    {
        $result = $media->filesystem()->putFileAs(
            $media->getDirectory(),
            $this->file,
            $this->fileName
        );

        if ($result === false) {
            throw MediaFileWriteFailed::forPath($media->getPath(), $media->disk);
        }
    }

    /** Requested collection if any, otherwise the configured default (when one exists). */
    protected function attachToCollections(Media $media): void
    {
        if (count($this->collections) > 0) {
            // todo: support multiple collections
            $collectionModel = $this->collectionModel();
            $collection = $collectionModel::firstOrCreate([
                'name' => $this->collections[0],
            ]);

            $collection->validateMedia($media);
            $media->collections()->attach($collection->getKey());
            $collection->enforceMaxItems();

            return;
        }

        // todo: opt-out of the default collection
        $collectionModel = $this->collectionModel();
        $collection = $collectionModel::findByName(config('mediaman.collection'));

        if ($collection) {
            $collection->validateMedia($media);
            $media->collections()->attach($collection->getKey());
            $collection->enforceMaxItems();
        }
    }

    /**
     * Best-effort cleanup for failed uploads. Each media owns an obfuscated
     * directory so deleting it never touches another media's files. Swallows
     * its own errors — the original failure is what the caller should see.
     */
    protected function cleanupFailedUpload(Media $media): void
    {
        try {
            $filesystem = $media->filesystem();

            if (! $filesystem->deleteDirectory($media->getDirectory())) {
                $filesystem->delete($media->getPath());
            }
        } catch (\Throwable) {
            // best-effort cleanup only
        }
    }

    /** Logged but never rolled back — variants are a derived artifact, regenerable later. */
    protected function generateResponsiveImagesIfRequested(Media $media): void
    {
        $requested = $this->generateResponsive
            || config('mediaman.responsive_images.auto_generate', false);

        if (! $requested) {
            return;
        }

        if (! config('mediaman.responsive_images.enabled', true) || ! $media->isOfType(MediaType::IMAGE)) {
            return;
        }

        try {
            $media->generateResponsiveImages($this->responsiveOptions);
        } catch (\Throwable $e) {
            Log::warning('MediaMan: responsive image generation failed after upload', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Enable responsive variant generation; chain `withBreakpoints`/`withFormats`/`withQuality`. */
    public function generateResponsive(array $options = []): MediaUploader
    {
        $this->generateResponsive = true;
        $this->responsiveOptions = $options;

        return $this;
    }

    protected function isImage(): bool
    {
        return str_starts_with($this->file->getMimeType() ?? '', 'image/');
    }

    protected function shouldGeneratePlaceholder(): bool
    {
        return (bool) config('mediaman.placeholder.enabled', true);
    }

    /**
     * `getimagesize()` reads only the header (no pixel decode); falls back to
     * a full Intervention decode for formats it doesn't support (AVIF, HEIC).
     */
    protected function readImageMeta(string $path): ?array
    {
        $info = @getimagesize($path);

        if ($info && $info[0] > 0 && $info[1] > 0) {
            return ['width' => (int) $info[0], 'height' => (int) $info[1]];
        }

        try {
            $image = app(ImageManager::class)->decode(file_get_contents($path));
            $width = $image->width();
            $height = $image->height();

            if ($width <= 0 || $height <= 0) {
                return null;
            }

            return ['width' => $width, 'height' => $height];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Override responsive widths (px) for this upload. */
    public function withBreakpoints(array $breakpoints): MediaUploader
    {
        $this->responsiveOptions['widths'] = $breakpoints;

        return $this;
    }

    /** Override responsive output formats for this upload (e.g. `['webp', 'jpg']`). */
    public function withFormats(array $formats): MediaUploader
    {
        $this->responsiveOptions['formats'] = $formats;

        return $this;
    }

    /** Override responsive encoder quality (0-100) for this upload. */
    public function withQuality(int $quality): MediaUploader
    {
        $this->responsiveOptions['quality'] = $quality;

        return $this;
    }

    /** @throws MimeTypeNotAllowed */
    protected function validateMimeType(): void
    {
        $allowed = $this->allowedMimeTypes ?? config('mediaman.allowed_mime_types', []);

        if (empty($allowed)) {
            return;
        }

        $mimeType = $this->file->getMimeType();

        foreach ($allowed as $pattern) {
            if ($this->mimeTypeMatches($mimeType, $pattern)) {
                return;
            }
        }

        throw MimeTypeNotAllowed::forMimeType($mimeType);
    }

    /** @throws FileSizeExceeded */
    protected function validateFileSize(): void
    {
        $maxBytes = $this->maxFileSize ?? (int) config('mediaman.max_file_size', 0);

        if ($maxBytes <= 0) {
            return;
        }

        $actualBytes = (int) $this->file->getSize();

        if ($actualBytes > $maxBytes) {
            throw FileSizeExceeded::forSize($actualBytes, $maxBytes);
        }
    }

    /** @throws DisallowedExtension */
    protected function validateExtension(): void
    {
        if (! config('mediaman.block_disallowed_extensions', true)) {
            return;
        }

        $disallowed = $this->getDisallowedExtensions();

        if (empty($disallowed)) {
            return;
        }

        $extension = strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION));

        if ($extension !== '' && in_array($extension, $disallowed, true)) {
            throw DisallowedExtension::forExtension($extension);
        }
    }

    protected function getDisallowedExtensions(): array
    {
        return config('mediaman.disallowed_extensions') ?? [
            'php', 'phtml', 'phar', 'shtml', 'htaccess',
            'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
        ];
    }

    /**
     * Check if a MIME type matches a pattern (supports wildcards like 'image/*').
     */
    protected function mimeTypeMatches(string $mimeType, string $pattern): bool
    {
        if ($pattern === $mimeType) {
            return true;
        }

        if (str_ends_with($pattern, '/*')) {
            return str_starts_with($mimeType, substr($pattern, 0, -1));
        }

        return false;
    }
}
