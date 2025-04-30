<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Console\Commands\MediamanPublishConfigCommand;
use Emaia\MediaMan\Console\Commands\MediamanPublishMigrationCommand;
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
            __DIR__.'/../config/mediaman.php',
            'mediaman'
        );

        $this->app->singleton(ConversionRegistry::class);
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
            __DIR__.'/../config/mediaman.php' => config_path('mediaman.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MediamanPublishConfigCommand::class,
                MediamanPublishMigrationCommand::class,
            ]);
        }
    }
}
