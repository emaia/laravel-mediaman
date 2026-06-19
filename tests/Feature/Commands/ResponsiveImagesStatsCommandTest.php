<?php

use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;

it('shows stats with no media items', function () {
    $this->artisan('mediaman:responsive-stats')
        ->expectsOutputToContain('Responsive images')
        ->expectsOutputToContain('Total images')
        ->expectsOutputToContain('With responsive')
        ->expectsOutputToContain('Without responsive')
        ->assertExitCode(0);
});

it('shows stats with image media items', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    MediaUploader::source($file)->upload();

    $file2 = UploadedFile::fake()->image('test2.jpg');
    MediaUploader::source($file2)->upload();

    $this->artisan('mediaman:responsive-stats')
        ->expectsOutputToContain('Total images')
        ->assertExitCode(0);
});

it('shows current configuration', function () {
    $this->artisan('mediaman:responsive-stats')
        ->expectsOutputToContain('Configuration')
        ->expectsOutputToContain('Enabled')
        ->expectsOutputToContain('Auto generate')
        ->expectsOutputToContain('Queue')
        ->expectsOutputToContain('Quality')
        ->expectsOutputToContain('Formats')
        ->expectsOutputToContain('Breakpoints')
        ->expectsOutputToContain('Width calculator')
        ->assertExitCode(0);
});
