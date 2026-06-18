# Security

[← Back to README](../README.md)

- [Disallowed file extensions](#disallowed-file-extensions)
- [SSRF protection for remote URLs](#ssrf-protection-for-remote-urls)
- [Detect orphaned files](#detect-orphaned-files)
- [`APP_KEY` rotation](#app_key-rotation)
- [Custom paths and URLs](#custom-paths-and-urls)

## Disallowed file extensions

MediaMan blocks executable and server-side file extensions on upload by default. The blocklist:

`php`, `phtml`, `phar`, `shtml`, `htaccess`, `cgi`, `pl`, `asp`, `aspx`, `jsp`, `jspx`

Configure or disable in `config/mediaman.php`:

```php
'block_disallowed_extensions' => true,
'disallowed_extensions' => [
    'php', 'phtml', 'phar', 'shtml', 'htaccess',
    'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx',
],
```

Uploads with a disallowed extension throw `Emaia\MediaMan\Exceptions\DisallowedExtension`.

The check runs against the **sanitized** filename, so double-extension attacks (`malware.php.jpg`) are defused before validation: the dot is replaced (`malware-php.jpg`) and the extension becomes `jpg`.

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

`DefaultPathGenerator` includes `APP_KEY` in the obfuscation hash: the directory is `{id}-{md5(id . app_key)}`. Rotating the key would silently change every computed path, breaking URLs to all existing media.

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

If you'd rather decouple paths from the key entirely, swap `PathGenerator` for a custom implementation that uses random tokens or another scheme — see [Configuration → Pluggable generators](configuration.md#pluggable-generators).

## Custom paths and URLs

If you need to obfuscate URLs further or apply per-tenant directories, swap the `PathGenerator` and/or `UrlGenerator`. See [Configuration → Pluggable generators](configuration.md#pluggable-generators).
