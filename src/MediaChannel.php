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

    /**
     * Aggregate flag updated incrementally as rules are registered so the
     * fast-path/aggregate-path branch in HasMedia::syncMedia can be picked
     * in O(1) without iterating $fileRules.
     */
    protected bool $anyRuleNeedsModel = false;

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
     * Register a validation closure that runs at attach time. Rules stack
     * with AND semantics — every registered rule must return truthy or the
     * attach throws MediaNotAcceptedByChannel.
     *
     * The closure receives the Media instance, plus the owning model as a
     * second argument when its signature declares it (detected via reflection
     * at registration so per-item validation stays reflection-free).
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
            $this->anyRuleNeedsModel = true;
        }

        return $this;
    }

    public function hasFileRules(): bool
    {
        return $this->fileRules !== [];
    }

    public function anyRuleNeedsModel(): bool
    {
        return $this->anyRuleNeedsModel;
    }

    /**
     * Run every registered rule against the given media, throwing on the
     * first failure. The owning model is passed only when a rule declares a
     * second parameter.
     */
    public function validateFile(Media $media, object $model, string $channelName): void
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
