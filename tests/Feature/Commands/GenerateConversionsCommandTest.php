<?php

use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Symfony\Component\Console\Output\BufferedOutput;

function captureGenerateConversionsOutput(array $options = []): string
{
    $output = new BufferedOutput;
    Artisan::call('mediaman:generate-conversions', $options, $output);

    return $output->fetch();
}

it('exits with error when --conversion is missing', function () {
    $this->artisan('mediaman:generate-conversions')
        ->expectsOutputToContain('--conversion option is required')
        ->assertExitCode(1);
});

it('exits with error for unknown conversion names', function () {
    $this->artisan('mediaman:generate-conversions', ['--conversion' => 'nonexistent'])
        ->expectsOutputToContain('Unknown conversion')
        ->assertExitCode(1);
});

it('registers a conversion and generates it for a media item', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200)->encodeUsingFormat(Format::JPEG);
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $out = captureGenerateConversionsOutput(['--conversion' => 'thumb', '--force' => true]);

    expect($out)->toContain('Generate conversions', 'Conversions', 'thumb', 'Media items', 'Processed', '1');

    $path = $media->fresh()->getPath('thumb');
    expect(Storage::disk(self::DEFAULT_DISK)->exists($path))->toBeTrue();
});

it('skips existing conversions without --force', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200)->encodeUsingFormat(Format::JPEG);
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    captureGenerateConversionsOutput(['--conversion' => 'thumb', '--force' => true]);

    $mtimeBefore = Storage::disk(self::DEFAULT_DISK)->lastModified($media->getPath('thumb'));

    $out = captureGenerateConversionsOutput(['--conversion' => 'thumb']);

    expect($out)->toContain('Skipped (already exist)', '1');

    $mtimeAfter = Storage::disk(self::DEFAULT_DISK)->lastModified($media->getPath('thumb'));

    expect($mtimeAfter)->toEqual($mtimeBefore);
});

it('overwrites existing conversions with --force', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200)->encodeUsingFormat(Format::JPEG);
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    captureGenerateConversionsOutput(['--conversion' => 'thumb', '--force' => true]);

    sleep(1);
    $mtimeBefore = Storage::disk(self::DEFAULT_DISK)->lastModified($media->getPath('thumb'));

    $out = captureGenerateConversionsOutput(['--conversion' => 'thumb', '--force' => true]);

    expect($out)->toContain('Processed', '1');

    $mtimeAfter = Storage::disk(self::DEFAULT_DISK)->lastModified($media->getPath('thumb'));

    expect($mtimeAfter)->not->toEqual($mtimeBefore);
});

it('filters by --media with individual IDs', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->useName('a')->upload();
    MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->useName('b')->upload();

    $out = captureGenerateConversionsOutput([
        '--conversion' => 'thumb',
        '--force' => true,
        '--media' => (string) $m1->id,
    ]);

    expect($out)->toContain('Media items', 'Processed', '1');
});

it('filters by --media with range syntax', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->useName('a')->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->useName('b')->upload();
    $m3 = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->useName('c')->upload();

    $out = captureGenerateConversionsOutput([
        '--conversion' => 'thumb',
        '--force' => true,
        '--media' => "{$m2->id}..{$m3->id}",
    ]);

    expect($out)->toContain('Media items', 'Processed', '2');
});

it('filters by --media with mixed IDs and ranges', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    $m1 = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->useName('a')->upload();
    $m2 = MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->useName('b')->upload();
    $m3 = MediaUploader::source(UploadedFile::fake()->image('c.jpg'))->useName('c')->upload();

    $out = captureGenerateConversionsOutput([
        '--conversion' => 'thumb',
        '--force' => true,
        '--media' => "{$m1->id},{$m2->id}..{$m3->id}",
    ]);

    expect($out)->toContain('Media items', 'Processed', '3');
});

it('shows message when no media items found', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    $this->artisan('mediaman:generate-conversions', [
        '--conversion' => 'thumb',
        '--media' => '9999',
    ])
        ->expectsOutputToContain('No media items found')
        ->assertExitCode(0);
});

it('dispatches PerformConversions job when --queue is used', function () {
    Queue::fake();

    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $out = captureGenerateConversionsOutput([
        '--conversion' => 'thumb',
        '--queue' => true,
    ]);

    expect($out)->toContain('Dispatched', '1', 'queued');

    Queue::assertPushed(PerformConversions::class, 1);
});

it('filters by collection name', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    MediaUploader::source(UploadedFile::fake()->image('a.jpg'))
        ->useCollection('Blog')->upload();
    MediaUploader::source(UploadedFile::fake()->image('b.jpg'))->upload();

    $out = captureGenerateConversionsOutput([
        '--conversion' => 'thumb',
        '--collection' => 'Blog',
        '--force' => true,
    ]);

    expect($out)->toContain('Media items', 'Processed', '1');
});

it('handles multiple conversion names', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });
    Conversion::register('cover', function ($image) {
        return $image->resize(800, 600)->encodeUsingFormat(Format::JPEG);
    });

    MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $out = captureGenerateConversionsOutput([
        '--conversion' => 'thumb,cover',
        '--force' => true,
    ]);

    expect($out)->toContain('Conversions', 'thumb, cover', 'Processed', '1');
});

it('fails with invalid --media range (from > to)', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    $this->artisan('mediaman:generate-conversions', [
        '--conversion' => 'thumb',
        '--media' => '5..1',
    ])
        ->expectsOutputToContain('Invalid --media value')
        ->assertExitCode(1);
});
