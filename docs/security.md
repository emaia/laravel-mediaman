# Security

[← Back to README](../README.md)

- [Hardening recommendations](#hardening-recommendations)
- [Disallowed file extensions](#disallowed-file-extensions)
- [SVG uploads](#svg-uploads)
- [Minimum upload size](#minimum-upload-size)
- [SSRF protection for remote URLs](#ssrf-protection-for-remote-urls)
- [Detect orphaned files](#detect-orphaned-files)
- [`APP_KEY` rotation](#app_key-rotation)
- [Custom paths and URLs](#custom-paths-and-urls)

## Hardening recommendations

MediaMan ships with permissive defaults — sensible for the package to "just work" on first install. For **production deployments**, tighten the following in `config/mediaman.php`:

```php
// 1. Whitelist explicit MIME types. Empty array (default) accepts everything.
'allowed_mime_types' => [
    // Images
    'image/jpeg', 'image/png', 'image/gif',
    'image/webp', 'image/avif', 'image/heic',
    // 'image/svg+xml',  // opt-in: also requires svg.enabled=true (see below)

    // Documents
    'application/pdf',

    // Video (uncomment as needed)
    // 'video/mp4', 'video/webm',

    // Audio (uncomment as needed)
    // 'audio/mpeg', 'audio/mp4',
],

// 2. Set a maximum upload size (bytes). 0 = unlimited.
'max_file_size' => 50 * 1024 * 1024, // 50 MB

// 3. Keep the extension blocklist on (the default).
'block_disallowed_extensions' => true,

// 4. Keep SVG disabled unless you've reviewed the sanitization story below.
'svg' => ['enabled' => false],
```

Run `php artisan mediaman:doctor` — the **Security** section flags configurations that diverge from these recommendations (empty allow-list, SVG enabled, blocklist disabled).

**Why both whitelist AND blocklist?** They're orthogonal defenses, not competing:

- `allowed_mime_types` answers "what content do I want to accept?" — validates the MIME sniffed via `finfo` against the allowlist.
- `disallowed_extensions` answers "even if MIME matches, never let these extensions land?" — validates the sanitized filename's extension against the blocklist. A PHP shell renamed `.jpg` with a forged MIME still has `.jpg` extension and may sneak past extension-only checks; an `image/jpeg` content-typed PHP file passes the MIME allow-list but fails the extension blocklist if `.php`.

## Disallowed file extensions

MediaMan blocks executable and server-side file extensions on upload by default. The blocklist covers two categories:

**Server-side execution** (Apache/Nginx interpret these when configured):

`php`, `phtml`, `phar`, `shtml`, `htaccess`, `cgi`, `pl`, `asp`, `aspx`, `jsp`, `jspx`

**Defense in depth** (interpreter scripts + Windows-side executables — not a server-execution risk, but harmless to deny by default):

`sh`, `bash`, `zsh`, `py`, `rb`, `exe`, `com`, `msi`, `scr`, `bat`, `cmd`, `vbs`, `ps1`

Configure or disable in `config/mediaman.php`:

```php
'block_disallowed_extensions' => true,
'disallowed_extensions' => [
    'php', 'phtml', 'phar', 'shtml', 'htaccess',
    'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
    'sh', 'bash', 'zsh', 'py', 'rb',
    'exe', 'com', 'msi', 'scr', 'bat', 'cmd', 'vbs', 'ps1',
],
```

Uploads with a disallowed extension throw `Emaia\MediaMan\Exceptions\DisallowedExtension`.

The check runs against the **sanitized** filename, so double-extension attacks (`malware.php.jpg`) are defused before validation: the dot is replaced (`malware-php.jpg`) and the extension becomes `jpg`.

## SVG uploads

SVGs are XML documents that can embed `<script>`, `<foreignObject>`, and DOM event handlers (`onclick`, `onload`, …). When served same-origin with `Content-Type: image/svg+xml`, malicious SVGs become XSS vectors.

**Disabled by default.** Uploading an SVG with `svg.enabled = false` throws `Emaia\MediaMan\Exceptions\SvgNotAllowed`.

**When enabled**, every SVG upload is routed through an app-supplied sanitizer (`mediaman.svg.sanitizer`) before storage. The sanitized markup is what lands on disk; the raw upload is discarded. MediaMan does **not** ship a default sanitizer — XSS surface is your app's threat model to own.

```php
'svg' => [
    'enabled' => env('MEDIAMAN_SVG_ENABLED', false),
    'sanitizer' => \App\Services\Mediaman\EnshrinedSvgSanitizer::class,
],
```

Implement the sanitizer by satisfying `Emaia\MediaMan\Security\SvgSanitizer`:

```php
interface SvgSanitizer
{
    /**
     * Sanitize raw SVG markup. Return the cleaned string, or null to reject
     * the upload (caller throws `SvgNotAllowed::sanitizationFailed`).
     */
    public function sanitize(string $svgContent): ?string;
}
```

### Recommended adapter: [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer)

DOM-parsed whitelist of safe tags/attributes — the de-facto standard for PHP SVG sanitization (used by WordPress, ProcessWire, October CMS). More rigorous for SVG specifically than general-purpose XSS cleaners.

```bash
composer require enshrined/svg-sanitize
```

```php
// app/Services/Mediaman/EnshrinedSvgSanitizer.php
namespace App\Services\Mediaman;

use Emaia\MediaMan\Security\SvgSanitizer;
use enshrined\svgSanitize\Sanitizer;

class EnshrinedSvgSanitizer implements SvgSanitizer
{
    public function sanitize(string $svgContent): ?string
    {
        $clean = (new Sanitizer)->sanitize($svgContent);

        return $clean === false ? null : $clean;
    }
}
```

### Alternative adapter: [voku/anti-xss](https://github.com/voku/anti-xss)

General-purpose XSS sanitizer (port from CodeIgniter). Acceptable for apps already depending on it for other surfaces; for SVG isolated, prefer `enshrined/svg-sanitize` — regex-based filters are historically more bypassable on SVG-specific vectors than DOM-parsed whitelists.

```bash
composer require voku/anti-xss
```

```php
namespace App\Services\Mediaman;

use Emaia\MediaMan\Security\SvgSanitizer;
use voku\helper\AntiXSS;

class VokuSvgSanitizer implements SvgSanitizer
{
    public function sanitize(string $svgContent): ?string
    {
        $antiXss = new AntiXSS;
        $clean = $antiXss->xss_clean($svgContent);

        return $antiXss->isXssFound() === null ? $clean : null;
    }
}
```

### Failure modes

- `mediaman.svg.enabled = false` + SVG upload → `SvgNotAllowed::disabled()`
- `mediaman.svg.enabled = true` + `sanitizer = null` + SVG upload → `SvgNotAllowed::noSanitizerConfigured()`
- Sanitizer returns `null` (rejects markup) → `SvgNotAllowed::sanitizationFailed($reason)`

## Minimum upload size

`min_file_size` (bytes) rejects uploads below the threshold as `FileSizeExceeded` (same exception family as `max_file_size`).

Default is **1** — rejects zero-byte uploads, which would otherwise create ghost media records pointing at empty files (placeholder generation fails silently, conversions fail silently, responsive variants fail silently).

Set to `0` to allow zero-byte uploads explicitly (legitimate use case: placeholder records for late binding):

```php
'min_file_size' => env('MEDIAMAN_MIN_FILE_SIZE', 1),
```

## SSRF protection for remote URLs

`MediaUploader::fromUrl()` validates every URL through `Emaia\MediaMan\Support\UrlGuard` before any HTTP request leaves your server. Blocked by default:

- **Schemes** other than `http`/`https`
- **Hostnames** `localhost`, `localhost.`, `*.localhost`
- **IPv4 ranges** `0.0.0.0/8`, `10.0.0.0/8`, `127.0.0.0/8`, `169.254.0.0/16` (AWS/GCP metadata), `172.16.0.0/12`, `192.168.0.0/16`, `255.255.255.255` broadcast
- **IPv6** `::` (unspecified), `::1` (loopback), `fc00::/7` (ULA), `fe80::/10` (link-local), `::ffff:x.x.x.x` (IPv4-mapped), `2002::/16` (6to4), `2001::/32` (Teredo)
- **DNS resolution** — both A and AAAA records are checked; any resolved private IP rejects the URL

To allow downloads from internal networks, set:

```php
'url_sources' => [
    'allow_private_hosts' => true,
],
```

### DNS rebinding mitigation

The IPs resolved by `UrlGuard` are pinned through `CURLOPT_RESOLVE` so the actual HTTP request targets exactly the IPs that were validated — preventing DNS rebinding between check time and fetch time.

### Layered size enforcement

`fromUrl()` enforces `max_size_bytes` in four passes:

1. **Scheme + host validation** (above)
2. **HEAD `Content-Length` pre-check** — oversized URLs rejected before download starts
3. **In-stream size guard** — download aborts mid-stream when the limit is exceeded
4. **Post-download filesize verification** — final defense

See [Uploads → From a remote URL](uploads.md#from-a-remote-url) for usage.

## Detect orphaned files

```bash
# Dry run (default) — reports without deleting
php artisan mediaman:clean

# Delete orphaned files on disk
php artisan mediaman:clean --force

# Scope to a specific disk
php artisan mediaman:clean --disk=s3-media
```

The command reports two kinds of orphans:

- **Files on disk** without a matching Media record → deletable with `--force`
- **Media records** pointing to missing files → reported only, **never auto-deleted** (cascade safety: a record may belong to multiple collections, channels, or models)

Detection works at the top-level media directory. Stale conversion or responsive variants inside a valid media directory are not flagged.

## `APP_KEY` rotation

`DefaultMediaResolver::directory()` includes `APP_KEY` in the obfuscation hash: the directory is `{id}-{md5(id . app_key)}`. Rotating the key would silently change every computed path, breaking URLs to all existing media.

**Plan the rotation as a three-step operation**, just like any other `APP_KEY` rotation (encrypted columns, signed URLs, sessions, etc.):

```bash
# 1. Note the current APP_KEY before rotating — you'll need it as --old-key below.
#    (Read it from .env or with `php artisan tinker --execute='echo config("app.key");'`)
OLD_KEY="base64:..."

# 2. Rotate the key. After this, config('app.key') returns the NEW value.
php artisan key:generate

# 3. Rename the on-disk directories. The command uses config('app.key') as the
#    target and the value passed to --old-key as the source.
php artisan mediaman:rotate-paths --old-key="$OLD_KEY"          # dry-run by default
php artisan mediaman:rotate-paths --old-key="$OLD_KEY" --force  # actually move
```

The command iterates `Media` records, computes the directory under the old and new keys, and physically renames on disk when they differ. Options:

- `--old-key` (required) — the value `config('app.key')` returned **before** the rotation. The command compares it against the current `config('app.key')` — if they match, nothing happens, so you must rotate first and then run the command.
- `--force` — actually move files (without it, only reports planned moves)
- `--disk=...` — scope to a specific disk
- `--media=ID` — scope to a single Media id (handy for recovery)

It's safe to re-run: media whose files are already at the new location are reported as "already migrated" and skipped. Media with both old and new directories present (partial previous run) are flagged for manual review without touching anything.

If you'd rather decouple paths from the key entirely, swap the `MediaResolver` for a custom implementation that uses random tokens or another scheme — see [Configuration → Pluggable MediaResolver](configuration.md#pluggable-mediaresolver).

## Custom paths and URLs

If you need to obfuscate URLs further or apply per-tenant directories, swap the `MediaResolver`. See [Configuration → Pluggable MediaResolver](configuration.md#pluggable-mediaresolver).
