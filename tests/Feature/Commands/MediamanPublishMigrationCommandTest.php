<?php

use Illuminate\Support\Facades\File;

it('publishes migration file when none exists', function () {
    $migrationsPath = database_path('migrations');
    File::ensureDirectoryExists($migrationsPath);

    // Clear any existing mediaman migrations
    foreach (File::files($migrationsPath) as $file) {
        if (str_ends_with($file->getFilename(), '_create_mediaman_tables.php')) {
            File::delete($file->getPathname());
        }
    }

    $this->artisan('mediaman:publish-migration')
        ->expectsOutputToContain('Migration published to');

    $found = false;
    foreach (File::files($migrationsPath) as $file) {
        if (str_ends_with($file->getFilename(), '_create_mediaman_tables.php')) {
            $found = true;
            File::delete($file->getPathname());
            break;
        }
    }

    expect($found)->toBeTrue();
});

it('asks for confirmation when migration already exists', function () {
    $migrationsPath = database_path('migrations');
    File::ensureDirectoryExists($migrationsPath);

    // Create a dummy migration
    $dummyPath = $migrationsPath.'/2024_01_01_000000_create_mediaman_tables.php';
    File::put($dummyPath, '<?php // dummy');

    $this->artisan('mediaman:publish-migration')
        ->expectsOutputToContain('Found mediaman migration')
        ->expectsConfirmation('The mediaman migration file already exists. Do you want to overwrite it?', 'no')
        ->expectsOutputToContain('was not overwritten');

    // Clean up
    File::delete($dummyPath);
});
