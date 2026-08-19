<?php

namespace Emaia\MediaMan\Console\Concerns;

trait ParsesMediaKeys
{
    /**
     * Parse opaque comma-separated model keys, expanding bounded integer ranges.
     *
     * @return string[]
     */
    protected function parseMediaKeys(string $value, int $maxRangeSize = 10000): array
    {
        $keys = [];

        foreach (explode(',', $value) as $part) {
            $part = trim($part);

            if ($part === '') {
                return [];
            }

            if (str_contains($part, '..')) {
                if (! preg_match('/^([1-9][0-9]*)\.\.([1-9][0-9]*)$/', $part, $matches)) {
                    return [];
                }

                $from = filter_var($matches[1], FILTER_VALIDATE_INT);
                $to = filter_var($matches[2], FILTER_VALIDATE_INT);

                if (! is_int($from) || ! is_int($to) || $from > $to || ($to - $from + 1) > $maxRangeSize) {
                    return [];
                }

                for ($key = $from; $key <= $to; $key++) {
                    $keys[(string) $key] = true;

                    if (count($keys) > $maxRangeSize) {
                        return [];
                    }
                }

                continue;
            }

            $keys[$part] = true;

            if (count($keys) > $maxRangeSize) {
                return [];
            }
        }

        return array_keys($keys);
    }
}
