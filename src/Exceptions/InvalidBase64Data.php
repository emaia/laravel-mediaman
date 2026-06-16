<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class InvalidBase64Data extends Exception
{
    public static function invalid(): self
    {
        return new self('Invalid base64 data.');
    }

    public static function invalidDataUri(): self
    {
        return new self('Invalid data URI format.');
    }
}
