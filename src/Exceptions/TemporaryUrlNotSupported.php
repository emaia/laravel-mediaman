<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class TemporaryUrlNotSupported extends Exception
{
    public static function forDisk(string $disk): self
    {
        return new self("Disk [$disk] does not support temporary URLs.");
    }
}
