<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class InvalidConversion extends Exception
{
    public static function doesNotExist(string $name): InvalidConversion
    {
        return new InvalidConversion("Conversion `{$name}` does not exist");
    }
}
