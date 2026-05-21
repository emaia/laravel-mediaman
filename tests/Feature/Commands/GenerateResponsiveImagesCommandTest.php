<?php

use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

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

it('filters by collection name', function () {
    $matched = MediaUploader::source(UploadedFile::fake()->image('matched.jpg', 800, 600))
        ->toCollection('targeted')->upload();
    MediaUploader::source(UploadedFile::fake()->image('other.jpg', 800, 600))
        ->toCollection('other')->upload();

    $this->artisan('mediaman:generate-responsive', [
        '--collection' => 'targeted',
        '--queue' => true,
    ])
        ->expectsOutputToContain('Processing 1 media items')
        ->expectsOutputToContain('Queued: '.$matched->name)
        ->assertExitCode(0);
});

it('queues jobs when --queue option is provided', function () {
    Queue::fake();

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $this->artisan('mediaman:generate-responsive', ['--queue' => true])
        ->expectsOutputToContain('Queued: '.$media->name)
        ->expectsOutputToContain('Responsive image generation jobs have been queued')
        ->assertExitCode(0);

    Queue::assertPushed(GenerateResponsiveImages::class);
});

it('skips items that already have responsive images unless --force is provided', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg', 800, 600))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 1],
    ]);
    $media->save();

    $this->artisan('mediaman:generate-responsive', ['--queue' => false])
        ->expectsOutputToContain('No media items found to process')
        ->assertExitCode(0);
});

it('reprocesses items with --force even when responsive images exist', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg', 800, 600))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 1],
    ]);
    $media->save();

    $this->artisan('mediaman:generate-responsive', ['--force' => true, '--queue' => false])
        ->expectsOutputToContain('Processing 1 media items')
        ->assertExitCode(0);
});

it('continues processing when an individual generation fails', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg', 800, 600))->upload();

    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('generateResponsiveImages')
        ->andThrow(new RuntimeException('boom'));
    app()->instance(ResponsiveImageGenerator::class, $generator);

    $this->artisan('mediaman:generate-responsive', ['--queue' => false])
        ->expectsOutputToContain('Failed: '.$media->name.' - boom')
        ->assertExitCode(0);
});
