<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class InvalidCopyTarget extends Exception
{
    public static function missingTrait(): self
    {
        return new self('Target model must use the HasMedia trait.');
    }
}
