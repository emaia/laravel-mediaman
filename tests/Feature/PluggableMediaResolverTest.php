<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\DefaultMediaResolver;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Illuminate\Http\UploadedFile;

class CustomResolverForConfigSwap extends DefaultMediaResolver {}

// ─── Regression: DefaultMediaResolver bit-for-bit compatible ────────

it('DefaultMediaResolver produces same directory as v2 PathGenerator', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $expectedDir = $media->getKey().'-'.md5($media->getKey().config('app.key'));

    expect($media->getDirectory())->toEqual($expectedDir);
});

it('media path structure is preserved with DefaultMediaResolver', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $path = $media->getPath();

    expect($path)->toContain($media->getDirectory())
        ->and($path)->toEndWith('.jpg');
});

it('conversion path uses MediaResolver::pathForConversion', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    $origPath = $media->getOriginalPath('thumb');

    $expectedDir = $media->getDirectory().'/conversions/thumb';

    expect($origPath)->toStartWith($expectedDir);
});

it('DefaultMediaResolver produces a non-empty URL via the default URL pipeline', function () {
    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getUrl())->not->toBeEmpty();
});

it('DefaultMediaResolver sanitizes the same way as v2 FileNamer', function () {
    $resolver = new DefaultMediaResolver;

    expect($resolver->baseName('bad file name#023.jpg'))->toEqual('bad-file-name-023.jpg');
    expect($resolver->baseName('../../etc/passwd'))->not->toContain('..');
    expect($resolver->baseName("file\0name.jpg"))->toEqual('filename.jpg');
    expect($resolver->baseName('malware.php.jpg'))->toEqual('malware-php.jpg');
    expect($resolver->baseName('###.jpg'))->toEqual('unnamed.jpg');
});

// ─── Custom MediaResolver (extends + selective override) ────────────

it('can swap the path methods by extending DefaultMediaResolver', function () {
    app()->instance(MediaResolver::class, new class extends DefaultMediaResolver
    {
        public function directory(Media $media): string
        {
            return 'custom/'.$media->getKey();
        }

        public function pathForConversion(Media $media, string $conversion): string
        {
            return $this->directory($media).'/converted/'.$conversion;
        }

        public function pathForResponsive(Media $media): string
        {
            return $this->directory($media).'/responsive-variants';
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getDirectory())->toEqual('custom/'.$media->getKey())
        ->and($media->getPath())->toStartWith('custom/'.$media->getKey());
});

it('can swap the URL methods by extending DefaultMediaResolver', function () {
    app()->instance(MediaResolver::class, new class extends DefaultMediaResolver
    {
        public function url(Media $media, ?string $conversion = null): string
        {
            return 'https://cdn.example.com/'.$media->getPath($conversion ?? '');
        }

        public function temporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string
        {
            return $this->url($media, $conversion).'?expires='.$expiration->getTimestamp();
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getUrl())->toStartWith('https://cdn.example.com/')
        ->and($media->getUrl())->toContain($media->getPath());
});

it('can swap the filename methods by extending DefaultMediaResolver', function () {
    app()->instance(MediaResolver::class, new class extends DefaultMediaResolver
    {
        public function baseName(string $originalName): string
        {
            return 'uploaded-'.time().'.'.pathinfo($originalName, PATHINFO_EXTENSION);
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('original.jpg'))
        ->useFileName('original.jpg')
        ->upload();

    expect($media->file_name)->toStartWith('uploaded-');
});

it('overriding conversionFileName flows through to getPath', function () {
    app()->instance(MediaResolver::class, new class extends DefaultMediaResolver
    {
        public function conversionFileName(string $originalName, string $conversion, string $extension): string
        {
            return 'CUSTOM-'.$conversion.'-'.pathinfo($originalName, PATHINFO_FILENAME).'.'.$extension;
        }
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    // getOriginalPath uses file_name directly (not the resolver) — it's the read source.
    // getPath with a conversion uses the resolver, so the custom name lands here.
    $path = $media->getPath('thumb');

    expect($path)->toContain('CUSTOM-thumb-photo');
});

it('can be swapped via Config::set without rebinding the container', function () {
    // The bind is a lazy closure that reads config('mediaman.resolver') at
    // first resolve, so flipping the config value before the first resolve
    // is enough — no need to call app()->instance(...) like the older
    // generator binds required.
    config(['mediaman.resolver' => CustomResolverForConfigSwap::class]);

    expect(app(MediaResolver::class))->toBeInstanceOf(CustomResolverForConfigSwap::class);
});

it('overriding responsiveFileName changes the resolved name', function () {
    app()->instance(MediaResolver::class, new class extends DefaultMediaResolver
    {
        public function responsiveFileName(string $originalName, int $width, string $format): string
        {
            return 'RESP-'.$width.'.'.$format;
        }
    });

    expect(app(MediaResolver::class)->responsiveFileName('photo.jpg', 800, 'webp'))
        ->toEqual('RESP-800.webp');
});

// ─── URL versioning / prefix (DefaultMediaResolver internals) ───────

it('appends ?v= timestamp when url.versioning is "timestamp"', function () {
    config(['mediaman.url.versioning' => 'timestamp']);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getUrl())->toContain('?v=');
});

it('omits versioning when url.versioning is false (default)', function () {
    config(['mediaman.url.versioning' => false]);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getUrl())->not->toContain('?v=');
});

it('ignores the legacy version_query key — it must be migrated to versioning', function () {
    // Old apps with `version_query: true` must rename — the legacy key has no effect.
    config([
        'mediaman.url.versioning' => null,
        'mediaman.url.version_query' => true,
    ]);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getUrl())->not->toContain('?v=');
});

it('prepends CDN prefix when configured', function () {
    config(['mediaman.url.prefix' => 'https://cdn.example.com']);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    expect($media->getUrl())->toStartWith('https://cdn.example.com/');
});

it('strips host from absolute storage URLs when prefix is set', function () {
    config(['mediaman.url.prefix' => 'https://cdn.example.com']);

    $media = MediaUploader::source(UploadedFile::fake()->image('test.jpg'))->upload();

    // Subclass forces an absolute URL through the resolver's prefix logic
    // (typical S3 case) and re-applies the protected applyPrefix internal.
    app()->instance(MediaResolver::class, new class extends DefaultMediaResolver
    {
        public function url(Media $media, ?string $conversion = null): string
        {
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
