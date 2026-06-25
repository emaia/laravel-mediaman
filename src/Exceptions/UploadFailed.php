<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

/**
 * Thrown when PHP rejected the upload before MediaMan saw it (commonly:
 * `upload_max_filesize` exceeded, `post_max_size` exceeded, partial upload,
 * missing tmp dir). The original `UPLOAD_ERR_*` code is exposed so callers
 * can `match ($e->phpUploadErrorCode)` instead of parsing the message.
 */
class UploadFailed extends Exception
{
    public function __construct(
        public readonly int $phpUploadErrorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromPhpUpload(int $code, string $message): self
    {
        return new self($code, "PHP rejected the upload before MediaMan received it: $message");
    }
}
