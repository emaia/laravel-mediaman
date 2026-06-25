<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class MediaNotAcceptedByCollection extends Exception
{
    public static function mimeTypeNotAllowed(string $mimeType, string $collectionName): self
    {
        return new self("The MIME type `$mimeType` is not allowed by collection `$collectionName`.");
    }
}
