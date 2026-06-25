<?php

namespace Emaia\MediaMan\Enums;

use Symfony\Component\Mime\MimeTypes;

enum MediaFormat: string
{
    case WEBP = 'webp';
    case AVIF = 'avif';
    case JPG = 'jpg';
    case JPEG = 'jpeg';
    case PNG = 'png';
    case GIF = 'gif';
    case BMP = 'bmp';
    case TIFF = 'tiff';
    case HEIC = 'heic';
    case HEIF = 'heif';
    case SVG = 'svg';

    public function mimeType(): string
    {
        return match ($this) {
            self::JPG, self::JPEG => 'image/jpeg',
            self::PNG => 'image/png',
            self::WEBP => 'image/webp',
            self::AVIF => 'image/avif',
            self::GIF => 'image/gif',
            self::BMP => 'image/bmp',
            self::TIFF => 'image/tiff',
            self::HEIC => 'image/heic',
            self::HEIF => 'image/heif',
            self::SVG => 'image/svg+xml',
        };
    }

    /** Formats supported as responsive variant outputs. */
    public static function responsiveFormats(): array
    {
        return [self::AVIF, self::WEBP, self::HEIC, self::JPG, self::JPEG, self::PNG, self::GIF];
    }

    /** Modern-first ordering driving `<source>` precedence in `<picture>` output. */
    public static function preferredOrder(): array
    {
        return [self::AVIF, self::HEIC, self::WEBP, self::JPG, self::JPEG, self::PNG];
    }

    /** Formats probed when reading existing variants from disk. */
    public static function detectableFormats(): array
    {
        return [self::WEBP, self::AVIF, self::PNG, self::JPG, self::GIF, self::BMP, self::TIFF, self::HEIC, self::HEIF];
    }

    /** Case-insensitive variant of `tryFrom()`. */
    public static function tryFromValue(string $value): ?self
    {
        return self::tryFrom(strtolower($value));
    }

    /**
     * Canonical extension for a MIME type via Symfony's MimeTypes registry.
     * Returns null for unknown MIMEs so callers can fail loudly instead of
     * writing files with a wrong-but-plausible extension (PR #31 family).
     */
    public static function extensionFromMimeType(string $mimeType): ?string
    {
        return MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null;
    }
}
