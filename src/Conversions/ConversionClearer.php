<?php

namespace Emaia\MediaMan\Conversions;

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Resolvers\MediaResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ConversionClearer
{
    /** Unpublish and remove one conversion, returning false when no files existed. */
    public function clear(Media $media, string $conversion): bool
    {
        [$fresh, $state] = DB::connection($media->getConnectionName())->transaction(
            function () use ($media, $conversion): array {
                /** @var Media|null $fresh */
                $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

                if ($fresh === null) {
                    throw new RuntimeException("Media [{$media->getKey()}] no longer exists.");
                }

                if ($fresh->hasCustomProperty(Media::PROPERTY_RESPONSIVE_ROTATING)) {
                    throw new RuntimeException("Media [{$media->getKey()}] is rotating paths.");
                }

                $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
                $clearing = $properties[Media::PROPERTY_CONVERSION_CLEARING] ?? [];
                $clearing = is_array($clearing) ? $clearing : [];
                $state = $clearing[$conversion] ?? null;

                if (! is_array($state) || ! self::isValidClearState($fresh, $conversion, $state)) {
                    $active = $fresh->getConversionFile($conversion);
                    $disks = array_values(array_unique(array_filter([
                        ...$fresh->getConversionDisks(),
                        $active['disk'] ?? null,
                        $fresh->getConversionWriteDisk($conversion),
                    ], fn ($disk) => is_string($disk) && $disk !== '')));
                    $state = [
                        'token' => strtoupper((string) Str::ulid()),
                        'base_path' => ConversionPath::directory(
                            app(MediaResolver::class)->pathForConversion($fresh, $conversion),
                        ),
                        'disks' => $disks,
                        'started_at' => now()->toIso8601String(),
                    ];
                    $state['signature'] = self::signClearState($fresh, $conversion, $state);
                    $clearing[$conversion] = $state;
                }

                $files = $properties[Media::PROPERTY_CONVERSION_FILES] ?? [];
                $files = is_array($files) ? $files : [];
                unset($files[$conversion]);

                if ($files === []) {
                    unset($properties[Media::PROPERTY_CONVERSION_FILES]);
                } else {
                    $properties[Media::PROPERTY_CONVERSION_FILES] = $files;
                }

                $epochs = $properties[Media::PROPERTY_CONVERSION_GENERATION_EPOCHS] ?? [];
                $epochs = is_array($epochs) ? $epochs : [];
                $epochs[$conversion] = (int) ($epochs[$conversion] ?? 0) + 1;
                $properties[Media::PROPERTY_CONVERSION_GENERATION_EPOCHS] = $epochs;
                $properties[Media::PROPERTY_CONVERSION_CLEARING] = $clearing;
                $knownDisks = $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS] ?? [];
                $knownDisks = is_array($knownDisks) ? $knownDisks : [];
                $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE] = ConversionManifest::sign(
                    $fresh,
                    $files,
                    $knownDisks,
                );
                $fresh->custom_properties = $properties;
                $fresh->save();

                return [$fresh, $state];
            },
        );
        $media->setRawAttributes($fresh->getAttributes(), true);
        $found = false;

        foreach ($state['disks'] as $disk) {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($state['base_path'])) {
                continue;
            }

            $found = true;

            if (! $filesystem->deleteDirectory($state['base_path'])) {
                throw new RuntimeException("Failed to delete conversion [$conversion] from disk [$disk].");
            }
        }

        $published = DB::connection($media->getConnectionName())->transaction(
            function () use ($media, $conversion, $state): Media {
                /** @var Media|null $fresh */
                $fresh = $media->newQuery()->whereKey($media->getKey())->lockForUpdate()->first();

                if ($fresh === null) {
                    throw new RuntimeException("Media [{$media->getKey()}] no longer exists.");
                }

                $properties = is_array($fresh->custom_properties) ? $fresh->custom_properties : [];
                $clearing = $properties[Media::PROPERTY_CONVERSION_CLEARING] ?? [];
                $clearing = is_array($clearing) ? $clearing : [];

                if (($clearing[$conversion]['token'] ?? null) === $state['token']) {
                    unset($clearing[$conversion]);
                }

                if ($clearing === []) {
                    unset($properties[Media::PROPERTY_CONVERSION_CLEARING]);

                    $files = $properties[Media::PROPERTY_CONVERSION_FILES] ?? [];

                    if (! is_array($files) || $files === []) {
                        unset(
                            $properties[Media::PROPERTY_CONVERSION_GENERATION_DISKS],
                            $properties[Media::PROPERTY_CONVERSION_MANIFEST_SIGNATURE],
                        );
                    }
                } else {
                    $properties[Media::PROPERTY_CONVERSION_CLEARING] = $clearing;
                }

                $fresh->custom_properties = $properties;
                $fresh->save();

                return $fresh;
            },
        );
        $media->setRawAttributes($published->getAttributes(), true);

        return $found;
    }

    /** Validate that a clear tombstone was issued for one conversion on this media. */
    public static function isValidClearState(Media $media, string $conversion, array $state): bool
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $conversion) !== 1
            || ! is_array($state['disks'] ?? null)
        ) {
            return false;
        }

        foreach ($state['disks'] as $disk) {
            if (! is_string($disk) || $disk === '') {
                return false;
            }
        }

        if (! (isset($state['signature'], $state['started_at'])
            && is_string($state['signature'])
            && is_string($state['token'] ?? null)
            && ConversionGeneration::isManaged($state['token'])
            && is_string($state['base_path'] ?? null)
            && $state['base_path'] !== ''
            && ! str_contains($state['base_path'], '..')
            && is_string($state['started_at']))) {
            return false;
        }

        foreach (self::signingKeys() as $key) {
            if (hash_equals(self::signClearState($media, $conversion, $state, $key), $state['signature'])) {
                return true;
            }
        }

        return false;
    }

    /** @param array{token: string, base_path: string, disks: array, started_at: string, signature?: string} $state */
    private static function signClearState(Media $media, string $conversion, array $state, ?string $key = null): string
    {
        $key ??= self::signingKeys()[0]
            ?? throw new RuntimeException('APP_KEY is required to sign conversion clear state.');

        return hash_hmac('sha256', json_encode([
            'model' => $media::class,
            'table' => $media->getTable(),
            'key' => (string) $media->getKey(),
            'conversion' => $conversion,
            'token' => $state['token'],
            'base_path' => $state['base_path'],
            'disks' => array_values($state['disks']),
            'started_at' => $state['started_at'],
        ], JSON_THROW_ON_ERROR), $key);
    }

    /** @return string[] */
    private static function signingKeys(): array
    {
        $previous = config('app.previous_keys', []);

        if (is_string($previous)) {
            $previous = explode(',', $previous);
        }

        return array_values(array_unique(array_filter([
            config('app.key'),
            ...(is_array($previous) ? $previous : []),
        ], fn ($key) => is_string($key) && $key !== '')));
    }
}
