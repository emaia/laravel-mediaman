<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;

it('shows message when no media with responsive images found', function () {
    $this->artisan('mediaman:clear-responsive', ['--confirm' => true])
        ->expectsOutputToContain('No media items with responsive images found')
        ->assertExitCode(0);
});

it('shows no items even with media that has no responsive images', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)->upload();

    $this->artisan('mediaman:clear-responsive', ['--confirm' => true])
        ->expectsOutputToContain('No media items with responsive images found')
        ->assertExitCode(0);
});

it('cancels when user does not confirm', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // Manually set responsive images data
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'test', 'url' => '/test', 'size' => 1000],
    ]);
    $media->save();

    $this->artisan('mediaman:clear-responsive')
        ->expectsConfirmation(
            'This will clear responsive images for 1 media items. Continue?',
            'no'
        )
        ->expectsOutputToContain('Operation cancelled')
        ->assertExitCode(0);
});

it('filters by media id', function () {
    $this->artisan('mediaman:clear-responsive', ['--media' => 9999, '--confirm' => true])
        ->expectsOutputToContain('No media items with responsive images found')
        ->assertExitCode(0);
});
