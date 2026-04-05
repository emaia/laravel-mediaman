<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class MimeTypeNotAllowed extends Exception
{
    public static function forMimeType(string $mimeType): self
    {
        return new self("The MIME type `{$mimeType}` is not allowed for upload.");
    }
}
