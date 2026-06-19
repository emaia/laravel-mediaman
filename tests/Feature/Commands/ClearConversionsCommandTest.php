<?php

use Emaia\MediaMan\ConversionRegistry;
use Emaia\MediaMan\Facades\Conversion;
use Emaia\MediaMan\ImageManipulator;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Format;
use Symfony\Component\Console\Output\BufferedOutput;

function createMediaWithConversion(string $conversionName): Media
{
    $closure = match ($conversionName) {
        'thumb' => fn ($image) => $image->resize(200, 200)->encodeUsingFormat(Format::JPEG),
        'cover' => fn ($image) => $image->resize(800, 600)->encodeUsingFormat(Format::JPEG),
        default => fn ($image) => $image->resize(100, 100)->encodeUsingFormat(Format::JPEG),
    };

    if (! app(ConversionRegistry::class)->exists($conversionName)) {
        Conversion::register($conversionName, $closure);
    }

    $media = MediaUploader::source(UploadedFile::fake()->image("{$conversionName}.jpg", 800, 600))
        ->upload();

    app(ImageManipulator::class)->manipulate($media, [$conversionName], false);

    return $media;
}

function captureClearConversionsOutput(array $options = []): string
{
    $output = new BufferedOutput;
    Artisan::call('mediaman:clear-conversions', $options, $output);

    return $output->fetch();
}

it('exits with error when --conversion is missing', function () {
    $this->artisan('mediaman:clear-conversions')
        ->expectsOutputToContain('--conversion option is required')
        ->assertExitCode(1);
});

it('exits with error for unknown conversion names', function () {
    $this->artisan('mediaman:clear-conversions', ['--conversion' => 'nonexistent'])
        ->expectsOutputToContain('Unknown conversion')
        ->assertExitCode(1);
});

it('shows message when no media items found', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    $this->artisan('mediaman:clear-conversions', [
        '--conversion' => 'thumb',
        '--media' => '9999',
    ])
        ->expectsOutputToContain('No media items found')
        ->assertExitCode(0);
});

it('clears conversion files from disk', function () {
    $media = createMediaWithConversion('thumb');

    $convPath = $media->getPath('thumb');
    expect(Storage::disk(self::DEFAULT_DISK)->exists($convPath))->toBeTrue();

    $out = captureClearConversionsOutput([
        '--conversion' => 'thumb',
        '--force' => true,
    ]);
    expect($out)->toContain('Clear conversions', 'thumb', 'Cleared');

    $media->clearConversionFormatCache();

    expect(Storage::disk(self::DEFAULT_DISK)->exists($convPath))->toBeFalse();
});

it('clears without prompt when operation count is small', function () {
    $media = createMediaWithConversion('thumb');

    $convPath = $media->getPath('thumb');
    expect(Storage::disk(self::DEFAULT_DISK)->exists($convPath))->toBeTrue();

    $this->artisan('mediaman:clear-conversions', ['--conversion' => 'thumb'])
        ->expectsOutputToContain('Cleared')
        ->assertExitCode(0);

    $media->clearConversionFormatCache();
    expect(Storage::disk(self::DEFAULT_DISK)->exists($convPath))->toBeFalse();
});

it('reports skipped when conversion file does not exist', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });
    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();

    $this->artisan('mediaman:clear-conversions', [
        '--conversion' => 'thumb',
        '--media' => (string) $media->id,
        '--force' => true,
    ])
        ->expectsOutputToContain('Skipped (not found)')
        ->assertExitCode(0);
});

it('filters by --media with individual IDs', function () {
    $m1 = createMediaWithConversion('thumb');
    $m2 = createMediaWithConversion('thumb');

    $out = captureClearConversionsOutput([
        '--conversion' => 'thumb',
        '--media' => (string) $m1->id,
        '--force' => true,
    ]);
    expect($out)->toContain('Media items', 'Cleared');

    $m1->clearConversionFormatCache();
    $m2->clearConversionFormatCache();
    expect(Storage::disk(self::DEFAULT_DISK)->exists($m1->getPath('thumb')))->toBeFalse()
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($m2->getPath('thumb')))->toBeTrue();
});

it('filters by --media with range syntax', function () {
    $m1 = createMediaWithConversion('thumb');
    $m2 = createMediaWithConversion('thumb');
    $m3 = createMediaWithConversion('thumb');

    $out = captureClearConversionsOutput([
        '--conversion' => 'thumb',
        '--media' => "{$m1->id}..{$m2->id}",
        '--force' => true,
    ]);
    expect($out)->toContain('Media items', 'Cleared');

    $m3->clearConversionFormatCache();
    expect(Storage::disk(self::DEFAULT_DISK)->exists($m1->getPath('thumb')))->toBeFalse()
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($m2->getPath('thumb')))->toBeFalse()
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($m3->getPath('thumb')))->toBeTrue();
});

it('filters by collection name', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200)->encodeUsingFormat(Format::JPEG);
    });

    $matched = MediaUploader::source(UploadedFile::fake()->image('matched.jpg'))
        ->useCollection('Blog')->upload();
    app(ImageManipulator::class)->manipulate($matched, ['thumb'], false);

    $other = MediaUploader::source(UploadedFile::fake()->image('other.jpg'))->upload();
    app(ImageManipulator::class)->manipulate($other, ['thumb'], false);

    $out = captureClearConversionsOutput([
        '--conversion' => 'thumb',
        '--collection' => 'Blog',
        '--force' => true,
    ]);
    expect($out)->toContain('Cleared');

    $matched->clearConversionFormatCache();
    $other->clearConversionFormatCache();
    expect(Storage::disk(self::DEFAULT_DISK)->exists($matched->getPath('thumb')))->toBeFalse()
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($other->getPath('thumb')))->toBeTrue();
});

it('clears multiple conversions', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200)->encodeUsingFormat(Format::JPEG);
    });
    Conversion::register('cover', function ($image) {
        return $image->resize(800, 600)->encodeUsingFormat(Format::JPEG);
    });

    $media = MediaUploader::source(UploadedFile::fake()->image('photo.jpg'))->upload();
    $manipulator = app(ImageManipulator::class);
    $manipulator->manipulate($media, ['thumb', 'cover'], false);

    expect(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath('thumb')))->toBeTrue()
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath('cover')))->toBeTrue();

    $out = captureClearConversionsOutput([
        '--conversion' => 'thumb,cover',
        '--force' => true,
    ]);
    expect($out)->toContain('Conversions', 'thumb, cover', 'Cleared');

    $media->clearConversionFormatCache();
    expect(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath('thumb')))->toBeFalse()
        ->and(Storage::disk(self::DEFAULT_DISK)->exists($media->getPath('cover')))->toBeFalse();
});

it('fails gracefully with invalid --media range (from > to)', function () {
    Conversion::register('thumb', function ($image) {
        return $image->resize(200, 200);
    });

    $this->artisan('mediaman:clear-conversions', [
        '--conversion' => 'thumb',
        '--media' => '5..1',
    ])
        ->expectsOutputToContain('Invalid --media value')
        ->assertExitCode(1);
});
