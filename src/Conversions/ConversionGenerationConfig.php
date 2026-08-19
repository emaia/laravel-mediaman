<?php

namespace Emaia\MediaMan\Conversions;

use Emaia\MediaMan\Support\GenerationConfig;

final readonly class ConversionGenerationConfig
{
    public function __construct(
        public false|string $versioning,
        public int $retentionDays,
        public int $generationTimeoutMinutes,
    ) {}

    public static function fromConfig(): self
    {
        $values = GenerationConfig::values('mediaman.conversions');

        return new self(
            $values['versioning'],
            $values['retention_days'],
            $values['timeout_minutes'],
        );
    }

    public function isVersioned(): bool
    {
        return $this->versioning === 'generation';
    }
}
