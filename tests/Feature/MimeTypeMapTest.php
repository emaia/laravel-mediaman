<?php

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Models\Media;

it('resolves standard mime types to canonical extensions via Symfony MimeTypes', function () {
    expect(MediaFormat::extensionFromMimeType('image/jpeg'))->toBe('jpg')
        ->and(MediaFormat::extensionFromMimeType('image/png'))->toBe('png')
        ->and(MediaFormat::extensionFromMimeType('image/webp'))->toBe('webp')
        ->and(MediaFormat::extensionFromMimeType('image/avif'))->toBe('avif')
        ->and(MediaFormat::extensionFromMimeType('image/gif'))->toBe('gif')
        ->and(MediaFormat::extensionFromMimeType('image/svg+xml'))->toBe('svg');
});

it('resolves non-image mime types canonically (covering doc / archive / video)', function () {
    expect(MediaFormat::extensionFromMimeType('application/pdf'))->toBe('pdf')
        ->and(MediaFormat::extensionFromMimeType('application/zip'))->toBe('zip')
        ->and(MediaFormat::extensionFromMimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBe('xlsx')
        ->and(MediaFormat::extensionFromMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBe('docx')
        ->and(MediaFormat::extensionFromMimeType('video/mp4'))->toBe('mp4');
});

it('returns null for unknown mime types instead of silent jpg fallback', function () {
    expect(MediaFormat::extensionFromMimeType('image/unknown'))->toBeNull()
        ->and(MediaFormat::extensionFromMimeType('application/x-foobar'))->toBeNull();
});

it('Media delegates getExtensionFromMimeType to MediaFormat (nullable)', function () {
    $media = new Media;

    expect($media->getExtensionFromMimeType('image/webp'))->toBe('webp')
        ->and($media->getExtensionFromMimeType('image/heic'))->toBe('heic')
        ->and($media->getExtensionFromMimeType('image/unknown'))->toBeNull();
});
