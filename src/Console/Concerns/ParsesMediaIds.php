<?php

namespace Emaia\MediaMan\Console\Concerns;

trait ParsesMediaIds
{
    /**
     * Parse a --media value into an array of IDs. Supports comma-separated
     * individual IDs ("1,3,5"), ranges ("1..10"), or a mix ("1,3..5").
     *
     * Returns an empty array when any part is invalid.
     */
    protected function parseMediaIds(string $value): array
    {
        $ids = [];

        foreach (explode(',', $value) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (str_contains($part, '..')) {
                [$from, $to] = explode('..', $part);
                $from = (int) $from;
                $to = (int) $to;

                if ($from <= 0 || $to <= 0 || $from > $to) {
                    return [];
                }

                for ($i = $from; $i <= $to; $i++) {
                    $ids[] = $i;
                }
            } else {
                $id = (int) $part;

                if ($id <= 0) {
                    return [];
                }

                $ids[] = $id;
            }
        }

        return $ids;
    }
}
