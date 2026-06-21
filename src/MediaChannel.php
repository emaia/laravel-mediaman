<?php

namespace Emaia\MediaMan;

use Closure;
use Emaia\MediaMan\Exceptions\MediaNotAcceptedByChannel;
use Emaia\MediaMan\Models\Media;
use InvalidArgumentException;
use ReflectionFunction;

class MediaChannel
{
    protected array $conversions = [];

    protected string $fallbackUrl = '';

    protected string $fallbackPath = '';

    /** @var array<string, string> */
    protected array $conversionFallbackUrls = [];

    /** @var array<string, string> */
    protected array $conversionFallbackPaths = [];

    /** @var array<int, array{name: ?string, rule: Closure, needs_model: bool}> */
    protected array $fileRules = [];

    /** Cached so HasMedia::syncMedia can pick fast vs aggregate path in O(1). */
    protected bool $hasModelAwareRules = false;

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

    /**
     * Register a validation closure run at attach time. Rules stack (AND).
     * Closure receives Media + optionally the owning model when its signature declares it.
     * See docs/models.md → Channel validation rules.
     */
    public function acceptsFile(string|Closure $nameOrRule, ?Closure $rule = null): MediaChannel
    {
        if ($nameOrRule instanceof Closure) {
            $resolved = $nameOrRule;
            $name = null;
        } else {
            if ($rule === null) {
                throw new InvalidArgumentException(
                    'When a rule name is provided, a closure must follow as the second argument.'
                );
            }

            $resolved = $rule;
            $name = $nameOrRule;
        }

        $needsModel = (new ReflectionFunction($resolved))->getNumberOfParameters() >= 2;

        $this->fileRules[] = [
            'name' => $name,
            'rule' => $resolved,
            'needs_model' => $needsModel,
        ];

        if ($needsModel) {
            $this->hasModelAwareRules = true;
        }

        return $this;
    }

    public function hasFileRules(): bool
    {
        return $this->fileRules !== [];
    }

    public function hasModelAwareRules(): bool
    {
        return $this->hasModelAwareRules;
    }

    /** Run every registered rule; throw on the first failure. */
    public function validateMedia(Media $media, object $model, string $channelName): void
    {
        foreach ($this->fileRules as $entry) {
            $passes = $entry['needs_model']
                ? ($entry['rule'])($media, $model)
                : ($entry['rule'])($media);

            if (! $passes) {
                throw MediaNotAcceptedByChannel::ruleFailed(
                    $channelName,
                    $media->getKey(),
                    $entry['name'],
                );
            }
        }
    }
}
