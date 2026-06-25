<?php

namespace Emaia\MediaMan;

use Emaia\MediaMan\Enums\MediaFormat;
use Emaia\MediaMan\Exceptions\InvalidConversion;
use Exception;
use Illuminate\Support\Facades\Log;
use ReflectionFunction;
use SplFileObject;

class ConversionRegistry
{
    /**
     * @var array<string, array{closure: callable, format: ?string, disk: ?string}>
     */
    protected array $conversions = [];

    /** @return array<string, callable> Name → closure for every registered conversion. */
    public function all(): array
    {
        return array_map(fn ($item) => $item['closure'], $this->conversions);
    }

    /**
     * `$disk = null` stores conversion files on the media's own disk; passing
     * an explicit disk enables hot/cold storage tiering for the variant.
     */
    public function register(string $name, callable $conversion, ?string $disk = null): void
    {
        $this->conversions[$name] = [
            'closure' => $conversion,
            'format' => $this->detectFormat($conversion),
            'disk' => $disk,
        ];
    }

    /** @throws InvalidConversion */
    public function get(string $name): callable
    {
        if (! $this->exists($name)) {
            throw InvalidConversion::doesNotExist($name);
        }

        return $this->conversions[$name]['closure'];
    }

    /** Pre-computed output format from registration-time reflection, or null. */
    public function getFormat(string $name): ?string
    {
        if (! $this->exists($name)) {
            return null;
        }

        return $this->conversions[$name]['format'];
    }

    public function exists(string $name): bool
    {
        return isset($this->conversions[$name]);
    }

    /** Per-conversion disk override, or null when the media's own disk is used. */
    public function getDisk(string $name): ?string
    {
        if (! $this->exists($name)) {
            return null;
        }

        return $this->conversions[$name]['disk'] ?? null;
    }

    /**
     * All unique, non-null disk names across every registered conversion.
     *
     * @return string[]
     */
    public function disks(): array
    {
        $disks = [];

        foreach ($this->conversions as $item) {
            if (isset($item['disk'])) {
                $disks[$item['disk']] = true;
            }
        }

        return array_keys($disks);
    }

    /** Inspects the closure source for `encodeUsingFormat()` / `encode('mime')` calls. */
    private function detectFormat(callable $converter): ?string
    {
        try {
            $reflection = new ReflectionFunction($converter);
            $code = $this->getClosureCode($reflection);

            if ($code) {
                $formatMethods = [
                    'encodeUsingFormat(Format::WEBP' => MediaFormat::WEBP->value,
                    'encodeUsingFormat(Format::AVIF' => MediaFormat::AVIF->value,
                    'encodeUsingFormat(Format::PNG' => MediaFormat::PNG->value,
                    'encodeUsingFormat(Format::JPEG' => MediaFormat::JPG->value,
                    'encodeUsingFormat(Format::GIF' => MediaFormat::GIF->value,
                    'encodeUsingFormat(Format::BMP' => MediaFormat::BMP->value,
                    'encodeUsingFormat(Format::TIFF' => MediaFormat::TIFF->value,
                    'encodeUsingFormat(Format::HEIC' => MediaFormat::HEIC->value,
                    'encodeUsingFormat(Format::HEIF' => MediaFormat::HEIF->value,
                ];

                foreach ($formatMethods as $method => $format) {
                    if (stripos($code, $method) !== false) {
                        return $format;
                    }
                }

                if (preg_match('/encode\([\'"]([^\'\"]+)[\'"]/', $code, $matches)) {
                    return MediaFormat::extensionFromMimeType($matches[1]);
                }
            }
        } catch (Exception $e) {
            Log::debug('MediaMan: Format detection at registration failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function getClosureCode(ReflectionFunction $reflection): ?string
    {
        try {
            $filename = $reflection->getFileName();
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();

            if (! $filename || ! $startLine || ! $endLine) {
                return null;
            }

            $file = new SplFileObject($filename);
            $file->seek($startLine - 1);

            $code = '';
            for ($i = $startLine; $i <= $endLine; $i++) {
                $code .= $file->current();
                $file->next();
            }

            return $code;
        } catch (Exception $e) {
            Log::debug('MediaMan: Failed to extract closure code', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
