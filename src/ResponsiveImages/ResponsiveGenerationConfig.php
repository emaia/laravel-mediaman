<?php

namespace Emaia\MediaMan\ResponsiveImages;

use Emaia\MediaMan\Support\GenerationConfig;

final readonly class ResponsiveGenerationConfig
{
    public function __construct(
        public false|string $versioning,
        public int $retentionDays,
        public int $generationTimeoutMinutes,
    ) {}

    public static function fromConfig(): self
    {
        $values = GenerationConfig::values('mediaman.responsive_images');

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
