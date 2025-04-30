<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Exceptions\InvalidConversion;

class ConversionRegistry
{
    protected array $conversions = [];

    /**
     * Get all the registered conversions.
     */
    public function all(): array
    {
        return $this->conversions;
    }

    /**
     * Register a new conversion.
     */
    public function register(string $name, callable $conversion): void
    {
        $this->conversions[$name] = $conversion;
    }

    /**
     * Get the conversion with the specified name.
     * @throws InvalidConversion
     */
    public function get(string $name): mixed
    {
        if (! $this->exists($name)) {
            throw InvalidConversion::doesNotExist($name);
        }

        return $this->conversions[$name];
    }

    /**
     * Determine if a conversion with the specified name exists.
     */
    public function exists(string $name): bool
    {
        return isset($this->conversions[$name]);
    }
}
