<?php

namespace Emaia\MediaMan\Console\Commands;

use Illuminate\Console\Command;

class MediamanPublishCommand extends Command
{
    protected $signature = 'mediaman:publish';

    protected $description = 'Publish MediaMan config and migration in one step';

    public function handle(): int
    {
        $this->call('mediaman:publish-config');
        $this->call('mediaman:publish-migration');

        return self::SUCCESS;
    }
}
