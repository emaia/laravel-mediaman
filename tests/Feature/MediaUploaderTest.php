<?php

use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Exceptions\MediaFileWriteFailed;
use Emaia\MediaMan\Exceptions\MediaNotAcceptedByCollection;
use Emaia\MediaMan\Exceptions\MimeTypeNotAllowed;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Models\MediaCollection;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

it('can upload a file to the default disk', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->disk)->toEqual(self::DEFAULT_DISK);

    $filesystem = Storage::disk(self::DEFAULT_DISK);

    expect($filesystem->exists($media->getPath()))->toBeTrue();
});

it('can upload a file to a specific disk', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $customDisk = 'custom';

    // Create a test filesystem for the custom disk...
    Storage::fake($customDisk);

    $media = MediaUploader::source($file)
        ->setDisk($customDisk)
        ->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->disk)->toEqual($customDisk);

    $filesystem = Storage::disk($customDisk);

    expect($filesystem->exists($media->getPath()))->toBeTrue();
});

it('can change the name of the media model', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $media = MediaUploader::source($file)
        ->useName($newName = 'New name')
        ->upload();

    expect($media->name)->toEqual($newName);
});

it('can rename the file before it gets uploaded', function () {
    $file = UploadedFile::fake()->image('file-name.jpg');

    $media = MediaUploader::source($file)
        ->useFileName($newFileName = 'new-file-name.jpg')
        ->upload();

    expect($media->file_name)->toEqual($newFileName);
});

it('will sanitize the file name', function () {
    $file = UploadedFile::fake()->image('bad file name#023.jpg');

    $media = MediaUploader::source($file)->upload();

    expect($media->file_name)->toEqual('bad-file-name-023.jpg');
});

it('sanitizes directory traversal attempts from file name', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->useFileName('../../etc/passwd')
        ->upload();

    expect($media->file_name)->not->toContain('..')
        ->not->toContain('/');
});

it('strips null bytes from file name', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->useFileName("file\0name.jpg")
        ->upload();

    expect($media->file_name)->toEqual('filename.jpg');
});

it('sanitizes double extensions', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->useFileName('malware.php.jpg')
        ->upload();

    expect($media->file_name)->toEqual('malware-php.jpg');
});

it('sanitization defuses double-extension before validation runs', function () {
    $media = MediaUploader::source(UploadedFile::fake()->create('legit.php.jpg', 100))->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->file_name)->not->toContain('.php')
        ->and($media->file_name)->toEndWith('.jpg');
});

it('strips unicode control characters from file name', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->useFileName("file\xE2\x80\x8Bname.jpg")  // zero-width space
        ->upload();

    expect($media->file_name)->toEqual('filename.jpg');
});

it('sanitizes dangerous characters from file name', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->useFileName('file<script>:.jpg')
        ->upload();

    expect($media->file_name)->not->toContain('<')
        ->not->toContain('>')
        ->not->toContain(':');
});

it('uses fallback name when filename becomes empty after sanitization', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->useFileName('###.jpg')
        ->upload();

    expect($media->file_name)->toEqual('unnamed.jpg');
});

it('can save data to the media model', function () {
    $file = UploadedFile::fake()->image('image.jpg');

    $media = MediaUploader::source($file)
        ->withCustomProperties([
            'test-01' => 'test data 01',
            'test-02' => 'test data 02',
        ])
        ->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->custom_properties['test-01'])->toEqual('test data 01')
        ->and($media->custom_properties['test-02'])->toEqual('test data 02');
});

// --- MIME Type Validation ---

