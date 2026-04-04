<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;

it('shows message when no media items found', function () {
    $this->artisan('mediaman:generate-responsive')
        ->expectsOutputToContain('No media items found to process')
        ->assertExitCode(0);
});

it('processes image media items', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->artisan('mediaman:generate-responsive', ['--queue' => false])
        ->expectsOutputToContain('Processing 1 media items')
        ->assertExitCode(0);
});

it('filters by media id', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $file2 = UploadedFile::fake()->image('test2.jpg', 800, 600);
    MediaUploader::source($file2)->upload();

    $this->artisan('mediaman:generate-responsive', ['--media' => $media->id, '--queue' => false])
        ->expectsOutputToContain('Processing 1 media items')
        ->assertExitCode(0);
});

it('shows no items when filtering by non-existent media id', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)->upload();

    $this->artisan('mediaman:generate-responsive', ['--media' => 9999])
        ->expectsOutputToContain('No media items found to process')
        ->assertExitCode(0);
});
