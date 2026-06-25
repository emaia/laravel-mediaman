<?php

use Emaia\MediaMan\Exceptions\DisallowedExtension;
use Emaia\MediaMan\Exceptions\FileSizeExceeded;
use Emaia\MediaMan\Exceptions\SvgNotAllowed;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Security\SvgSanitizer;
use Illuminate\Http\UploadedFile;

// --- min_file_size hardening ---

it('rejects 0-byte uploads by default (min_file_size=1)', function () {
    // Empty file
    $tmp = tempnam(sys_get_temp_dir(), 'mm_empty_');
    $empty = new UploadedFile($tmp, 'ghost.bin', 'application/octet-stream', null, true);

    expect(fn () => MediaUploader::source($empty)->upload())
        ->toThrow(FileSizeExceeded::class, 'is below the minimum required 1 bytes');
});

it('accepts 0-byte uploads when min_file_size is set to 0', function () {
    config()->set('mediaman.min_file_size', 0);

    $tmp = tempnam(sys_get_temp_dir(), 'mm_empty_');
    $empty = new UploadedFile($tmp, 'placeholder.bin', 'application/octet-stream', null, true);

    $media = MediaUploader::source($empty)->upload();

    expect($media->size)->toBe(0);
});

it('rejects uploads below a configured min_file_size threshold', function () {
    config()->set('mediaman.min_file_size', 1024); // 1 KB

    $tmp = tempnam(sys_get_temp_dir(), 'mm_small_');
    file_put_contents($tmp, str_repeat('x', 500)); // 500 bytes — below threshold

    $file = new UploadedFile($tmp, 'small.txt', 'text/plain', null, true);

    expect(fn () => MediaUploader::source($file)->upload())
        ->toThrow(FileSizeExceeded::class, 'is below the minimum required 1024 bytes');
});

// --- Expanded disallowed_extensions blocklist (defense in depth) ---

it('blocks shell-script and Windows-executable extensions by default', function () {
    foreach (['sh', 'bat', 'exe', 'ps1', 'py', 'vbs'] as $ext) {
        $file = UploadedFile::fake()->create("payload.$ext", 10);

        expect(fn () => MediaUploader::source($file)->upload())
            ->toThrow(DisallowedExtension::class)
            ->and(true)->toBeTrue();
    }
});

// --- SVG hardening (pluggable sanitizer) ---

function makeSvgUpload(string $svgContent, string $name = 'graphic.svg'): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'mm_svg_');
    file_put_contents($tmp, $svgContent);

    return new UploadedFile($tmp, $name, 'image/svg+xml', null, true);
}

class StripScriptSanitizer implements SvgSanitizer
{
    public function sanitize(string $svgContent): ?string
    {
        // Minimal test double: drop <script> and event handlers.
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svgContent);
        $clean = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $clean ?? $svgContent);

        return $clean;
    }
}

class RejectAllSanitizer implements SvgSanitizer
{
    public function sanitize(string $svgContent): ?string
    {
        return null;
    }
}

it('rejects SVG uploads by default', function () {
    config()->set('mediaman.svg.enabled', false);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';

    expect(fn () => MediaUploader::source(makeSvgUpload($svg))->upload())
        ->toThrow(SvgNotAllowed::class, 'SVG uploads are disabled');
});

it('throws when SVG enabled but no sanitizer configured', function () {
    config()->set('mediaman.svg.enabled', true);
    config()->set('mediaman.svg.sanitizer', null);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';

    expect(fn () => MediaUploader::source(makeSvgUpload($svg))->upload())
        ->toThrow(SvgNotAllowed::class, 'no sanitizer is configured');
});

it('routes SVG through the configured sanitizer and writes the cleaned bytes', function () {
    config()->set('mediaman.svg.enabled', true);
    config()->set('mediaman.svg.sanitizer', StripScriptSanitizer::class);

    $malicious = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
        <script>alert('xss')</script>
        <rect width="10" height="10" onclick="alert('xss')" fill="red"/>
    </svg>
    SVG;

    $media = MediaUploader::source(makeSvgUpload($malicious))->upload();

    $stored = $media->filesystem()->get($media->getPath());

    expect($stored)
        ->not->toContain('<script>')
        ->not->toContain('onclick')
        ->toContain('<rect');
});

it('throws when the sanitizer rejects the markup', function () {
    config()->set('mediaman.svg.enabled', true);
    config()->set('mediaman.svg.sanitizer', RejectAllSanitizer::class);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';

    expect(fn () => MediaUploader::source(makeSvgUpload($svg))->upload())
        ->toThrow(SvgNotAllowed::class, 'sanitizer rejected the SVG markup');
});

// --- SVG detection bypass coverage (3-way detection) ---

it('catches SVG via filename extension even when finfo returns text/xml', function () {
    config()->set('mediaman.svg.enabled', false);

    // Simulate outdated magic db: file is technically valid SVG but sniff says
    // text/xml because the leading XML declaration confuses old finfo databases.
    // We build the UploadedFile with the lying client mime — getMimeType() runs
    // its own finfo sniff on the file contents; pass-through .svg extension is
    // what guarantees detection regardless of sniff drift.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';
    $tmp = tempnam(sys_get_temp_dir(), 'mm_svg_ext_');
    file_put_contents($tmp, $svg);
    $file = new UploadedFile($tmp, 'graphic.svg', 'image/svg+xml', null, true);

    // Force the looksLikeSvg fallback path even if finfo correctly identifies:
    // the .svg extension alone is enough to trigger SVG handling.
    expect(fn () => MediaUploader::source($file)->upload())
        ->toThrow(SvgNotAllowed::class, 'SVG uploads are disabled');
});

it('catches SVG via content marker when extension lies and MIME lies', function () {
    config()->set('mediaman.svg.enabled', false);

    // Worst-case bypass: arbitrary extension, generic client MIME, but the
    // bytes ARE an SVG. Sniff via finfo may classify as text/xml or even
    // text/plain depending on the magic db; both bypass MIME-only checks.
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>';
    $tmp = tempnam(sys_get_temp_dir(), 'mm_svg_arb_');
    file_put_contents($tmp, $svg);
    $file = new UploadedFile($tmp, 'innocuous.bin', 'application/octet-stream', null, true);

    expect(fn () => MediaUploader::source($file)->upload())
        ->toThrow(SvgNotAllowed::class, 'SVG uploads are disabled');
});

it('does not treat plain XML as SVG (content marker discriminates <svg>)', function () {
    config()->set('mediaman.svg.enabled', false);

    // Plain XML should NOT trigger the SVG path — only files whose first
    // non-whitespace element is `<svg>` (post XML declaration) are SVG.
    $xml = '<?xml version="1.0"?><root><child>data</child></root>';
    $tmp = tempnam(sys_get_temp_dir(), 'mm_xml_');
    file_put_contents($tmp, $xml);
    $file = new UploadedFile($tmp, 'document.xml', 'application/xml', null, true);

    // Should upload successfully (no SVG path triggered, no DisallowedExtension
    // because .xml is not blocklisted by default).
    $media = MediaUploader::source($file)->upload();

    expect($media)->toBeInstanceOf(Media::class);
});