it('uploads when no mime type restrictions are configured', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('uploads when the file mime type is in the allowed list', function () {
    Config::set('mediaman.allowed_mime_types', ['image/jpeg', 'image/png']);

    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('throws exception when the file mime type is not allowed by config', function () {
    Config::set('mediaman.allowed_mime_types', ['application/pdf']);

    MediaUploader::source(UploadedFile::fake()->image('file.jpg'))->upload();
})->throws(MimeTypeNotAllowed::class);

it('supports wildcard mime type patterns', function () {
    Config::set('mediaman.allowed_mime_types', ['image/*']);

    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('rejects files not matching wildcard mime type pattern', function () {
    Config::set('mediaman.allowed_mime_types', ['application/*']);

    MediaUploader::source(UploadedFile::fake()->image('file.jpg'))->upload();
})->throws(MimeTypeNotAllowed::class);

it('allows per-upload mime type override via fluent method', function () {
    Config::set('mediaman.allowed_mime_types', ['application/pdf']);

    $media = MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->allowMimeTypes(['image/jpeg', 'image/png'])
        ->upload();

    expect($media)->toBeInstanceOf(Media::class);
});

it('rejects files when per-upload mime types do not match', function () {
    MediaUploader::source(UploadedFile::fake()->image('file.jpg'))
        ->allowMimeTypes(['application/pdf'])
        ->upload();
})->throws(MimeTypeNotAllowed::class);

it('does not save to database when mime type validation fails', function () {
    Config::set('mediaman.allowed_mime_types', ['application/pdf']);

    try {
        MediaUploader::source(UploadedFile::fake()->image('file.jpg'))->upload();
    } catch (MimeTypeNotAllowed) {
        // expected
    }

    expect(Media::count())->toBe(0);
});

it('does not store file to disk when mime type validation fails', function () {
    Config::set('mediaman.allowed_mime_types', ['application/pdf']);

    try {
        MediaUploader::source(UploadedFile::fake()->image('file.jpg'))->upload();
    } catch (MimeTypeNotAllowed) {
        // expected
    }

    expect(Storage::disk(self::DEFAULT_DISK)->allFiles())->toBeEmpty();
});

// --- Atomic upload (transaction + cleanup) ---

afterEach(function () {
    Mockery::close();
});

it('rolls back the media row when the file write throws', function () {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('putFileAs')->andThrow(new RuntimeException('write boom'));
    $filesystem->shouldReceive('deleteDirectory')->andReturn(true);
    $filesystem->shouldNotReceive('delete');

    Storage::shouldReceive('disk')->andReturn($filesystem);

    expect(fn () => MediaUploader::source(UploadedFile::fake()->create('file.txt', 10))->upload())
        ->toThrow(RuntimeException::class, 'write boom');

    expect(Media::count())->toBe(0);
});

it('treats a false return from the file write as a failure and rolls back', function () {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('putFileAs')->andReturn(false);
    $filesystem->shouldReceive('deleteDirectory')->andReturn(true);

    Storage::shouldReceive('disk')->andReturn($filesystem);

    expect(fn () => MediaUploader::source(UploadedFile::fake()->create('file.txt', 10))->upload())
        ->toThrow(MediaFileWriteFailed::class);

    expect(Media::count())->toBe(0);
});

it('rolls back and cleans the written file when collection validation rejects the media', function () {
    MediaCollection::create([
        'name' => 'pdf-only',
        'allowed_mime_types' => ['application/pdf'],
    ]);

    $file = UploadedFile::fake()->image('photo.jpg');

    expect(fn () => MediaUploader::source($file)->useCollection('pdf-only')->upload())
        ->toThrow(MediaNotAcceptedByCollection::class);

    expect(Media::count())->toBe(0)
        ->and(Storage::disk(self::DEFAULT_DISK)->allFiles())->toBeEmpty();
});

it('rolls back and cleans the written file when the collection attach fails', function () {
    Config::set('mediaman.tables.collection_media', 'missing_pivot_table');

    $file = UploadedFile::fake()->image('photo.jpg');

    expect(fn () => MediaUploader::source($file)->useCollection('gallery')->upload())
        ->toThrow(QueryException::class);

    expect(Media::count())->toBe(0)
        ->and(Storage::disk(self::DEFAULT_DISK)->allFiles())->toBeEmpty();
});

it('persists the row, file, and collection on a successful upload', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect(Media::count())->toBe(1)
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath()))->toBeTrue()
        ->and($media->collections->pluck('name'))->toContain(config('mediaman.collection'));
});

it('fires MediaUploaded only after the media row is persisted', function () {
    $persistedAtDispatch = null;

    Event::listen(MediaUploaded::class, function (MediaUploaded $event) use (&$persistedAtDispatch) {
        $persistedAtDispatch = Media::find($event->media->getKey()) !== null;
    });

    MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    expect($persistedAtDispatch)->toBeTrue();
});

it('does not roll back a committed upload when inline responsive generation fails', function () {
    Config::set('mediaman.responsive_images.queue', false);

    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('generateResponsiveImages')->andThrow(new RuntimeException('responsive boom'));
    app()->instance(ResponsiveImageGenerator::class, $generator);

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))
        ->generateResponsive()
        ->upload();

    expect($media)->toBeInstanceOf(Media::class)
        ->and(Media::count())->toBe(1)
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath()))->toBeTrue();
});
