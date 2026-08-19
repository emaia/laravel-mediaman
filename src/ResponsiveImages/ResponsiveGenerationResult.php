<?php

namespace Emaia\MediaMan\ResponsiveImages;

final readonly class ResponsiveGenerationResult
{
    /**
     * Describe a completed generation attempt. Hard failures remain exceptions so queue retries keep working.
     *
     * @param  array<int, array{format: string, width: int, error: string}>  $skipped
     */
    public function __construct(
        public ResponsiveGenerationStatus $status,
        public ?string $generation = null,
        public ?string $disk = null,
        public int $attempted = 0,
        public int $published = 0,
        public array $skipped = [],
        public ?string $reason = null,
    ) {}

    public static function noOp(string $reason): self
    {
        return new self(ResponsiveGenerationStatus::NoOp, reason: $reason);
    }

    public function wasPublished(): bool
    {
        return $this->status === ResponsiveGenerationStatus::Published
            || $this->status === ResponsiveGenerationStatus::Partial;
    }
}
