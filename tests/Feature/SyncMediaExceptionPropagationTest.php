<?php

use Emaia\MediaMan\Exceptions\MediaNotAcceptedByCollection;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Tests\Models\ThrowingSubject;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

afterEach(function () {
    ThrowingSubject::$throwOnGetMedia = null;
});

function freshThrowingSubject(): ThrowingSubject
{
    $subject = new ThrowingSubject;
    $subject->save();

    return $subject;
}

it('rethrows InvalidArgumentException from syncMedia without logging', function () {
    $subject = freshThrowingSubject();
    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();

    ThrowingSubject::$throwOnGetMedia = new InvalidArgumentException('bad channel state');

    Log::spy();

    expect(fn () => $subject->attachMedia($media->getKey()))
        ->toThrow(InvalidArgumentException::class, 'bad channel state');

    Log::shouldNotHaveReceived('warning');
});

it('rethrows MediaNotAcceptedByCollection from syncMedia without logging', function () {
    $subject = freshThrowingSubject();
    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();

    ThrowingSubject::$throwOnGetMedia = MediaNotAcceptedByCollection::mimeTypeNotAllowed('image/jpeg', 'strict');

    Log::spy();

    expect(fn () => $subject->attachMedia($media->getKey()))
        ->toThrow(MediaNotAcceptedByCollection::class);

    Log::shouldNotHaveReceived('warning');
});

it('rethrows QueryException from syncMedia without logging', function () {
    $subject = freshThrowingSubject();
    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();

    ThrowingSubject::$throwOnGetMedia = new QueryException(
        'sqlite',
        'select 1',
        [],
        new RuntimeException('simulated deadlock'),
    );

    Log::spy();

    expect(fn () => $subject->attachMedia($media->getKey()))
        ->toThrow(QueryException::class);

    Log::shouldNotHaveReceived('warning');
});

it('still swallows generic Throwable from syncMedia and logs a warning', function () {
    $subject = freshThrowingSubject();
    $media = MediaUploader::source(UploadedFile::fake()->image('a.jpg'))->upload();

    ThrowingSubject::$throwOnGetMedia = new RuntimeException('unexpected failure');

    Log::spy();

    $result = $subject->attachMedia($media->getKey());

    expect($result)->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) =>
            str_contains($message, 'Failed to sync media')
            && $context['error'] === 'unexpected failure'
        )
        ->once();
});
