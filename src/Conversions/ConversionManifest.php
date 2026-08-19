<?php

namespace Emaia\MediaMan\Conversions;

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Support\SigningKeys;
use RuntimeException;

final class ConversionManifest
{
    public static function sign(Media $media, array $files, array $disks, ?string $key = null): string
    {
        $key ??= SigningKeys::all()[0]
            ?? throw new RuntimeException('APP_KEY is required to sign conversion metadata.');

        return hash_hmac('sha256', json_encode(self::canonicalize([
            'model' => $media::class,
            'table' => $media->getTable(),
            'key' => (string) $media->getKey(),
            'files' => $files,
            'disks' => array_values(array_unique($disks)),
        ]), JSON_THROW_ON_ERROR), $key);
    }

    public static function isValid(Media $media, array $files, array $disks, mixed $signature): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        foreach (SigningKeys::all() as $key) {
            if (hash_equals(self::sign($media, $files, $disks, $key), $signature)) {
                return true;
            }
        }

        return false;
    }

    private static function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? self::canonicalize($item) : $item, $value);
        }

        ksort($value);

        return array_map(fn ($item) => is_array($item) ? self::canonicalize($item) : $item, $value);
    }
}
