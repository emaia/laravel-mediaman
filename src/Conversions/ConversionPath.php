<?php

namespace Emaia\MediaMan\Conversions;

use InvalidArgumentException;

final class ConversionPath
{
    public static function name(string $conversion): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $conversion) !== 1) {
            throw new InvalidArgumentException("Conversion name [$conversion] is unsafe for storage.");
        }

        return $conversion;
    }

    public static function directory(string $path): string
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || str_contains($path, '\\')
        ) {
            throw new InvalidArgumentException("Conversion directory [$path] is unsafe for storage.");
        }

        $path = rtrim($path, '/');

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("Conversion directory [$path] is unsafe for storage.");
            }
        }

        return $path;
    }

    public static function fileName(string $fileName): string
    {
        if (
            $fileName === ''
            || $fileName === '.'
            || $fileName === '..'
            || basename($fileName) !== $fileName
            || str_contains($fileName, '\\')
        ) {
            throw new InvalidArgumentException("Conversion filename [$fileName] is unsafe for storage.");
        }

        return $fileName;
    }
}
