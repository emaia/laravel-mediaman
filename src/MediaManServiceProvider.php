<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Console\Commands\ClearResponsiveImagesCommand;
use Emaia\MediaMan\Console\Commands\GenerateResponsiveImagesCommand;
use Emaia\MediaMan\Console\Commands\MediamanPublishConfigCommand;
use Emaia\MediaMan\Console\Commands\MediamanPublishMigrationCommand;
use Emaia\MediaMan\Console\Commands\ResponsiveImagesStatsCommand;
use Emaia\MediaMan\ResponsiveImages\ResponsiveConversions;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;

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

        $this->app->bind(MediaChannel::class);

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
                MediamanPublishConfigCommand::class,
                MediamanPublishMigrationCommand::class,
                GenerateResponsiveImagesCommand::class,
                ClearResponsiveImagesCommand::class,
                ResponsiveImagesStatsCommand::class,
            ]);
        }

        // Register responsive conversions if enabled
        if (config('mediaman.responsive_images.enabled', true)) {
            ResponsiveConversions::register();
        }
    }

    /**
     * Register Image Manager.
     */
    protected function registerImageManager(): void
    {
        $this->app->singleton(ImageManager::class, function () {
            return config('mediaman.driver') === 'gd'
                ? ImageManager::gd()
                : ImageManager::imagick();
        });
    }

    /**
     * Register responsive image-related services.
     */
    protected function registerResponsiveImageServices(): void
    {
        $this->app->bind('mediaman.width_calculator.breakpoint', function ($app) {
            return new BreakpointWidthCalculator(
                $app->make(ImageManager::class)
            );
        });

        $this->app->bind('mediaman.width_calculator.file_size_optimized', function ($app) {
            return new FileSizeOptimizedWidthCalculator(
                imageManager: $app->make(ImageManager::class)
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
