<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class MediaFileWriteFailed extends Exception
{
    public function __construct(
        public readonly string $path,
        public readonly string $disk,
    ) {
        parent::__construct("Failed to write media file to [$path] on disk [$disk].");
    }

    public static function forPath(string $path, string $disk): self
    {
        return new self($path, $disk);
    }
}
