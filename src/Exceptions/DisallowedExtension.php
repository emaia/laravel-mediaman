<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class DisallowedExtension extends Exception
{
    public static function forExtension(string $extension): self
    {
        return new self("The file extension `.$extension` is not allowed for upload.");
    }
}
