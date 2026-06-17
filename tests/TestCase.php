<?php

namespace Emaia\MediaMan\Tests;

use Emaia\MediaMan\MediaManServiceProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;

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

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Clean any auto-generated migrations in Testbench skeleton
        $this->cleanTestbenchMigrations();

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
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Load migrations
        $app['migrator']->path(__DIR__.'/../database/migrations');
    }

    /**
     * Remove any *.php files from the Testbench skeleton's migrations dir.
     *
     * Tests for `mediaman:publish-migration` publish stub files into
     * `base_path('database/migrations')` (the Testbench fake-app directory).
     * RefreshDatabase auto-discovers files in that directory and would re-run
     * them on the next test, conflicting with `loadMigrationsFrom` and
     * `app['migrator']->path()` loaded above.
     *
     * Wiping the directory at setUp ensures isolation between tests.
     */
    protected function cleanTestbenchMigrations(): void
    {
        $dir = base_path('database/migrations');

        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*.php') as $file) {
            if (basename($file) !== '.gitkeep') {
                unlink($file);
            }
        }
    }
}
