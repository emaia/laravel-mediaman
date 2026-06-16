# MediaMan — Laravel Media Management Package

## Project Overview

MediaMan (`emaia/laravel-mediaman`) is an independent media management package for Laravel 12–13 with a focus on:
- **Media-first architecture**: files exist as standalone entities before attaching to models
- **Virtual collections**: database-backed `MediaCollection` model for grouping media
- **Polymorphic channels**: tagged associations between models and media
- **Image conversions**: globally registered, queueable, with auto format detection
- **Responsive images**: multi-format (AVIF, WebP, JPG, PNG), breakpoint-based or file-size-optimized, with native `<picture>` HTML generation

## Commands

```bash
# Run tests
./vendor/bin/pest

# Run specific test filter
./vendor/bin/pest --filter "Keyword"

# Static analysis
./vendor/bin/phpstan analyse

# Format code
vendor/bin/pint

# Publish package assets (from within a Laravel app)
php artisan mediaman:publish-config
php artisan mediaman:publish-migration
```

## Architecture

```
src/
  Casts/Json.php                  — Custom JSON cast for custom_properties
  Console/Commands/               — 5 artisan commands
  ConversionRegistry.php          — Global singleton for named image conversions
  Enums/                          — MediaFormat, MediaType
  Events/                         — MediaUploaded, MediaDeleted, ConversionCompleted, ResponsiveImagesGenerated
  Exceptions/                     — FileSizeExceeded, InvalidConversion, MimeTypeNotAllowed
  Facades/Conversion.php          — Facade for ConversionRegistry
  ImageManipulator.php            — Executes conversion closures via Intervention Image
  Jobs/                           — PerformConversions, GenerateResponsiveImages (ShouldQueue)
  MediaChannel.php                — Value object linking channels to conversions
  MediaManServiceProvider.php     — Container bindings + boot registrations
  MediaUploader.php               — Fluent upload pipeline (validate → create model → store → attach → event)
  Models/                         — Media (core), MediaCollection (DB-backed collections)
  ResponsiveImages/               — Generator, conversions, two width calculators
  Traits/                         — HasMedia (model trait), ResponsiveImages (media model trait), ResolvesModels
```

## Key Design Decisions

1. **Media is independent**: `MediaUploader::source($file)->upload()` creates media without needing a parent model. Models attach via `HasMedia` trait later.

2. **Channels ≠ Collections**: "Channels" are tags on the polymorphic pivot (e.g., "avatar", "gallery") per model. "Collections" are DB-backed groups that can span multiple models. Both are independent axes of organization.

3. **Obfuscated paths**: Each media gets its own directory `{id}-{md5(id.app_key)}` to prevent URL guessing.

4. **Global conversions**: Registered once via `Conversion::register('name', fn)` and reused across any model/channel. Format is auto-detected at registration time via reflection.

5. **Responsive images are first-class**: `getPictureHtml()` generates complete `<picture>` elements with `<source>` tags per format, ordered by modern format priority (AVIF > WebP > JPG > PNG).

6. **Disk migration**: Changing `disk` or `file_name` on a Media model triggers automatic physical file move/rename.

## Database Tables

| Table | Purpose |
|-------|---------|
| `mediaman_media` | Core media records (disk, name, file_name, mime_type, size, custom_properties) |
| `mediaman_collections` | Virtual directories/groups (name) |
| `mediaman_collection_media` | Many-to-many pivot: collections ↔ media |
| `mediaman_mediables` | Polymorphic pivot: any model ↔ media + channel field |

## Testing

- Framework: **Pest**
- Located in `tests/`
- Uses `orchestra/testbench` for package testing (no Laravel app needed)
- Factory: `database/factories/MediaFactory.php`

## Dependencies

- `intervention/image` ^4.0 — image processing
- `illuminate/support`, `illuminate/database`, `illuminate/validation` ^12.0|^13.0

## Reference

- Benchmark comparison: `review.md` in project root
- Implementation plan: `plan.md` in project root
- Spatie MediaLibrary source (for competitive analysis): `/home/dina/Projects/laravel-medialibrary/`
- Spatie docs: https://spatie.be/docs/laravel-medialibrary/v11/introduction

## Git Workflow

### Commits

- **Subject**: Imperative mood, no period at end (e.g. `Add malicious extension blocking`)
- **Body**: Bullet points prefixed with `-`, each describing a specific change (short)
- **PR reference**: Appended as `(#N)` in the subject when applicable
- Always signed (GPG)
- Ask to confirm the message is correct before pushing

### Pull Requests

- Push feature branch, open PR on GitHub
- Branch naming: descriptive kebab-case (e.g. `security-extensions`)
- PR title matches commit subject convention; body summarizes changes
- Review required; merge manually (do not squash-merge from the CLI)
- Remote merge (GitHub UI) squashes the branch into a single commit on `main`
- Ask to confirm the message is correct before pushing
- **PR body template** — `## Summary` (bullet points) + `## Test plan`. The Test plan combines automated checks
  with a manual smoke section covering what tests can't verify (visual, browser-specific behavior, real
  interaction). Each item is a checkbox so the reviewer can tick it as they go.

  Example:

  ```markdown
  ## Test plan

  - [ ] `composer test` — N/N passing
  - [ ] Manual smoke: <render this component in a fresh app, click X, expect Y; tweak prop Z, expect Y'>
  - [ ] Manual smoke: <one scenario per non-trivial code path — error path, edge case, prop variant>
  - [ ] Manual smoke: <accessibility / keyboard / screen reader if relevant>
  ```

  Skip the manual lines that don't apply (a pure internal refactor with full test coverage may legitimately
  have only the automated lines).

### Tag and Release

- Tags are created on `main` after the PR is merged remotely
- Versioning follows `2.X.Y` semver:
    - Patch (`2.1.1`): bugfixes
    - Minor (`2.2.0`): new features
- Annotated tag: `git tag -a 2.2.0 -m "2.2.0"`
- Release created via `gh release create 2.2.0 --title "2.2.0" --notes-file /tmp/release-notes.md`
- Release notes format:
    - Markdown title with feature name (e.g. `## Responsive image picture HTML`)
    - One-sentence summary
    - Section per feature with code examples
    - `**Full Changelog**: https://github.com/emaia/laravel-mediaman/compare/v2.1.0...v2.2.0` at the end
- CHANGELOG.md is updated automatically by the release workflow; do not edit manually
- Ask to confirm the message is correct before pushing
