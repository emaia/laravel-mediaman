<?php

use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;

it('allows uploads of any size by default (max_file_size = 0)', function () {
    config(['mediaman.max_file_size' => 0]);

    $file = UploadedFile::fake()->create('big.bin', 5000); // 5 MB

    $media = MediaUploader::source($file)->upload();

    expect($media->size)->toBeGreaterThan(0);
});

it('accepts files within the configured limit', function () {
    config(['mediaman.max_file_size' => 10 * 1024 * 1024]); // 10 MB

    $file = UploadedFile::fake()->create('within.bin', 1024); // 1 MB

    $media = MediaUploader::source($file)->upload();

    expect($media->size)->toBeLessThanOrEqual(10 * 1024 * 1024);
});

it('throws FileSizeExceeded when the file is larger than the limit', function () {
    config(['mediaman.max_file_size' => 1024 * 1024]); // 1 MB

    $file = UploadedFile::fake()->create('huge.bin', 2048); // 2 MB

    MediaUploader::source($file)->upload();
})->throws(FileSizeExceeded::class);

it('uses the fluent maxFileSize override over config', function () {
    config(['mediaman.max_file_size' => 100 * 1024 * 1024]); // 100 MB config

    $file = UploadedFile::fake()->create('overridden.bin', 1024); // 1 MB

    MediaUploader::source($file)
        ->maxFileSize(512 * 1024) // 512 KB override
        ->upload();
})->throws(FileSizeExceeded::class);

it('treats fluent maxFileSize(0) as unlimited', function () {
    config(['mediaman.max_file_size' => 1024]); // 1 KB config

    $file = UploadedFile::fake()->create('bypass.bin', 2048); // 2 MB

    $media = MediaUploader::source($file)
        ->maxFileSize(0)
        ->upload();

    expect($media->size)->toBeGreaterThan(1024);
});

it('reports both actual and max bytes in the exception message', function () {
    config(['mediaman.max_file_size' => 1024]); // 1 KB

    $file = UploadedFile::fake()->create('over.bin', 2); // 2 KB

    try {
        MediaUploader::source($file)->upload();
        $this->fail('Expected FileSizeExceeded to be thrown');
    } catch (FileSizeExceeded $e) {
        expect($e->getMessage())->toContain('1024')
            ->and($e->getMessage())->toContain((string) $file->getSize());
    }
});

it('does not save the media when validation fails', function () {
    config(['mediaman.max_file_size' => 1024]);

    $file = UploadedFile::fake()->create('rejected.bin', 2048);
    $before = Media::count();

    try {
        MediaUploader::source($file)->upload();
    } catch (FileSizeExceeded) {
        // expected
    }

    expect(Media::count())->toEqual($before);
});
