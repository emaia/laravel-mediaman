<?php

namespace Emaia\MediaMan\Facades;

use Emaia\MediaMan\ConversionRegistry;
use Illuminate\Support\Facades\Facade;

class Conversion extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return ConversionRegistry::class;
    }
}
