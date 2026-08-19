<?php

namespace Emaia\MediaMan\Conversions;

use Emaia\MediaMan\Support\GenerationToken;

final class ConversionGeneration
{
    public static function isManaged(string $generation): bool
    {
        return GenerationToken::isManaged($generation);
    }
}
