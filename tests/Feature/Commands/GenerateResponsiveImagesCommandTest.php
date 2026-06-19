<?php

use Emaia\MediaMan\Jobs\GenerateResponsiveImages;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Output\BufferedOutput;

function captureGenerateResponsiveOutput(array $options = []): string
{
    $output = new BufferedOutput;
    Artisan::call('mediaman:generate-responsive', $options, $output);

    return $output->fetch();
}

it('shows message when no media items found', function () {
    $this->artisan('mediaman:generate-responsive')
        ->expectsOutputToContain('No media items found to process')
        ->assertExitCode(0);
});

it('processes image media items inline', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)->upload();

    $out = captureGenerateResponsiveOutput();
    expect($out)->toContain('Media items', 'Processed');
});

it('filters by media id', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    $file2 = UploadedFile::fake()->image('test2.jpg', 800, 600);
    MediaUploader::source($file2)->upload();

    $out = captureGenerateResponsiveOutput(['--media' => (string) $media->id]);
    expect($out)->toContain('Media items', 'Processed', '1');
});

it('shows no items when filtering by non-existent media id', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)->upload();

    $this->artisan('mediaman:generate-responsive', ['--media' => '9999'])
        ->expectsOutputToContain('No media items found to process')
        ->assertExitCode(0);
});

it('filters by collection name', function () {
    MediaUploader::source(UploadedFile::fake()->image('matched.jpg', 800, 600))
        ->toCollection('targeted')->upload();
    MediaUploader::source(UploadedFile::fake()->image('other.jpg', 800, 600))
        ->toCollection('other')->upload();

    $out = captureGenerateResponsiveOutput([
        '--collection' => 'targeted',
        '--queue' => true,
    ]);
    expect($out)->toContain('Media items', 'Dispatched', '1');
});

it('queues jobs when --queue option is provided', function () {
    Queue::fake();

    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)->upload();

    $out = captureGenerateResponsiveOutput(['--queue' => true]);
    expect($out)->toContain('Mode', 'queue', 'Dispatched', '1');

    Queue::assertPushed(GenerateResponsiveImages::class);
});

it('skips items that already have responsive images unless --force is provided', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg', 800, 600))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 1],
    ]);
    $media->save();

    $this->artisan('mediaman:generate-responsive')
        ->expectsOutputToContain('No media items found to process')
        ->assertExitCode(0);
});

it('reprocesses items with --force even when responsive images exist', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg', 800, 600))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 1],
    ]);
    $media->save();

    $out = captureGenerateResponsiveOutput(['--force' => true]);
    expect($out)->toContain('Media items', 'Processed');
});

it('continues processing when an individual generation fails', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg', 800, 600))->upload();

    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('generateResponsiveImages')
        ->andThrow(new RuntimeException('boom'));
    app()->instance(ResponsiveImageGenerator::class, $generator);

    $out = captureGenerateResponsiveOutput();
    expect($out)->toContain('Failed');
});

it('supports --media with range syntax', function () {
    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->useName('a')->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->useName('b')->upload();
    $m3 = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->useName('c')->upload();

    $out = captureGenerateResponsiveOutput([
        '--media' => "{$m1->id}..{$m2->id}",
    ]);
    expect($out)->toContain('Media items', 'Processed', '2');
});

it('fails with invalid --media range', function () {
    $this->artisan('mediaman:generate-responsive', [
        '--media' => '5..1',
    ])
        ->expectsOutputToContain('Invalid --media value')
        ->assertExitCode(1);
});
