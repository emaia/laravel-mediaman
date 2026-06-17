<?php

use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

// --- Image driver auto-detection ---

it('uses the configured image driver when set explicitly', function () {
    Config::set('mediaman.driver', 'gd');

    app()->forgetInstance(ImageManager::class);

    $manager = app(ImageManager::class);

    expect($manager->driver)->toBeInstanceOf(GdDriver::class);
});

it('auto-detects the image driver when config is null', function () {
    Config::set('mediaman.driver', null);

    app()->forgetInstance(ImageManager::class);

    $manager = app(ImageManager::class);

    $expected = extension_loaded('imagick') ? ImagickDriver::class : GdDriver::class;

    expect($manager->driver)->toBeInstanceOf($expected);
});

// --- Disk fallback ---

it('falls back to filesystems.default when mediaman.disk is null', function () {
    Storage::fake('fallback-disk');

    Config::set('mediaman.disk', null);
    Config::set('filesystems.default', 'fallback-disk');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect($media->disk)->toEqual('fallback-disk');
});

it('honors mediaman.disk when explicitly set even if filesystems.default differs', function () {
    Storage::fake('explicit-disk');

    Config::set('mediaman.disk', 'explicit-disk');
    Config::set('filesystems.default', 'other-disk');

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect($media->disk)->toEqual('explicit-disk');
});
