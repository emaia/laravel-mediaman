<?php

use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Intervention\Image\Format;
use Symfony\Component\Console\Output\BufferedOutput;

function captureStatsOutput(array $options = []): string
{
    $output = new BufferedOutput;
    Artisan::call('mediaman:stats', $options, $output);

    return $output->fetch();
}

it('shows consolidated stats with no flags', function () {
    $out = captureStatsOutput();
    expect($out)->toContain('Media inventory', 'Records', 'Total size', 'Image records');
    expect($out)->toContain('Conversions', 'Registered');
    expect($out)->toContain('Responsive images', 'Enabled', 'Auto generate');
});

it('shows responsive stats with --responsive flag', function () {
    $out = captureStatsOutput(['--responsive' => true]);
    expect($out)->toContain('Responsive images', 'Total images', 'With responsive', 'Without responsive');
    expect($out)->toContain('Configuration', 'Enabled', 'Auto generate', 'Queue', 'Quality', 'Formats', 'Breakpoints', 'Width calculator');
});

it('always shows media inventory regardless of flags', function () {
    expect(captureStatsOutput())->toContain('Media inventory', 'Records');
    expect(captureStatsOutput(['--responsive' => true]))->toContain('Media inventory', 'Records');
    expect(captureStatsOutput(['--conversions' => true]))->toContain('Media inventory', 'Records');
    expect(captureStatsOutput(['--responsive' => true, '--conversions' => true]))->toContain('Media inventory', 'Records');
});

it('shows conversion stats with --conversions flag', function () {
    $out = captureStatsOutput(['--conversions' => true]);
    expect($out)->toContain('Media inventory', 'Conversions', 'Registered');
});

it('shows all sections when both flags are used', function () {
    $out = captureStatsOutput(['--responsive' => true, '--conversions' => true]);
    expect($out)->toContain('Media inventory', 'Conversions', 'Registered', 'Responsive images', 'Total images', 'Configuration');
});

it('shows image counts in consolidated view with media present', function () {
    MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();
    MediaUploader::source(UploadedFile::fake()->image('test2.jpg'))->upload();

    $out = captureStatsOutput();
    expect($out)->toContain('Image records');
});

it('shows coverage percentage in consolidated view', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 1000],
    ]);
    $media->save();

    $out = captureStatsOutput();
    expect($out)->toContain('Coverage');
});

it('shows conversion names in consolidated view when conversions are registered', function () {
    Conversion::register('thumb', fn ($image) => $image);
    Conversion::register('cover', fn ($image) => $image);

    $out = captureStatsOutput();
    expect($out)->toContain('Names', 'thumb', 'cover');
});

it('shows conversion format details with --conversions flag', function () {
    Conversion::register('thumb', function ($image) {
        return $image->encodeUsingFormat(Format::JPEG);
    });

    $out = captureStatsOutput(['--conversions' => true]);
    expect($out)->toContain('thumb');
});

it('handles no conversions registered with --conversions flag', function () {
    $out = captureStatsOutput(['--conversions' => true]);
    expect($out)->toContain('Registered', 'no conversions registered');
});

it('shows coverage stats with --responsive flag', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 1000],
    ]);
    $media->save();

    $out = captureStatsOutput(['--responsive' => true]);
    expect($out)->toContain('Total images', 'With responsive', 'Without responsive', 'Coverage');
});
