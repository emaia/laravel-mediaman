<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Console\Commands\CleanCommand;
use Emaia\MediaMan\Console\Commands\ClearConversionsCommand;
use Emaia\MediaMan\Console\Commands\ClearResponsiveImagesCommand;
use Emaia\MediaMan\Console\Commands\DoctorCommand;
use Emaia\MediaMan\Console\Commands\GenerateConversionsCommand;
use Emaia\MediaMan\Console\Commands\GenerateResponsiveImagesCommand;
use Emaia\MediaMan\Console\Commands\PublishCommand;
use Emaia\MediaMan\Console\Commands\PublishConfigCommand;
use Emaia\MediaMan\Console\Commands\PublishMigrationCommand;
use Emaia\MediaMan\Console\Commands\RotatePathsCommand;
use Emaia\MediaMan\Console\Commands\StatsCommand;
use Emaia\MediaMan\Downloaders\Downloader;
use Emaia\MediaMan\Downloaders\HttpDownloader;
use Emaia\MediaMan\Placeholders\PlaceholderGenerator;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;

class MediaManServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/mediaman.php',
            'mediaman'
        );

        $this->app->singleton(ConversionRegistry::class);

        $this->app->bind(Downloader::class, HttpDownloader::class);

        // Lazy closures so swapping these via Config::set takes effect without rebinding.
        $this->app->singleton(
            MediaResolver::class,
            fn ($app) => $app->make(config('mediaman.resolver'))
        );
        $this->app->singleton(
            PlaceholderGenerator::class,
            fn ($app) => $app->make(config('mediaman.placeholder.generator'))
        );

        $this->registerImageManager();
        $this->registerResponsiveImageServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Migrations
        $this->publishes([
            __DIR__.'/../database/migrations/create_mediaman_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_mediaman_tables.php'),
        ], 'migrations');

        // Config
        $this->publishes([
            __DIR__.'/../config/mediaman.php' => config_path('mediaman.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishCommand::class,
                PublishConfigCommand::class,
                PublishMigrationCommand::class,
                GenerateResponsiveImagesCommand::class,
                GenerateConversionsCommand::class,
                ClearResponsiveImagesCommand::class,
                ClearConversionsCommand::class,
                StatsCommand::class,
                CleanCommand::class,
                DoctorCommand::class,
                RotatePathsCommand::class,
            ]);
        }
    }

    /**
     * Register the image manager singleton.
     */
    protected function registerImageManager(): void
    {
        $this->app->singleton(ImageManager::class, function () {
            $driver = config('mediaman.driver') ?? $this->autoDetectImageDriver();

            return match ($driver) {
                // The vips driver lives in a separate Composer package
                // (intervention/image-driver-vips). Reference it via string so
                // the class isn't required at load time — it's only resolved
                // when the user actually opts in, and a missing package
                // surfaces as a clear class-not-found error.
                'vips' => ImageManager::usingDriver('Intervention\Image\Drivers\Vips\Driver'),
                'imagick' => ImageManager::usingDriver(ImagickDriver::class),
                'gd' => ImageManager::usingDriver(GdDriver::class),
                default => throw new InvalidArgumentException(
                    "Unsupported image driver [$driver]. Supported: \"vips\", \"imagick\", \"gd\"."
                ),
            };
        });
    }

    /**
     * Pick an image driver based on which PHP extensions are loaded and
     * which driver packages are installed. Prefers vips (highest throughput
     * via libvips), then imagick, then gd as the universal fallback.
     */
    protected function autoDetectImageDriver(): string
    {
        if ($this->canUseVips()) {
            return 'vips';
        }

        if (extension_loaded('imagick')) {
            return 'imagick';
        }

        return 'gd';
    }

    /**
     * Three gates before claiming vips is usable: PHP ext-vips loaded, the
     * intervention/image-driver-vips package installed, and a runtime probe
     * that catches a misconfigured libvips. The driver constructor itself
     * throws MissingDependencyException when libvips can't be reached, so
     * we wrap it and let auto-detect fall through to imagick/gd gracefully.
     * An explicit MEDIAMAN_DRIVER=vips still bubbles the original error —
     * we don't silence what the user asked for directly.
     */
    protected function canUseVips(): bool
    {
        if (! extension_loaded('vips')) {
            return false;
        }

        $driver = 'Intervention\Image\Drivers\Vips\Driver';

        if (! class_exists($driver)) {
            return false;
        }

        try {
            new $driver;

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Register responsive image-related services.
     */
    protected function registerResponsiveImageServices(): void
    {
        $this->app->bind('mediaman.width_calculator.breakpoint', function ($app) {
            return new BreakpointWidthCalculator(
                $app->make(ImageManager::class),
                config('mediaman.responsive_images.breakpoints')
            );
        });

        $this->app->bind('mediaman.width_calculator.file_size_optimized', function ($app) {
            return new FileSizeOptimizedWidthCalculator(
                $app->make(ImageManager::class)
            );
        });

        $this->app->bind(WidthCalculator::class, function ($app) {
            $calculator = config('mediaman.responsive_images.width_calculator', 'breakpoint');

            return match ($calculator) {
                'file_size_optimized' => $app->make('mediaman.width_calculator.file_size_optimized'),
                default => $app->make('mediaman.width_calculator.breakpoint'),
            };
        });

        $this->app->singleton(ResponsiveImageGenerator::class, function ($app) {
            return new ResponsiveImageGenerator(
                $app->make(ImageManager::class),
                $app->make(WidthCalculator::class)
            );
        });

        $this->app->singleton(ImageManipulator::class, function ($app) {
            return new ImageManipulator(
                $app->make(ConversionRegistry::class),
                $app->make(ImageManager::class)
            );
        });
    }
}
