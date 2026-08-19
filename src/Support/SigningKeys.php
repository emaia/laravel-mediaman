<?php

namespace Emaia\MediaMan\Support;

final class SigningKeys
{
    /** @return string[] */
    public static function all(): array
    {
        $previous = config('app.previous_keys', []);

        if (is_string($previous)) {
            $previous = explode(',', $previous);
        }

        return array_values(array_unique(array_filter([
            config('app.key'),
            ...(is_array($previous) ? $previous : []),
        ], fn ($key) => is_string($key) && $key !== '')));
    }
}
