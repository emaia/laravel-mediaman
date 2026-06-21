<?php

namespace Emaia\MediaMan\Resolvers;

use DateTimeInterface;
use Emaia\MediaMan\Models\Media;

/**
 * Single pluggable surface for everything that resolves to a path, URL, or
 * filename for a Media item. Replaces the v2 `PathGenerator`, `UrlGenerator`,
 * and `FileNamer` interfaces — most customizations touched all three together
 * (changing the path implies changing the URL), so consolidating them removes
 * three binds, three config keys, and three classes worth of indirection in
 * favor of one.
 *
 * Extend `DefaultMediaResolver` and override the methods you need; the rest
 * inherit the default behavior.
 */
interface MediaResolver
{
    // ─── Paths ──────────────────────────────────────────────────────────

    /**
     * Base directory for the media's original file, relative to the disk
     * root. Conversions and responsive variants live underneath it.
     */
    public function directory(Media $media): string;

    /**
     * Directory holding the generated variant for a specific conversion.
     */
    public function pathForConversion(Media $media, string $conversion): string;

    /**
     * Directory holding the responsive image variants.
     */
    public function pathForResponsive(Media $media): string;

    // ─── URLs ──────────────────────────────────────────────────────────

    /**
     * Public URL for the original file, or for a conversion when given.
     */
    public function url(Media $media, ?string $conversion = null): string;

    /**
     * Signed temporary URL (S3-style). Implementations should not apply
     * `url.prefix` or `version_query`: temporary URLs are already absolute
     * and signed.
     */
    public function temporaryUrl(Media $media, DateTimeInterface $expiration, ?string $conversion = null): string;

    // ─── Filenames ─────────────────────────────────────────────────────

    /**
     * Sanitize and produce the on-disk base filename for an uploaded file.
     */
    public function baseName(string $originalName): string;

    /**
     * Filename for a conversion variant. The default reuses the original
     * basename and only swaps the extension; the conversion identity is
     * carried by the directory path. Custom implementations may append a
     * suffix (e.g. `photo-thumb.jpg`) if preferred.
     */
    public function conversionFileName(string $originalName, string $conversion, string $extension): string;

    /**
     * Filename for a responsive variant.
     */
    public function responsiveFileName(string $originalName, int $width, string $format): string;
}
