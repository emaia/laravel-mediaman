<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Enums\MediaType;
use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Exceptions\MimeTypeNotAllowed;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Traits\ResolvesModels;
use Illuminate\Http\UploadedFile;

class MediaUploader
{
    use ResolvesModels;
    /** @var UploadedFile */
    protected $file;

    /** @var string */
    protected $name;

    /** @var array */
    protected $collections = [];

    /** @var string */
    protected $fileName;

    /** @var string */
    protected $disk;

    /** @var array */
    protected $custom_properties = [];

    /** @var array|null */
    protected ?array $allowedMimeTypes = null;

    /**
     * Enable automatic responsive image generation.
     */
    protected bool $generateResponsive = false;

    /**
     * Options for responsive image generation.
     */
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
     * Set the file to be uploaded.
     */
    public function setFile(UploadedFile $file): MediaUploader
    {
        $this->file = $file;

        $fileName = $file->getClientOriginalName();
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        $this->setName($name);
        $this->setFileName($fileName);

        return $this;
    }

    /**
     * Set the name of the media item.
     */
    public function setName(string $name): MediaUploader
    {
        $this->name = $name;

        return $this;
    }

    public function useName(string $name): MediaUploader
    {
        return $this->setName($name);
    }

    /**
     * Set the name of the media item.
     */
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
     * Set the name of the file.
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

    /**
     * Sanitize the file name.
     */
    protected function sanitizeFileName(string $fileName): string
    {
        // Strip null bytes and control/invisible unicode chars
        $fileName = preg_replace('/[\x00\p{C}]/u', '', $fileName);

        // Separate name and final extension
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        // Replace dangerous characters (includes .. for directory traversal)
        $name = str_replace(
            ['..', '#', '/', '\\', ' ', '?', '%', '*', ':', '|', '"', "'", '<', '>'],
            '-',
            $name
        );

        // Replace dots in name part (prevents double extensions like file.php.jpg)
        $name = str_replace('.', '-', $name);

        // Collapse multiple dashes and trim
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');

        // Fallback for empty name
        if ($name === '') {
            $name = 'unnamed';
        }

        return $extension !== '' ? "{$name}.{$extension}" : $name;
    }

    /**
     * Specify the disk where the file will be stored.
     */
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

    /**
     * Set any custom custom_properties to be saved to the media item.
     */
    public function withCustomProperties(array $custom_properties): MediaUploader
    {
        $this->custom_properties = $custom_properties;

        return $this;
    }

    public function useCustomProperties(array $custom_properties): MediaUploader
    {
        return $this->withCustomProperties($custom_properties);
    }

    /**
     * Set the allowed MIME types for this upload.
     *
     * Overrides the global `mediaman.allowed_mime_types` config.
     * Supports wildcards like 'image/*'.
     */
    public function allowMimeTypes(array $mimeTypes): MediaUploader
    {
        $this->allowedMimeTypes = $mimeTypes;

        return $this;
    }

    /**
     * Upload the file to the specified disk.
     */
    public function upload(): Media
    {
        $this->validateMimeType();

        $model = $this->mediaModel();

        $media = new $model;

        $media->name = $this->name;
        $media->file_name = $this->fileName;
        $media->disk = $this->disk ?: config('mediaman.disk');
        $media->mime_type = $this->file->getMimeType();
        $media->size = $this->file->getSize();
        $media->custom_properties = $this->custom_properties;

        $media->save();

        $media->filesystem()->putFileAs(
            $media->getDirectory(),
            $this->file,
            $this->fileName
        );

        if (count($this->collections) > 0) {
            // todo: support multiple collections
            $collectionModel = $this->collectionModel();
            $collection = $collectionModel::firstOrCreate([
                'name' => $this->collections[0],
            ]);

            $media->collections()->attach($collection->getKey());
        } else {
            // add to the default collection
            // todo: allow not to add in the default collection
            $collectionModel = $this->collectionModel();
            $collection = $collectionModel::findByName(config('mediaman.collection'));
            if ($collection) {
                $media->collections()->attach($collection->getKey());
            }
        }

        // Generate responsive images if requested or auto-enabled
        if ($this->generateResponsive || config('mediaman.responsive_images.auto_generate', false)) {
            if (config('mediaman.responsive_images.enabled', true) && $media->isOfType(MediaType::IMAGE)) {
                $media->generateResponsiveImages($this->responsiveOptions);
            }
        }

        event(new MediaUploaded($media));

        return $media;
    }

    /**
     * Enable responsive image generation.
     */
    public function generateResponsive(array $options = []): MediaUploader
    {
        $this->generateResponsive = true;
        $this->responsiveOptions = $options;

        return $this;
    }

    /**
     * Set responsive image breakpoints.
     */
    public function withBreakpoints(array $breakpoints): MediaUploader
    {
        $this->responsiveOptions['widths'] = $breakpoints;

        return $this;
    }

    /**
     * Set responsive image formats.
     */
    public function withFormats(array $formats): MediaUploader
    {
        $this->responsiveOptions['formats'] = $formats;

        return $this;
    }

    /**
     * Set responsive image quality.
     */
    public function withQuality(int $quality): MediaUploader
    {
        $this->responsiveOptions['quality'] = $quality;

        return $this;
    }

    /**
     * Validate the file MIME type against allowed types.
     *
     * @throws MimeTypeNotAllowed
     */
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
