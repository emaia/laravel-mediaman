<?php

namespace Emaia\MediaMan\Tests;

use Emaia\MediaMan\MediaManServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

class TestCase extends Orchestra
{
    const DEFAULT_DISK = 'default';

    protected $file;

    protected $fileOne;

    protected $fileTwo;

    protected $media;

    protected $mediaCollection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->withFactories(__DIR__ . '/database/factories');

        // Use a test disk as the default disk...
        Config::set('mediaman.disk', self::DEFAULT_DISK);

        // Create a test filesystem for the default disk...
        Storage::fake(self::DEFAULT_DISK);

        // Media & MediaCollection models
        $this->media = resolve(config('mediaman.models.media'));
        $this->mediaCollection = resolve(config('mediaman.models.collection'));

        // Fake uploaded files
        $this->fileOne = UploadedFile::fake()->image('file-one.jpg');
        $this->fileTwo = UploadedFile::fake()->image('file-two.jpg');
    }

    protected function getPackageProviders($app)
    {
        return [
            MediaManServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:Hupx3yAySikrM2/edkZQNQHslgDWYfiBfCuSThJ5SK8=');

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Load migrations
        $app['migrator']->path(__DIR__ . '/../database/migrations');
    }
}
