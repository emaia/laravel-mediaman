<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('can upload a file to the default disk', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->disk)->toEqual(self::DEFAULT_DISK);

    $filesystem = Storage::disk(self::DEFAULT_DISK);

    expect($filesystem->exists($media->getPath()))->toBeTrue();
});

it('can upload a file to a specific disk', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $customDisk = 'custom';

    // Create a test filesystem for the custom disk...
    Storage::fake($customDisk);

    $media = MediaUploader::source($file)
        ->setDisk($customDisk)
        ->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->disk)->toEqual($customDisk);

    $filesystem = Storage::disk($customDisk);

    expect($filesystem->exists($media->getPath()))->toBeTrue();
});

it('can change the name of the media model', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $media = MediaUploader::source($file)
        ->useName($newName = 'New name')
        ->upload();

    expect($media->name)->toEqual($newName);
});

it('can rename the file before it gets uploaded', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $media = MediaUploader::source($file)
        ->useFileName($newFileName = 'new-file-name.jpg')
        ->upload();

    expect($media->file_name)->toEqual($newFileName);
});

it('will sanitize the file name', function () {
    $file = UploadedFile::fake()->image('bad file name#023.jpg');

    $media = MediaUploader::source($file)->upload();

    expect($media->file_name)->toEqual('bad-file-name-023.jpg');
});

it('can save data to the media model', function () {
    $file = UploadedFile::fake()->image('image.jpg');

    $media = MediaUploader::source($file)
        ->withData([
            'test-01' => 'test data 01',
            'test-02' => 'test data 02'
        ])
        ->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->data['test-01'])->toEqual('test data 01')
        ->and($media->data['test-02'])->toEqual('test data 02');
});
