<?php

namespace Emaia\MediaMan\Resolvers;

use DateTimeInterface;
use Emaia\MediaMan\Models\Media;

/**
 * Single pluggable surface for paths, URLs, and filenames of a Media item.
 * Extend `DefaultMediaResolver` and override only the methods you need.
 * See docs/api.md → MediaResolver.
 */
interface MediaResolver
{
    // ─── Paths ──────────────────────────────────────────────────────────

    /** Base directory for the original file, relative to the disk root. */
    public function directory(Media $media): string;

    /** Directory holding the generated variant for a specific conversion. */
    public function pathForConversion(Media $media, string $conversion): string;

    /** Directory holding the responsive image variants. */
    public function pathForResponsive(Media $media): string;

    // ─── URLs ──────────────────────────────────────────────────────────

    /** Public URL for the original file, or for a conversion when given. */
    public function url(Media $media, ?string $conversion = null): string;

    /** Signed temporary URL (S3-style); must not apply `url.prefix` or `version_query`. */
    public function temporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string;

    // ─── Filenames ─────────────────────────────────────────────────────

    /** Sanitize and produce the on-disk base filename for an uploaded file. */
    public function baseName(string $originalName): string;

    /** Filename for a conversion variant — the conversion identity is in the path, the name carries the extension. */
    public function conversionFileName(string $originalName, string $conversion, string $extension): string;

    /** Filename for a responsive variant. */
    public function responsiveFileName(string $originalName, int $width, string $format): string;
}
