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

Read-only end-to-end diagnostic of the MediaMan pipeline. Useful as a smoke test after deployment, after `APP_KEY`
rotation, or while debugging "the URL returns 404 but the record exists" issues.

```bash
php artisan mediaman:doctor
```

Output (truncated):

```
  Schema migrations ........................................................
  Tables present ............................. ✓ all 4 expected tables found

  Config file ..............................................................
  Published .................................... ✓ at config/mediaman.php

  Disk .....................................................................
  Configured .................................................... · 'media'
  Probe (write/read/delete) ............................................ ✓ OK

  Public symlink ...........................................................
  Symlink ............... ✓ /var/www/public/media → /var/www/storage/app/media

  Image driver .............................................................
  Configured ........................................... · null (auto-detect)
  Effective ..................... ✓ Intervention\Image\Drivers\Imagick\Driver

  Queue ....................................................................
  Connection ..................................................... · 'database'
  Auto-generate responsive ⚠ enabled — ensure a queue worker is running

  Conversions ..............................................................
  Registered ....................................................... · 5

  Media inventory ..........................................................
  Records .......................................................... · 1,247
  Total size ........................................................ · 3.2 GB
  Responsive coverage ................................... · 1,022 / 1,247 (82%)
```

The layout mirrors Laravel's `php artisan about` style — section headers + label/value rows separated by dots. Status
icons are embedded in the value column: `✓` (green, OK), `⚠` (yellow, warning), `✗` (red, error), `·` (dim gray,
informational).

The command never mutates state — disk probe writes a unique scratch file (`mediaman-doctor-probe-{rand}.txt`), reads it
back, and deletes it. Failures (disk not configured, libvips broken at the driver constructor probe, schema missing,
link path squatted by a real file) print `✗` and the process exits with code `1`. Warnings (e.g. `auto_generate=true` +
`queue=true`, missing symlink that should exist) print `⚠` and keep the exit code at `0`.

The **public symlink** check looks at `config('filesystems.links')` for entries whose target equals the effective disk's
`root`. For each match it verifies the symlink exists and points where expected. Remote drivers (S3, etc.) are skipped
automatically. Local disks without any matching link entry print an informational note — the disk may be intentionally
private, or the user simply hasn't added the link to `filesystems.links` yet (and therefore `php artisan storage:link`
wouldn't create anything for it).

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
responsive coverage with current config. The `--responsive` detail adds per-format configuration (quality, formats,
breakpoints, width calculator). The `--conversions` detail shows each registered conversion with its detected output
format.

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
