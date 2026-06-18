# Artisan commands

[← Back to README](../README.md)

- [Publish assets](#publish-assets)
- [Clean orphaned files](#clean-orphaned-files)
- [Rotate media paths after APP_KEY rotation](#rotate-media-paths-after-app_key-rotation)
- [Generate responsive images](#generate-responsive-images)
- [Clear responsive images](#clear-responsive-images)
- [Responsive images stats](#responsive-images-stats)

## Publish assets

The fastest path is the one-shot command, which publishes both the config and the migration:

```bash
php artisan mediaman:publish
```

If you only want one or the other (e.g. re-publishing the config after a package update), the individual commands are still available:

```bash
php artisan mediaman:publish-config
php artisan mediaman:publish-migration
```

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

The command also detects reverse orphans (Media records whose file is missing from disk) and reports them for manual review — **DB records are never auto-deleted**. See [Security → mediaman:clean](security.md#detect-orphaned-files).

## Rotate media paths after APP_KEY rotation

The default storage layout hashes `APP_KEY` into each media directory (`{id}-{md5(id . app_key)}`). Rotating the key would silently break URLs to existing media unless you rename the on-disk directories first.

```bash
# Dry-run (default) — reports what would be moved
php artisan mediaman:rotate-paths --old-key="base64:..."

# Actually move
php artisan mediaman:rotate-paths --old-key="base64:..." --force

# Scope to a single disk
php artisan mediaman:rotate-paths --old-key="base64:..." --force --disk=s3-media

# Scope to a single Media id (handy for recovery / partial replays)
php artisan mediaman:rotate-paths --old-key="base64:..." --force --media=42
```

Workflow:

```bash
php artisan mediaman:rotate-paths --old-key="base64:..." --force   # rename on disk
php artisan key:generate                                           # rotate the key
```

The command is idempotent: re-runs against already-migrated media report them as "already migrated" and skip. See [Security → APP_KEY rotation](security.md#app_key-rotation) for context.

## Generate responsive images

Generate responsive variants for existing media:

```bash
# All images without existing responsive variants
php artisan mediaman:generate-responsive

# Force regeneration even if variants already exist
php artisan mediaman:generate-responsive --force

# Limit to a specific collection
php artisan mediaman:generate-responsive --collection="Blog Posts"

# Limit to a single media item
php artisan mediaman:generate-responsive --media=42

# Process inline instead of queuing
php artisan mediaman:generate-responsive --queue=false
```

## Clear responsive images

Remove responsive variants from storage:

```bash
# All media with responsive images (prompts for confirmation)
php artisan mediaman:clear-responsive

# Skip the confirmation prompt
php artisan mediaman:clear-responsive --confirm

# Limit to a specific collection
php artisan mediaman:clear-responsive --collection="Blog Posts"

# Limit to a single media item
php artisan mediaman:clear-responsive --media=42
```

## Responsive images stats

```bash
php artisan mediaman:responsive-stats
```

Output: total image count, images with variants, coverage percentage, and the active configuration (quality, formats, breakpoints, enabled status).
