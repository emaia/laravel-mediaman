<?php

namespace Emaia\MediaMan\Support;

use InvalidArgumentException;

final class GenerationConfig
{
    /** @return array{versioning: false|string, retention_days: int, timeout_minutes: int} */
    public static function values(string $prefix): array
    {
        $versioning = config("$prefix.versioning", false);

        if ($versioning !== false && $versioning !== 'generation') {
            throw new InvalidArgumentException("$prefix.versioning must be false or 'generation'.");
        }

        return [
            'versioning' => $versioning,
            'retention_days' => self::integer(
                "$prefix.version_retention_days",
                config("$prefix.version_retention_days", 7),
                allowZero: true,
            ),
            'timeout_minutes' => self::integer(
                "$prefix.generation_timeout_minutes",
                config("$prefix.generation_timeout_minutes", 1440),
                allowZero: false,
            ),
        ];
    }

    private static function integer(string $key, mixed $value, bool $allowZero): int
    {
        if (is_string($value)) {
            if (! preg_match('/^(0|[1-9][0-9]*)$/', $value)) {
                throw new InvalidArgumentException("$key must be an integer.");
            }

            $value = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => $allowZero ? 0 : 1],
            ]);
        }

        if (! is_int($value) || $value < ($allowZero ? 0 : 1)) {
            $constraint = $allowZero ? 'greater than or equal to zero' : 'greater than zero';

            throw new InvalidArgumentException("$key must be an integer $constraint.");
        }

        return $value;
    }
}
