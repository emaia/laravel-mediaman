<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Console\Commands\MediamanPublishConfigCommand;
use Emaia\MediaMan\Console\Commands\MediamanPublishMigrationCommand;
use Emaia\MediaMan\ResponsiveImages\ResponsiveImageGenerator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\WidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\ResponsiveConversions;
use Illuminate\Support\ServiceProvider;

class MediaManServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mediaman.php',
            'mediaman'
        );

        $this->app->singleton(ConversionRegistry::class);

        $this->registerResponsiveImageServices();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_mediaman_tables.php.stub' =>
                database_path('migrations/' . date('Y_m_d_His', time()) . '_create_mediaman_tables.php')
        ], 'migrations');

        // Config
        $this->publishes([
            __DIR__ . '/../config/mediaman.php' => config_path('mediaman.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MediamanPublishConfigCommand::class,
                MediamanPublishMigrationCommand::class,
            ]);
        }

        // Register responsive conversions if enabled
        if (config('mediaman.responsive_images.enabled', true)) {
            ResponsiveConversions::register();
        }
    }

    /**
     * Register responsive image related services.
     */
    protected function registerResponsiveImageServices(): void
    {
        // Register width calculators
        $this->app->bind('mediaman.width_calculator.breakpoint', function () {
            return new BreakpointWidthCalculator();
        });

        $this->app->bind('mediaman.width_calculator.file_size_optimized', function () {
            return new FileSizeOptimizedWidthCalculator();
        });

        // Register width calculator based on config
        $this->app->bind(WidthCalculator::class, function ($app) {
            $calculator = config('mediaman.responsive_images.width_calculator', 'breakpoint');

            return match($calculator) {
                'file_size_optimized' => $app->make('mediaman.width_calculator.file_size_optimized'),
                default => $app->make('mediaman.width_calculator.breakpoint'),
            };
        });

        // Register responsive image generator
        $this->app->singleton(ResponsiveImageGenerator::class, function ($app) {
            return new ResponsiveImageGenerator(
                null, // ImageManager will use default
                $app->make(WidthCalculator::class)
            );
        });
    }
}