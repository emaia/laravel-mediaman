<?php

namespace Emaia\MediaMan\Tests\Models;

use Emaia\MediaMan\Models\MediaCollection;

class CustomMediaCollection extends MediaCollection
{
    public function getCustomFlag(): string
    {
        return 'custom-collection';
    }
}
