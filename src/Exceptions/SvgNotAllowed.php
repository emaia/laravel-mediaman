<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class SvgNotAllowed extends Exception
{
    public static function disabled(): self
    {
        return new self(
            'SVG uploads are disabled. Enable explicitly via `mediaman.svg.enabled = true` '.
            'and configure a sanitizer via `mediaman.svg.sanitizer`. '.
            'See docs/security.md → SVG uploads.'
        );
    }

    public static function noSanitizerConfigured(): self
    {
        return new self(
            'SVG uploads are enabled but no sanitizer is configured. Set '.
            '`mediaman.svg.sanitizer` to a class implementing '.
            '`Emaia\MediaMan\Security\SvgSanitizer`. See docs/security.md → '.
            'SVG uploads for a recommended adapter using enshrined/svg-sanitize.'
        );
    }

    public static function sanitizationFailed(string $reason): self
    {
        return new self("SVG sanitization failed: $reason");
    }
}
