<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class FileSizeExceeded extends Exception
{
    public static function forSize(int $actualBytes, int $maxBytes): self
    {
        return new self("File size $actualBytes bytes exceeds the maximum allowed $maxBytes bytes.");
    }
}
