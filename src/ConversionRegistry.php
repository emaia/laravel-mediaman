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
    protected array $conversions = [];

    /**
     * Get all the registered conversions.
     */
    public function all(): array
    {
        return array_map(fn ($item) => $item['closure'], $this->conversions);
    }

    /**
     * Register a new conversion.
     */
    public function register(string $name, callable $conversion): void
    {
        $this->conversions[$name] = [
            'closure' => $conversion,
            'format' => $this->detectFormat($conversion),
        ];
    }

    /**
     * Get the conversion with the specified name.
     *
     * @throws InvalidConversion
     */
    public function get(string $name): callable
    {
        if (! $this->exists($name)) {
            throw InvalidConversion::doesNotExist($name);
        }

        return $this->conversions[$name]['closure'];
    }

    /**
     * Get the pre-computed output format for a conversion.
     */
    public function getFormat(string $name): ?string
    {
        if (! $this->exists($name)) {
            return null;
        }

        return $this->conversions[$name]['format'];
    }

    /**
     * Determine if a conversion with the specified name exists.
     */
    public function exists(string $name): bool
    {
        return isset($this->conversions[$name]);
    }

    /**
     * Detect the output format from a conversion closure at registration time.
     */
    private function detectFormat(callable $converter): ?string
    {
        try {
            $reflection = new ReflectionFunction($converter);
            $code = $this->getClosureCode($reflection);

            if ($code) {
                $formatMethods = [
                    'toWebp(' => MediaFormat::WEBP->value,
                    'toAvif(' => MediaFormat::AVIF->value,
                    'toPng(' => MediaFormat::PNG->value,
                    'toJpeg(' => MediaFormat::JPG->value,
                    'toGif(' => MediaFormat::GIF->value,
                    'toBmp(' => MediaFormat::BMP->value,
                    'toTiff(' => MediaFormat::TIFF->value,
                    'toHeic(' => MediaFormat::HEIC->value,
                    'toHeif(' => MediaFormat::HEIF->value,
                ];

                foreach ($formatMethods as $method => $format) {
                    if (stripos($code, $method) !== false) {
                        return $format;
                    }
                }

                if (preg_match('/encode\w*\([\'"]([^\'\"]+)[\'"]/', $code, $matches)) {
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

    /**
     * Extract the closure source code using Reflection.
     */
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
