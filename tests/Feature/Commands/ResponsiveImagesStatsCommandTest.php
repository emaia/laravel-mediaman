<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;

it('shows stats with no media items', function () {
    $this->artisan('mediaman:responsive-stats')
        ->expectsOutputToContain('Total image files: 0')
        ->expectsOutputToContain('With responsive images: 0')
        ->expectsOutputToContain('Without responsive images: 0')
        ->assertExitCode(0);
});

it('shows stats with image media items', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    MediaUploader::source($file)->upload();

    $file2 = UploadedFile::fake()->image('test2.jpg');
    MediaUploader::source($file2)->upload();

    $this->artisan('mediaman:responsive-stats')
        ->expectsOutputToContain('Total image files: 2')
        ->assertExitCode(0);
});

it('shows current configuration', function () {
    $this->artisan('mediaman:responsive-stats')
        ->expectsOutputToContain('Current Configuration')
        ->expectsOutputToContain('Quality:')
        ->assertExitCode(0);
});
