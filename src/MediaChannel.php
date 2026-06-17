<?php

namespace Emaia\MediaMan;

class MediaChannel
{
    protected array $conversions = [];

    protected string $fallbackUrl = '';

    protected string $fallbackPath = '';

    /** @var array<string, string> */
    protected array $conversionFallbackUrls = [];

    /** @var array<string, string> */
    protected array $conversionFallbackPaths = [];

    public function performConversions(string ...$conversions): MediaChannel
    {
        $this->conversions = $conversions;

        return $this;
    }

    public function hasConversions(): bool
    {
        return ! empty($this->conversions);
    }

    public function getConversions(): array
    {
        return $this->conversions;
    }

    public function useFallbackUrl(string $url, ?string $conversion = null): MediaChannel
    {
        if ($conversion === null) {
            $this->fallbackUrl = $url;
        } else {
            $this->conversionFallbackUrls[$conversion] = $url;
        }

        return $this;
    }

    public function useFallbackPath(string $path, ?string $conversion = null): MediaChannel
    {
        if ($conversion === null) {
            $this->fallbackPath = $path;
        } else {
            $this->conversionFallbackPaths[$conversion] = $path;
        }

        return $this;
    }

    public function getFallbackUrl(?string $conversion = null): string
    {
        if ($conversion !== null && isset($this->conversionFallbackUrls[$conversion])) {
            return $this->conversionFallbackUrls[$conversion];
        }

        return $this->fallbackUrl;
    }

    public function getFallbackPath(?string $conversion = null): string
    {
        if ($conversion !== null && isset($this->conversionFallbackPaths[$conversion])) {
            return $this->conversionFallbackPaths[$conversion];
        }

        return $this->fallbackPath;
    }
}
