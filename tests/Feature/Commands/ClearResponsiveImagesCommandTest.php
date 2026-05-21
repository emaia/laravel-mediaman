<?php

use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Illuminate\Http\UploadedFile;

function uploadMediaWithResponsive(?string $collection = null): Media
{
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $uploader = MediaUploader::source($file);
    if ($collection) {
        $uploader->toCollection($collection);
    }
    $media = $uploader->upload();

    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'p', 'url' => '/p', 'size' => 1000],
    ]);
    $media->save();

    return $media;
}

it('shows message when no media with responsive images found', function () {
    $this->artisan('mediaman:clear-responsive', ['--confirm' => true])
        ->expectsOutputToContain('No media items with responsive images found')
        ->assertExitCode(0);
});

it('shows no items even with media that has no responsive images', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    MediaUploader::source($file)->upload();

    $this->artisan('mediaman:clear-responsive', ['--confirm' => true])
        ->expectsOutputToContain('No media items with responsive images found')
        ->assertExitCode(0);
});

it('cancels when user does not confirm', function () {
    $file = UploadedFile::fake()->image('test.jpg', 800, 600);
    $media = MediaUploader::source($file)->upload();

    // Manually set responsive images data
    $media->setCustomProperty(Media::PROPERTY_RESPONSIVE_IMAGES, [
        ['width' => 320, 'height' => 240, 'format' => 'webp', 'path' => 'test', 'url' => '/test', 'size' => 1000],
    ]);
    $media->save();

    $this->artisan('mediaman:clear-responsive')
        ->expectsConfirmation(
            'This will clear responsive images for 1 media items. Continue?',
            'no'
        )
        ->expectsOutputToContain('Operation cancelled')
        ->assertExitCode(0);
});

it('filters by media id', function () {
    $this->artisan('mediaman:clear-responsive', ['--media' => 9999, '--confirm' => true])
        ->expectsOutputToContain('No media items with responsive images found')
        ->assertExitCode(0);
});

it('clears responsive images with --confirm flag', function () {
    $media = uploadMediaWithResponsive();

    $this->artisan('mediaman:clear-responsive', ['--confirm' => true])
        ->expectsOutputToContain('Clearing responsive images for 1 media items')
        ->expectsOutputToContain('Cleared: '.$media->name)
        ->expectsOutputToContain('Responsive images clearing completed')
        ->assertExitCode(0);

    expect($media->fresh()->hasResponsiveImages())->toBeFalse();
});

it('proceeds when user confirms interactively', function () {
    $media = uploadMediaWithResponsive();

    $this->artisan('mediaman:clear-responsive')
        ->expectsConfirmation(
            'This will clear responsive images for 1 media items. Continue?',
            'yes'
        )
        ->expectsOutputToContain('Cleared: '.$media->name)
        ->assertExitCode(0);

    expect($media->fresh()->hasResponsiveImages())->toBeFalse();
});

it('filters by collection name', function () {
    $matched = uploadMediaWithResponsive('targeted');
    $other = uploadMediaWithResponsive('other');

    $this->artisan('mediaman:clear-responsive', [
        '--collection' => 'targeted',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('Clearing responsive images for 1 media items')
        ->expectsOutputToContain('Cleared: '.$matched->name)
        ->assertExitCode(0);

    expect($matched->fresh()->hasResponsiveImages())->toBeFalse()
        ->and($other->fresh()->hasResponsiveImages())->toBeTrue();
});

it('filters by a specific media id and clears only that one', function () {
    $first = uploadMediaWithResponsive();
    $second = uploadMediaWithResponsive();

    $this->artisan('mediaman:clear-responsive', [
        '--media' => $second->id,
        '--confirm' => true,
    ])
        ->expectsOutputToContain('Cleared: '.$second->name)
        ->assertExitCode(0);

    expect($first->fresh()->hasResponsiveImages())->toBeTrue()
        ->and($second->fresh()->hasResponsiveImages())->toBeFalse();
});

it('continues processing when an individual clear fails', function () {
    $media = uploadMediaWithResponsive();

    $generator = Mockery::mock(ResponsiveImageGenerator::class);
    $generator->shouldReceive('clearResponsiveImages')
        ->andThrow(new RuntimeException('disk error'));
    app()->instance(ResponsiveImageGenerator::class, $generator);

    $this->artisan('mediaman:clear-responsive', ['--confirm' => true])
        ->expectsOutputToContain('Failed: '.$media->name.' - disk error')
        ->assertExitCode(0);
});
