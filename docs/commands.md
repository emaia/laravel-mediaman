# Artisan commands

[← Back to README](../README.md)

- [Publish assets](#publish-assets)
- [Doctor (health check)](#doctor-health-check)
- [Clean orphaned files](#clean-orphaned-files)
- [Rotate media paths after APP_KEY rotation](#rotate-media-paths-after-app_key-rotation)
- [Stats (consolidated)](#stats-consolidated)
- [Generate conversions](#generate-conversions)
- [Clear conversions](#clear-conversions)
- [Generate responsive](#generate-responsive)
- [Clear responsive](#clear-responsive)
- [Prune responsive generations](#prune-responsive-generations)

## Publish assets

The fastest path is the one-shot command, which publishes both the config and the migration:

```bash
php artisan mediaman:publish
```

If you only want one or the other (e.g. re-publishing the config after a package update), the individual commands are
still available:

```bash
php artisan mediaman:publish-config
php artisan mediaman:publish-migration
```

## Doctor (health check)

End-to-end diagnostic with no durable application mutation. Useful as a smoke test after deployment, after `APP_KEY` rotation, when adopting `vips`, when adding a new responsive format, or while debugging "the URL returns 404 but the record exists" issues. Disk probes write a unique scratch file (`mediaman-doctor-probe-{rand}.txt`) and delete it on the same call, which can still appear in remote-storage audit logs or notifications; image-driver probes are memory-only.

```bash
php artisan mediaman:doctor
```

Sections run in order and each is independent — a failure in one doesn't block the others. Every `warn` and `error` line carries an actionable hint in the output, so the table below describes only the surface each section covers (not the hint text, which lives at runtime).

| Section | What it surfaces |
|---|---|
| **Schema migrations** | All 4 expected tables exist (respects `mediaman.tables.*` overrides). |
| **Config file** | Whether `config/mediaman.php` was published, plus responsive generation strategy, retention, in-progress timeout, and validation. |
| **Disk** | Write/read/delete probe on the main disk **and** on every distinct variant disk in use (per-conversion `register(..., disk: 'X')` registrations + the `mediaman.conversions.disk` and `mediaman.responsive_images.disk` defaults), dedup'd against the main disk. See [Conversions → Conversion disk](conversions.md#conversion-disk). |
| **Public symlink** | For each `filesystems.links` entry pointing at the effective disk's `root`: the symlink exists, points where expected, and isn't squatted by a regular file. Local-driver only — S3/SFTP/etc. are skipped. Disks with no matching link warn that `getUrl()` cannot expose their files; private disks should use temporary or authenticated responses. |
| **Image driver** | The effective driver class, a real 1×1 PNG encode (Vips loads FFI bindings only on the first encode — without this, doctor would report "ok" for a class that can't actually do work), the SAPI + `ffi.enable` value when Vips is the driver (with a per-SAPI verification hint), and a 10×10 encode probe for **each** format in `responsive_images.formats` to surface codec gaps before they hit a real upload. See [Responsive images → Verifying driver/codec support](responsive-images.md#verifying-driver-codec-support). |
| **Queue** | The configured queue connection, plus a warning when `auto_generate=true` **and** `queue=true` (a worker must be running for uploads to materialize responsive variants). |
| **Conversions** | Number of conversions registered via `Conversion::register()`. |
| **Security** | Hardening drift — flags empty `allowed_mime_types`, `svg.enabled=true`, and `block_disallowed_extensions=false` against the defaults in [Security → Hardening](security.md#hardening-recommendations). |
| **Media inventory** | Total records, sum of `size` (human-formatted), and responsive coverage `X / Y (Z%)` when records exist. |

Exit code is `1` whenever any section emits an error; warnings and info don't affect the exit code. Use the exit code for CI integration; rely on the hints in the output for triage.

## Clean orphaned files

Detect and (optionally) remove files on disk without a corresponding Media record:

```bash
# Dry run (default) — reports without deleting
php artisan mediaman:clean

# Delete orphaned files on disk
php artisan mediaman:clean --force

# Scope to a specific disk
php artisan mediaman:clean --disk=media
```

The command also detects reverse orphans (Media records whose file is missing from disk) and reports them for manual
review — **DB records are never auto-deleted**. See [Security → mediaman:clean](security.md#detect-orphaned-files).
Files inside a valid media directory are intentionally outside this command's scope. Use
`mediaman:prune-responsive-generations` for inactive versioned responsive directories.

## Rotate media paths after APP_KEY rotation

The default storage layout hashes `APP_KEY` into each media directory (`{id}-{md5(id . app_key)}`). Rotating the key
would silently break URLs to existing media unless you rename the on-disk directories.

The command computes the target directory using the **current** `config('app.key')` and the source directory using the
`--old-key` you pass. **You must rotate the key first**, then run the command with the previous key as `--old-key`. If
`--old-key` equals the current `config('app.key')`, the command reports "Nothing to rotate" and exits — that's the noop
you get when the order is reversed.

Workflow:

```bash
# 1. Note the current APP_KEY before rotating
OLD_KEY="base64:..."

# 2. Rotate the key
php artisan key:generate

# 3. Rename on-disk directories using the saved old key
php artisan mediaman:rotate-paths --old-key="$OLD_KEY"          # dry-run
php artisan mediaman:rotate-paths --old-key="$OLD_KEY" --force  # actually move
```

Scoping options:

```bash
# Scope to a single disk
php artisan mediaman:rotate-paths --old-key="$OLD_KEY" --force --disk=s3-media

# Scope to a single Media id (handy for recovery / partial replays)
php artisan mediaman:rotate-paths --old-key="$OLD_KEY" --force --media=42
```

The command is idempotent: re-runs against already-migrated media report them as "already migrated" and skip.
See [Security → APP_KEY rotation](security.md#app_key-rotation) for context.

Responsive generation versioning must be disabled before rotation because a generation can start between filesystem
checks and path movement. Restore revalidating cache headers first, wait for cached HTML to expire, disable versioning,
clear responsive images and retained generations, rotate paths, then re-enable and regenerate. Active clear/prune state,
retained generation-disk history, unverified markers, and old/new path conflicts also return a non-zero exit instead of
silently creating responsive 404s.

Dry-run is strictly read-only: it does not persist rotation claims, bump `updated_at`, or increment the responsive generation epoch.

## Stats (consolidated)

Show media, conversion, and responsive image statistics. Without flags, a consolidated dashboard is shown. Use
`--responsive` or `--conversions` for detailed breakdowns.

```bash
# Consolidated overview
php artisan mediaman:stats

# Detailed responsive images stats
php artisan mediaman:stats --responsive

# Detailed conversion stats
php artisan mediaman:stats --conversions

# Both detailed sections
php artisan mediaman:stats --responsive --conversions
```

The consolidated view shows media inventory (records, total size, image records), registered conversion names, and
responsive coverage with current config. The `--responsive` detail adds per-format configuration, generation strategy,
retention, and legacy/versioned manifest counts. The `--conversions` detail shows each registered conversion with its
detected output format.

## Generate conversions

Generate (or regenerate) registered conversions for existing media. Useful after changing a conversion definition (e.g.
you tweaked the closure for `thumb` and want all stored thumbnails refreshed) or backfilling a newly-registered
conversion for historical media.

```bash
php artisan mediaman:generate-conversions --conversion=thumb,cover
```

The `--conversion` flag is required. Names are validated against the `ConversionRegistry` — unknown names short-circuit
with a clear error before any work starts.

Filters:

| Flag                   | Effect                                                                                                            |
|------------------------|-------------------------------------------------------------------------------------------------------------------|
| `--media=1,3,5..10`    | Restrict to specific media ids; supports comma-separated values and ranges (`from..to`). Mix freely (`1,3..5,9`). |
| `--collection=avatars` | Restrict to media attached to a named collection.                                                                 |

Behavior:

| Flag      | Default | Effect                                                                                                                                              |
|-----------|---------|-----------------------------------------------------------------------------------------------------------------------------------------------------|
| `--force` | off     | Overwrite existing conversion files. Default skips when the target already exists on disk.                                                          |
| `--queue` | off     | Dispatch each item as a `PerformConversions` job instead of running synchronously. Useful for large catalogs where you don't want to block the CLI. |

A confirmation prompt fires when the operation count (`media × conversions`) crosses 100, so a typo in `--media` doesn't
silently start thousands of jobs.

## Clear conversions

Remove conversion files for existing media. Useful when re-uploading after format changes or cleaning up stale
conversions.

```bash
php artisan mediaman:clear-conversions --conversion=thumb,cover
```

The `--conversion` flag is required. Names are validated against the `ConversionRegistry` — unknown names short-circuit
before any work starts.

Filters:

| Flag                   | Effect                                                                                                            |
|------------------------|-------------------------------------------------------------------------------------------------------------------|
| `--media=1,3,5..10`    | Restrict to specific media ids; supports comma-separated values and ranges (`from..to`). Mix freely (`1,3..5,9`). |
| `--collection=avatars` | Restrict to media attached to a named collection.                                                                 |

Behavior:

| Flag      | Default | Effect                        |
|-----------|---------|-------------------------------|
| `--force` | off     | Skip the confirmation prompt. |

A confirmation prompt fires when the operation count crosses 100. Converted files are deleted from disk (conversions are
filesystem-only — there is no database metadata to reset).

## Generate responsive

Generate responsive variants for existing media:

```bash
# All images without existing responsive variants (inline processing)
php artisan mediaman:generate-responsive

# Force regeneration even if variants already exist
php artisan mediaman:generate-responsive --force

# Limit to specific media ids with range support
php artisan mediaman:generate-responsive --media=1,3,5..10

# Limit to a specific collection
php artisan mediaman:generate-responsive --collection="Blog Posts"

# Dispatch as queued jobs instead of inline
php artisan mediaman:generate-responsive --queue
```

The command resolves `mediaman.models.media`, supports opaque custom-model keys, and processes matching records in bounded pages. Queued jobs use the connection configured by `mediaman.queue`. Inline mode continues after individual failures and returns exit code `1` when any item fails.

## Clear responsive

Remove responsive variants from storage:

```bash
# All media with responsive images (prompts for confirmation)
php artisan mediaman:clear-responsive

# Skip the confirmation prompt
php artisan mediaman:clear-responsive --force

# Limit to a specific collection
php artisan mediaman:clear-responsive --collection="Blog Posts"

# Limit to specific media ids with range support
php artisan mediaman:clear-responsive --media=1,3,5..10
```

Clear evaluates every selected media record, including non-raster records whose MIME type changed after responsive metadata was created. It first unpublishes responsive metadata and invalidates already-running generation jobs, then removes files. A storage cleanup failure leaves a retryable tombstone containing the original disk/base information, and generation stays blocked until a later clear retry succeeds. During `APP_KEY` rotation, keep the old key in Laravel's `APP_PREVIOUS_KEYS` until every tombstone has cleared; retries authenticate against that key ring and fail closed if the issuing key is unavailable. The command continues across media and returns exit code `1` when any item fails.

## Prune responsive generations

Report or remove inactive ULID generation directories. Legacy files, unknown directories, active manifest references, and fresh in-progress markers are never deleted.

```bash
# Dry run using responsive_images.version_retention_days
php artisan mediaman:prune-responsive-generations

# Delete eligible generations
php artisan mediaman:prune-responsive-generations --force

# Override age and scope
php artisan mediaman:prune-responsive-generations --older-than=14 --media=1,3..10
php artisan mediaman:prune-responsive-generations --collection="Blog Posts"

# Scan a previously configured responsive disk
php artisan mediaman:prune-responsive-generations --disk=old-responsive --force
```

The command returns non-zero when configuration, listing, marker validation, active-state validation, or deletion fails, while continuing with other generations and media where safe. Candidate output includes file count and bytes when the filesystem adapter exposes both values. It streams media records rather than loading the full catalog, and dry-run reads current state without acquiring row locks or pruning claims.

Scheduling is application-owned:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mediaman:prune-responsive-generations --force')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
```

`onOneServer()` requires a shared lock-capable cache. Retention should exceed the longest HTML/page-cache lifetime, queue delay, generation duration, and deployment rollback window.
