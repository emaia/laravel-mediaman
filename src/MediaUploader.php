<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Models\MediaCollection;
use Illuminate\Http\UploadedFile;

class MediaUploader
{
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
    protected $data = [];

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
     *
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
        return str_replace(['#', '/', '\\', ' '], '-', $fileName);
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
     * Set any custom data to be saved to the media item.
     */
    public function withData(array $data): MediaUploader
    {
        $this->data = $data;

        return $this;
    }

    public function useData(array $data): MediaUploader
    {
        return $this->withData($data);
    }

    /**
     * Upload the file to the specified disk.
     *
     */
    public function upload()
    {
        $model = config('mediaman.models.media');

        $media = new $model;

        $media->name = $this->name;
        $media->file_name = $this->fileName;
        $media->disk = $this->disk ?: config('mediaman.disk');
        $media->mime_type = $this->file->getMimeType();
        $media->size = $this->file->getSize();
        $media->data = $this->data;

        $media->save();

        $media->filesystem()->putFileAs(
            $media->getDirectory(),
            $this->file,
            $this->fileName
        );

        if (count($this->collections) > 0) {
            // todo: support multiple collections
            $collection = MediaCollection::firstOrCreate([
                'name' => $this->collections[0],
            ]);

            $media->collections()->attach($collection->id);
        } else {
            // add to the default collection
            // todo: allow not to add in the default collection

            /** @var MediaCollection|null $collection */
            $collection = MediaCollection::findByName(config('mediaman.collection'));
            if ($collection) {
                $media->collections()->attach($collection->id);
            }
        }

        return $media;
    }
}
