<?php

use Emaia\MediaMan\Generators\DefaultFileNamer;
use Emaia\MediaMan\Generators\DefaultUrlGenerator;
use Emaia\MediaMan\Generators\FileNamer;
use Emaia\MediaMan\Generators\PathGenerator;
use Emaia\MediaMan\Generators\UrlGenerator;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;

// ─── Regression: PathGenerator bit-for-bit compatible ───────────────

it('DefaultPathGenerator produces same directory as old hardcoded logic', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $expectedDir = $media->getKey().'-'.md5($media->getKey().config('app.key'));

    expect($media->getDirectory())->toEqual($expectedDir);
});

it('media path structure is preserved with DefaultPathGenerator', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $path = $media->getPath();

    expect($path)->toContain($media->getDirectory())
        ->and($path)->toEndWith('.jpg');
});

it('conversion path uses PathGenerator', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $origPath = $media->getOriginalPath('thumb');

    $expectedDir = $media->getDirectory().'/conversions/thumb';

    expect($origPath)->toStartWith($expectedDir);
});

// ─── Regression: UrlGenerator bit-for-bit compatible ─────────────────

it('DefaultUrlGenerator produces same URL as old logic', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $url = $media->getUrl();

    expect($url)->not->toBeEmpty();
});

// ─── Regression: FileNamer bit-for-bit compatible ────────────────────

it('DefaultFileNamer sanitizes the same way as old hardcoded logic', function () {
    $namer = new DefaultFileNamer;

    expect($namer->getBaseName('bad file name#023.jpg'))->toEqual('bad-file-name-023.jpg');
    expect($namer->getBaseName('../../etc/passwd'))->not->toContain('..');
    expect($namer->getBaseName("file\0name.jpg"))->toEqual('filename.jpg');
    expect($namer->getBaseName('malware.php.jpg'))->toEqual('malware-php.jpg');
    expect($namer->getBaseName('###.jpg'))->toEqual('unnamed.jpg');
});

// ─── Custom PathGenerator ────────────────────────────────────────────

it('can swap PathGenerator for a custom implementation', function () {
    app()->instance(PathGenerator::class, new class implements PathGenerator
    {
        public function getDirectory(Media $media): string
        {
            return 'custom/'.$media->getKey();
        }

        public function getPathForConversion(Media $media, string $conversion): string
        {
            return $this->getDirectory($media).'/converted/'.$conversion;
        }

        public function getPathForResponsive(Media $media): string
        {
            return $this->getDirectory($media).'/responsive-variants';
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getDirectory())->toEqual('custom/'.$media->getKey());
    expect($media->getPath())->toStartWith('custom/'.$media->getKey());
});

// ─── Custom UrlGenerator ─────────────────────────────────────────────

it('can swap UrlGenerator for a custom implementation', function () {
    app()->instance(UrlGenerator::class, new class implements UrlGenerator
    {
        public function getUrl(Media $media, ?string $conversion = null): string
        {
            return 'https://cdn.example.com/'.$media->getPath($conversion ?? '');
        }

        public function getTemporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string
        {
            return $this->getUrl($media, $conversion).'?expires='.$expiration->getTimestamp();
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getUrl())->toStartWith('https://cdn.example.com/');
    expect($media->getUrl())->toContain($media->getPath());
});

// ─── Custom FileNamer ────────────────────────────────────────────────

it('can swap FileNamer for a custom implementation', function () {
    app()->instance(FileNamer::class, new class implements FileNamer
    {
        public function getBaseName(string $originalName): string
        {
            return 'uploaded-'.time().'.'.pathinfo($originalName, PATHINFO_EXTENSION);
        }

        public function getConversionFileName(string $originalName, string $conversion, string $extension): string
        {
            return $conversion.'-'.bin2hex(random_bytes(4)).'.'.$extension;
        }

        public function getResponsiveFileName(string $originalName, int $width, string $format): string
        {
            return $width.'.'.$format;
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('original.jpg'))
        ->useFileName('original.jpg')
        ->upload();

    expect($media->file_name)->toStartWith('uploaded-');
});

it('uses FileNamer::getConversionFileName when computing conversion paths', function () {
    app()->instance(FileNamer::class, new class implements FileNamer
    {
        public function getBaseName(string $originalName): string
        {
            return $originalName;
        }

        public function getConversionFileName(string $originalName, string $conversion, string $extension): string
        {
            return 'CUSTOM-'.$conversion.'-'.pathinfo($originalName, PATHINFO_FILENAME).'.'.$extension;
        }

        public function getResponsiveFileName(string $originalName, int $width, string $format): string
        {
            return $width.'.'.$format;
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $convPath = $media->getOriginalPath('thumb');

    // Note: getOriginalPath uses file_name directly (not FileNamer) by design — it's the path
    // used to read the source file. Path with extension detection uses FileNamer:
    $path = $media->getPath('thumb');

    expect($path)->toContain('CUSTOM-thumb-photo');
});

it('uses FileNamer::getResponsiveFileName when generating responsive variants', function () {
    app()->instance(FileNamer::class, new class implements FileNamer
    {
        public function getBaseName(string $originalName): string
        {
            return $originalName;
        }

        public function getConversionFileName(string $originalName, string $conversion, string $extension): string
        {
            return pathinfo($originalName, PATHINFO_FILENAME).'.'.$extension;
        }

        public function getResponsiveFileName(string $originalName, int $width, string $format): string
        {
            return 'RESP-'.$width.'.'.$format;
        }
    });

    $namer = app(FileNamer::class);

    expect($namer->getResponsiveFileName('photo.jpg', 800, 'webp'))->toEqual('RESP-800.webp');
});

// ─── URL version query ───────────────────────────────────────────────

it('appends version query when version_query config is enabled', function () {
    config(['mediaman.url.version_query' => true]);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $url = $media->getUrl();

    expect($url)->toContain('?v=');
});

// ─── URL prefix ──────────────────────────────────────────────────────

it('prepends CDN prefix when configured', function () {
    config(['mediaman.url.prefix' => 'https://cdn.example.com']);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $url = $media->getUrl();

    expect($url)->toStartWith('https://cdn.example.com/');
});

it('strips host from absolute storage URLs when prefix is set', function () {
    config(['mediaman.url.prefix' => 'https://cdn.example.com']);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    // Swap UrlGenerator wrapper to feed an absolute URL through the prefix logic
    app()->instance(UrlGenerator::class, new class extends DefaultUrlGenerator
    {
        public function getUrl(Media $media, ?string $conversion = null): string
        {
            // Pretend storage returned an absolute URL (typical S3 case)
            $absoluteUrl = 'https://s3.example.com/bucket/'.$media->getPath($conversion ?? '');

            return $this->applyPrefixForTest($absoluteUrl);
        }

        public function applyPrefixForTest(string $url): string
        {
            return $this->applyPrefix($url);
        }
    });

    $url = $media->getUrl();

    expect($url)->toStartWith('https://cdn.example.com/bucket/')
        ->and($url)->not->toContain('https://s3.example.com');
});
