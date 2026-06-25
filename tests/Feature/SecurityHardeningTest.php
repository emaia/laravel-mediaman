<?php

use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Emaia\MediaMan\MediaUploader;
use Illuminate\Http\UploadedFile;

// --- min_file_size hardening ---

it('rejects 0-byte uploads by default (min_file_size=1)', function () {
    // Empty file
    $tmp = tempnam(sys_get_temp_dir(), 'mm_empty_');
    $empty = new UploadedFile($tmp, 'ghost.bin', 'application/octet-stream', null, true);

    expect(fn () => MediaUploader::source($empty)->upload())
        ->toThrow(FileSizeExceeded::class, 'is below the minimum required 1 bytes');
});

it('accepts 0-byte uploads when min_file_size is set to 0', function () {
    config()->set('mediaman.min_file_size', 0);

    $tmp = tempnam(sys_get_temp_dir(), 'mm_empty_');
    $empty = new UploadedFile($tmp, 'placeholder.bin', 'application/octet-stream', null, true);

    $media = MediaUploader::source($empty)->upload();

    expect($media->size)->toBe(0);
});

it('rejects uploads below a configured min_file_size threshold', function () {
    config()->set('mediaman.min_file_size', 1024); // 1 KB

    $tmp = tempnam(sys_get_temp_dir(), 'mm_small_');
    file_put_contents($tmp, str_repeat('x', 500)); // 500 bytes — below threshold

    $file = new UploadedFile($tmp, 'small.txt', 'text/plain', null, true);

    expect(fn () => MediaUploader::source($file)->upload())
        ->toThrow(FileSizeExceeded::class, 'is below the minimum required 1024 bytes');
});

// --- Expanded disallowed_extensions blocklist (defense in depth) ---

it('blocks shell-script and Windows-executable extensions by default', function () {
    foreach (['sh', 'bat', 'exe', 'ps1', 'py', 'vbs'] as $ext) {
        $file = UploadedFile::fake()->create("payload.$ext", 10);

        expect(fn () => MediaUploader::source($file)->upload())
            ->toThrow(\Emaia\MediaMan\Exceptions\DisallowedExtension::class)
            ->and(true)->toBeTrue();
    }
});
