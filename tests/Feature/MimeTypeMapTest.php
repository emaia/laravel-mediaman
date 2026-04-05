<?php

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Models\Media;

// --- P1: Shared MIME type map ---

it('resolves standard mime types to extensions via MediaFormat', function () {
    expect(MediaFormat::extensionFromMimeType('image/jpeg'))->toBe('jpg')
        ->and(MediaFormat::extensionFromMimeType('image/png'))->toBe('png')
        ->and(MediaFormat::extensionFromMimeType('image/webp'))->toBe('webp')
        ->and(MediaFormat::extensionFromMimeType('image/avif'))->toBe('avif')
        ->and(MediaFormat::extensionFromMimeType('image/gif'))->toBe('gif')
        ->and(MediaFormat::extensionFromMimeType('image/svg+xml'))->toBe('svg');
});

it('resolves alternative mime types to extensions via MediaFormat', function () {
    expect(MediaFormat::extensionFromMimeType('image/x-ms-bmp'))->toBe('bmp')
        ->and(MediaFormat::extensionFromMimeType('image/vnd.adobe.photoshop'))->toBe('psd')
        ->and(MediaFormat::extensionFromMimeType('image/x-photoshop'))->toBe('psd')
        ->and(MediaFormat::extensionFromMimeType('image/x-windows-bmp'))->toBe('bmp');
});

it('falls back to jpg for unknown mime types', function () {
    expect(MediaFormat::extensionFromMimeType('image/unknown'))->toBe('jpg')
        ->and(MediaFormat::extensionFromMimeType('application/pdf'))->toBe('jpg');
});

it('Media delegates getExtensionFromMimeType to MediaFormat', function () {
    $media = new Media;

    expect($media->getExtensionFromMimeType('image/webp'))->toBe('webp')
        ->and($media->getExtensionFromMimeType('image/heic'))->toBe('heic');
});
