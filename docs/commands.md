# Artisan commands

[← Back to README](../README.md)

- [Publish assets](#publish-assets)
- [Clean orphaned files](#clean-orphaned-files)
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
