<?php

namespace Emaia\MediaMan\Console\Concerns;

/** @deprecated Use ParsesMediaKeys for custom-model and bounded-range support. */
trait ParsesMediaIds
{
    /** Parse positive integer IDs and ranges using the legacy command semantics. */
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

                for ($id = $from; $id <= $to; $id++) {
                    $ids[] = $id;
                }

                continue;
            }

            $id = (int) $part;

            if ($id <= 0) {
                return [];
            }

            $ids[] = $id;
        }

        return $ids;
    }
}
