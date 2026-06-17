<?php

use Illuminate\Support\Facades\File;

it('publishes config and migration in one step', function () {
    $configPath = config_path('mediaman.php');
    $migrationsPath = database_path('migrations');

    File::ensureDirectoryExists($migrationsPath);

    if (File::exists($configPath)) {
        File::delete($configPath);
    }

    foreach (File::files($migrationsPath) as $file) {
        if (str_ends_with($file->getFilename(), '_create_mediaman_tables.php')) {
            File::delete($file->getPathname());
        }
    }

    $this->artisan('mediaman:publish')
        ->expectsOutputToContain('Published mediaman config file')
        ->expectsOutputToContain('Migration published to')
        ->assertExitCode(0);

    expect(File::exists($configPath))->toBeTrue();

    $migrationFound = false;
    foreach (File::files($migrationsPath) as $file) {
        if (str_ends_with($file->getFilename(), '_create_mediaman_tables.php')) {
            $migrationFound = true;
            File::delete($file->getPathname());
            break;
        }
    }

    expect($migrationFound)->toBeTrue();

    File::delete($configPath);
});
