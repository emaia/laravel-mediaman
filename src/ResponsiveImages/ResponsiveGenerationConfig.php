<?php

namespace Emaia\MediaMan\ResponsiveImages;

use InvalidArgumentException;

final readonly class ResponsiveGenerationConfig
{
    public function __construct(
        public false|string $versioning,
        public int $retentionDays,
        public int $generationTimeoutMinutes,
    ) {}

    public static function fromConfig(): self
    {
        $versioning = config('mediaman.responsive_images.versioning', false);

        if ($versioning !== false && $versioning !== 'generation') {
            throw new InvalidArgumentException(
                "mediaman.responsive_images.versioning must be false or 'generation'."
            );
        }

        return new self(
            $versioning,
            self::normalizeInteger(
                'mediaman.responsive_images.version_retention_days',
                config('mediaman.responsive_images.version_retention_days', 7),
                allowZero: true,
            ),
            self::normalizeInteger(
                'mediaman.responsive_images.generation_timeout_minutes',
                config('mediaman.responsive_images.generation_timeout_minutes', 1440),
                allowZero: false,
            ),
        );
    }

    public function isVersioned(): bool
    {
        return $this->versioning === 'generation';
    }

    private static function normalizeInteger(string $key, mixed $value, bool $allowZero): int
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
