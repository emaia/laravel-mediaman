<?php

use Illuminate\Support\Facades\File;

it('publishes config file when it does not exist', function () {
    $configPath = config_path('mediaman.php');

    // Ensure clean state
    if (File::exists($configPath)) {
        File::delete($configPath);
    }

    $this->artisan('mediaman:publish-config')
        ->expectsOutputToContain('Published mediaman config file');

    expect(File::exists($configPath))->toBeTrue();

    // Clean up
    File::delete($configPath);
});

it('asks for confirmation when config already exists', function () {
    $configPath = config_path('mediaman.php');

    // Create a dummy config file
    File::ensureDirectoryExists(dirname($configPath));
    File::put($configPath, '<?php return [];');

    $this->artisan('mediaman:publish-config')
        ->expectsConfirmation('The mediaman config file already exists. Do you want to overwrite it?', 'no')
        ->expectsOutputToContain('was not overwritten');

    // Clean up
    File::delete($configPath);
});
