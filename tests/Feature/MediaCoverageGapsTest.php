<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Models\MediaCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection as BaseCollection;
use Intervention\Image\Image;

// -- Computed attributes ---------------------------------------------------

it('formats friendly size for bytes', function () {
    $media = Media::factory()->make(['size' => 500]);
    expect($media->friendly_size)->toEqual('500 B');
});

it('formats friendly size for kilobytes', function () {
    $media = Media::factory()->make(['size' => 2048]);
    expect($media->friendly_size)->toEqual('2 KB');
});

it('formats friendly size for megabytes', function () {
    $media = Media::factory()->make(['size' => 5 * 1024 * 1024]);
    expect($media->friendly_size)->toEqual('5 MB');
});

it('returns 0 KB for zero-sized files', function () {
    $media = Media::factory()->make(['size' => 0]);
    expect($media->friendly_size)->toEqual('0 KB');
});

it('exposes media_url and media_uri attributes', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    expect($media->media_uri)->toEqual($media->getUrl())
        ->and($media->media_url)->toContain($media->media_uri);
});

// -- Conversion paths ------------------------------------------------------

it('returns null from getConversionUrl when the conversion file does not exist', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    expect($media->getConversionUrl('missing'))->toBeNull();
});

it('returns conversion URL from getUrlWithFallback when conversion exists on disk', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    // Place a fake conversion file at the expected path
    $conversionPath = $media->getDirectory().'/conversions/thumb/test.jpg';
    $media->filesystem()->put($conversionPath, 'fake-content');

    expect($media->getUrlWithFallback('thumb'))->toEqual($media->getUrl('thumb'));
});

it('returns getOriginalPath without a conversion segment by default', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    expect($media->getOriginalPath())->toEqual($media->getDirectory().'/'.$media->file_name);
});

it('appends conversion directory to getOriginalPath', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    expect($media->getOriginalPath('thumb'))
        ->toEqual($media->getDirectory().'/conversions/thumb/'.$media->file_name);
});

it('clears the conversion format cache', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    $registry = app(ConversionRegistry::class);
    $registry->register('webp-thumb', fn (Image $image) => $image->resize(64, 64));

    // First access populates the cache via name-based detection (`webp` in name)
    $first = $media->getPath('webp-thumb');

    $media->clearConversionFormatCache();

    $second = $media->getPath('webp-thumb');

    expect($first)->toEqual($second);
});

// -- Disk usability --------------------------------------------------------

it('runs accessibility check on disks when configured', function () {
    Storage::fake('accessible');
    config(['filesystems.disks.accessible' => [
        'driver' => 'local',
        'root' => storage_path('app/accessible'),
    ]]);
    config(['mediaman.check_disk_accessibility' => true]);

    $reflection = new ReflectionClass(Media::class);
    $method = $reflection->getMethod('ensureDiskUsability');
    $method->setAccessible(true);

    expect($method->invoke(null, 'accessible'))->toBeNull();
});

// -- syncCollections / fetchCollections variants --------------------------

it('detaches all collections when syncCollections is called with empty array', function () {
    $collection = MediaCollection::create(['name' => 'gallery']);
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->toCollection('gallery')->upload();

    expect($media->collections)->toHaveCount(1);

    $media->syncCollections([]);

    expect($media->fresh()->collections)->toHaveCount(0);
});

it('returns null when fetchCollections receives an unsupported input', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    $reflection = new ReflectionClass(Media::class);
    $method = $reflection->getMethod('fetchCollections');
    $method->setAccessible(true);

    // floats fall through all checks and return null
    expect($method->invoke($media, 1.5))->toBeNull();
});

it('fetches collections from a base Support collection of models', function () {
    $collection = MediaCollection::create(['name' => 'gallery']);
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    $attached = $media->attachCollections(new BaseCollection([$collection]));

    expect($attached)->toEqual(1)
        ->and($media->fresh()->collections->pluck('id')->toArray())->toContain($collection->id);
});

// -- File lifecycle on update ---------------------------------------------

it('moves the file to a new disk when the disk attribute is changed', function () {
    config(['filesystems.disks.secondary' => [
        'driver' => 'local',
        'root' => storage_path('app/secondary'),
    ]]);
    Storage::fake('secondary');

    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    $oldPath = $media->getDirectory().'/'.$media->file_name;
    expect($media->filesystem()->exists($oldPath))->toBeTrue();

    $media->disk = 'secondary';
    $media->save();

    expect(Storage::disk('secondary')->exists($oldPath))->toBeTrue();
});

it('treats empty Eloquent collection as detach-all in syncCollections', function () {
    $collection = MediaCollection::create(['name' => 'gallery']);
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->toCollection('gallery')->upload();

    expect($media->collections)->toHaveCount(1);

    $media->syncCollections(new Collection);

    expect($media->fresh()->collections)->toHaveCount(0);
});

it('detects format from existing file when no closure hint is available', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    app(ConversionRegistry::class)->register('genericName', fn (Image $image) => $image);

    // Pre-create a webp file at the expected conversion path
    $existingPath = $media->getDirectory().'/conversions/genericName/test.webp';
    $media->filesystem()->put($existingPath, 'fake');

    expect($media->getPath('genericName'))->toEndWith('.webp');
});

it('throws when disk accessibility check fails to delete', function () {
    config(['filesystems.disks.broken' => [
        'driver' => 'local',
        'root' => '/proc/this-is-not-writable-'.uniqid(),
    ]]);
    config(['mediaman.check_disk_accessibility' => true]);

    $reflection = new ReflectionClass(Media::class);
    $method = $reflection->getMethod('ensureDiskUsability');
    $method->setAccessible(true);

    $method->invoke(null, 'broken');
})->throws(Exception::class);

it('renames the file on disk when file_name is updated', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->upload();

    $originalPath = $media->getDirectory().'/'.$media->file_name;
    expect($media->filesystem()->exists($originalPath))->toBeTrue();

    $media->file_name = 'renamed.jpg';
    $media->save();

    expect($media->filesystem()->exists($media->getDirectory().'/renamed.jpg'))->toBeTrue()
        ->and($media->filesystem()->exists($originalPath))->toBeFalse();
});
