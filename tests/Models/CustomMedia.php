<?php

namespace Emaia\MediaMan\Tests\Models;

use Emaia\MediaMan\Models\Media;

class CustomMedia extends Media
{
    public function getCustomFlag(): string
    {
        return 'custom-media';
    }
}
