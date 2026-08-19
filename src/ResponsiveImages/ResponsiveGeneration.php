<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Symfony\Component\Uid\Ulid;

final class ResponsiveGeneration
{
    public static function isManaged(string $generation): bool
    {
        return $generation !== '00000000000000000000000000'
            && $generation !== '7ZZZZZZZZZZZZZZZZZZZZZZZZZ'
            && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $generation) === 1
            && Ulid::isValid($generation);
    }
}
