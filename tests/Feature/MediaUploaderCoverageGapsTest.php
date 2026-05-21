<?php

use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

it('exposes toDisk as a fluent alias for setDisk', function () {
    config(['filesystems.disks.alt' => [
        'driver' => 'local',
        'root' => storage_path('app/alt'),
    ]]);
    Storage::fake('alt');

    $file = UploadedFile::fake()->image('test.jpg', 100, 100);
    $media = MediaUploader::source($file)->toDisk('alt')->upload();

    expect($media->disk)->toEqual('alt')
        ->and(Storage::disk('alt')->exists($media->getDirectory().'/'.$media->file_name))->toBeTrue();
});

it('dispatches the responsive images job when generateResponsive is called', function () {
    config(['mediaman.responsive_images.enabled' => true]);
    config(['mediaman.responsive_images.queue' => true]);
    Queue::fake();

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)
        ->generateResponsive(['quality' => 75])
        ->upload();

    Queue::assertPushed(GenerateResponsiveImages::class);
});

it('auto-generates responsive images when responsive_images.auto_generate is true', function () {
    config(['mediaman.responsive_images.enabled' => true]);
    config(['mediaman.responsive_images.queue' => true]);
    config(['mediaman.responsive_images.auto_generate' => true]);
    Queue::fake();

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)->upload();

    Queue::assertPushed(GenerateResponsiveImages::class);
});

it('does not generate responsive images for non-image media', function () {
    config(['mediaman.responsive_images.auto_generate' => true]);
    Queue::fake();

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    MediaUploader::source($file)->generateResponsive()->upload();

    Queue::assertNotPushed(GenerateResponsiveImages::class);
});

it('stores breakpoints in responsive options', function () {
    config(['mediaman.responsive_images.queue' => false]);

    $file = UploadedFile::fake()->image('test.jpg', 1600, 1200);

    $media = MediaUploader::source($file)
        ->generateResponsive()
        ->withBreakpoints([320, 640])
        ->withFormats(['jpg'])
        ->withQuality(70)
        ->upload();

    $widths = $media->fresh()->getResponsiveImages()->pluck('width')->unique()->sort()->values()->toArray();
    expect($widths)->toEqual([320, 640]);
});

it('useCollection and useFileName are fluent aliases', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    $media = MediaUploader::source($file)
        ->useCollection('gallery')
        ->useFileName('renamed.jpg')
        ->useCustomProperties(['author' => 'jane'])
        ->upload();

    expect($media->file_name)->toEqual('renamed.jpg')
        ->and($media->custom_properties)->toHaveKey('author')
        ->and($media->custom_properties['author'])->toEqual('jane')
        ->and($media->collections->pluck('name')->toArray())->toContain('gallery');
});

it('sanitizes dangerous characters in file names', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    $media = MediaUploader::source($file)
        ->useFileName('../../etc/passwd.jpg')
        ->upload();

    expect($media->file_name)->not->toContain('..')
        ->and($media->file_name)->not->toContain('/')
        ->and($media->file_name)->toEndWith('.jpg');
});

it('collapses dots in filename to prevent double-extensions', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    $media = MediaUploader::source($file)
        ->useFileName('file.php.jpg')
        ->upload();

    expect($media->file_name)->toEqual('file-php.jpg');
});

it('falls back to "unnamed" when sanitization yields an empty name', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    $media = MediaUploader::source($file)
        ->useFileName('....jpg')
        ->upload();

    expect($media->file_name)->toEqual('unnamed.jpg');
});

it('drops the dot when the input filename has no extension', function () {
    $file = UploadedFile::fake()->image('test.jpg', 100, 100);

    $media = MediaUploader::source($file)
        ->useFileName('justaname')
        ->upload();

    expect($media->file_name)->toEqual('justaname');
});
