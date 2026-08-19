<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Emaia\MediaMan\Support\GenerationToken;

final class ResponsiveGeneration
{
    public static function isManaged(string $generation): bool
    {
        return GenerationToken::isManaged($generation);
    }
}
