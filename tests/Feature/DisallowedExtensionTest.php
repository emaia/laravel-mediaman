<?php

use Emaia\MediaMan\Exceptions\DisallowedExtension;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

it('blocks uploads with default disallowed extension', function () {
    $file = UploadedFile::fake()->create('shell.php', 100);

    MediaUploader::source($file)->upload();
})->throws(DisallowedExtension::class);

it('blocks uploads with all default disallowed extensions', function (string $ext) {
    $file = UploadedFile::fake()->create("evil.{$ext}", 100);

    MediaUploader::source($file)->upload();
})->throws(DisallowedExtension::class)->with([
    'php', 'phtml', 'phar', 'shtml', 'htaccess',
    'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
]);

it('allows uploads with common safe extensions', function () {
    $file = UploadedFile::fake()->image('photo.jpg');

    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->toEndWith('.jpg');
});

it('blocks disallowed extensions case-insensitively', function () {
    $file = UploadedFile::fake()->create('shell.PHP', 100);

    MediaUploader::source($file)->upload();
})->throws(DisallowedExtension::class);

it('allows disallowed extensions when block_disallowed_extensions is false', function () {
    Config::set('mediaman.block_disallowed_extensions', false);

    $file = UploadedFile::fake()->create('shell.php', 100);

    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->toEndWith('.php');
});

it('allows all extensions when disallowed_extensions list is empty', function () {
    Config::set('mediaman.disallowed_extensions', []);

    $file = UploadedFile::fake()->create('shell.php', 100);

    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('does not save media to database when extension is blocked', function () {
    $before = Media::count();

    try {
        MediaUploader::source(UploadedFile::fake()->create('shell.php', 100))->upload();
    } catch (DisallowedExtension) {
        // expected
    }

    expect(Media::count())->toEqual($before);
});

it('does not store file to disk when extension is blocked', function () {
    try {
        MediaUploader::source(UploadedFile::fake()->create('shell.php', 100))->upload();
    } catch (DisallowedExtension) {
        // expected
    }

    expect(Storage::disk(self::DEFAULT_DISK)->allFiles())->toBeEmpty();
});

it('blocks disallowed extension when set via useFileName', function () {
    $file = UploadedFile::fake()->image('photo.jpg');

    MediaUploader::source($file)
        ->useFileName('malware.php')
        ->upload();
})->throws(DisallowedExtension::class);

it('blocks bare dotfile like .htaccess', function () {
    MediaUploader::source(UploadedFile::fake()->create('.htaccess', 100))->upload();
})->throws(DisallowedExtension::class);

it('includes the extension in the exception message', function () {
    try {
        MediaUploader::source(UploadedFile::fake()->create('evil.php', 100))->upload();
        $this->fail('Expected DisallowedExtension to be thrown');
    } catch (DisallowedExtension $e) {
        expect($e->getMessage())->toContain('php');
    }
});
