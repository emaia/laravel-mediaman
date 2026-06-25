<?php

namespace Emaia\MediaMan\Security;

interface SvgSanitizer
{
    /**
     * Sanitize raw SVG markup. Return the cleaned string, or null to reject
     * the upload (caller throws `SvgNotAllowed::sanitizationFailed`).
     */
    public function sanitize(string $svgContent): ?string;
}
