<?php

use Emaia\MediaMan\Exceptions\TemporaryUrlNotSupported;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $this->media = MediaUploader::source($file)->upload();
});

// --- toResponse ---

it('returns a StreamedResponse with content-disposition header', function () {
    $response = $this->media->toResponse();

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    $headers = $response->headers;

    expect($headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('photo.jpg');
});

it('toResponse streams the file content', function () {
    $response = $this->media->toResponse();

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->not->toBeEmpty();
});

// --- toInlineResponse ---

it('returns an inline StreamedResponse', function () {
    $response = $this->media->toInlineResponse();

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->not->toContain('attachment');
});

it('toInlineResponse uses the file MIME type', function () {
    $mime = $this->media->mime_type;

    $response = $this->media->toInlineResponse();

    expect($response->headers->get('Content-Type'))->toContain($mime);
});

// --- getStream ---

it('returns a stream resource for the file', function () {
    $stream = $this->media->getStream();

    expect($stream)->toBeResource();

    $contents = stream_get_contents($stream);
    fclose($stream);

    expect($contents)->not->toBeEmpty();
});

// --- getTemporaryUrl ---

it('throws TemporaryUrlNotSupported on a local disk', function () {
    $tmpDir = sys_get_temp_dir().'/mediaman_test_'.uniqid();
    mkdir($tmpDir);

    Config::set('filesystems.disks.tmp-test', [
        'driver' => 'local',
        'root' => $tmpDir,
    ]);

    $media = new Media;
    $media->name = 'test';
    $media->file_name = 'test.jpg';
    $media->disk = 'tmp-test';
    $media->mime_type = 'image/jpeg';
    $media->size = 100;

    try {
        $media->getTemporaryUrl();
    } finally {
        array_map('unlink', glob("$tmpDir/*.*") ?: []);
        rmdir($tmpDir);
    }
})->throws(TemporaryUrlNotSupported::class);

it('getTemporaryUrl accepts an explicit expiration', function () {
    $tmpDir = sys_get_temp_dir().'/mediaman_test_'.uniqid();
    mkdir($tmpDir);

    Config::set('filesystems.disks.tmp-test', [
        'driver' => 'local',
        'root' => $tmpDir,
    ]);

    $media = new Media;
    $media->name = 'test';
    $media->file_name = 'test.jpg';
    $media->disk = 'tmp-test';
    $media->mime_type = 'image/jpeg';
    $media->size = 100;

    try {
        $media->getTemporaryUrl(now()->addHour());
    } finally {
        array_map('unlink', glob("$tmpDir/*.*") ?: []);
        rmdir($tmpDir);
    }
})->throws(TemporaryUrlNotSupported::class);

// --- mailAttachment ---

it('returns an Attachment object', function () {
    $attachment = $this->media->mailAttachment();

    expect($attachment)->toBeInstanceOf(Attachment::class);
});

it('mailAttachment uses the media file_name as attachment name', function () {
    $attachment = $this->media->mailAttachment();

    expect($attachment->as)->toEqual($this->media->file_name);
});

it('mailAttachment uses the media mime_type', function () {
    $attachment = $this->media->mailAttachment();

    expect($attachment->mime)->toEqual($this->media->mime_type);
});

// --- Attachable interface (toMailAttachment) ---

it('implements the Attachable interface via toMailAttachment', function () {
    $attachment = $this->media->toMailAttachment();

    expect($attachment)->toBeInstanceOf(Attachment::class)
        ->and($attachment->as)->toEqual($this->media->file_name)
        ->and($attachment->mime)->toEqual($this->media->mime_type);
});
