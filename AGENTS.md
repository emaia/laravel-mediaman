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

## Docblock conventions

**Selective documentation** — docblocks must add information; if they don't, they're noise. Default to skipping; document deliberately.

### Decision tree

```
1. Is this method part of the public API that app code calls?
   (Facades, fluent builders, model accessors/scopes, trait methods exposed to consumers,
    interface contracts, public methods on Resolver/Model/Collection classes)
   → YES  → docblock required (single-line summary at minimum)
   → NO   → step 2

2. Is the purpose non-obvious from name + parameter types + return type?
   (Cross-class interaction, hidden constraint, unusual side effect, surprising return semantics)
   → YES  → docblock focused on WHY, not what
   → NO   → skip — let the code speak
```

### Apply by category

| Category | Convention |
|---|---|
| `Facades/*`, `MediaUploader` chain, `Models/*` public methods, `Traits/HasMedia` + `ResponsiveImages` public, `Resolvers/MediaResolver` interface, `Resolvers/DefaultMediaResolver` public | **Always docblock** (1-line summary minimum — it's the contract) |
| `Events/*`, `Exceptions/*` | Class-level docblock when purpose isn't self-evident; constructor params auto-documented by type-hint |
| `Console/Commands/*::handle`, `Jobs/*::handle` | Skip — invoked by Laravel/CLI, not app code |
| Internal services (`ImageManipulator`, `ConversionRegistry`, `Support/*`) public methods | Docblock only when the contract isn't obvious from name + types |
| Protected/private methods | Only when **WHY** is non-obvious (extracted-for-a-reason, workaround for X, ordering-sensitive) |
| Trivial accessors (`getXxxAttribute` that returns a single property or simple cast) | Skip — name says it all |
| Trivial forwarders (public alias `useX()` → `setX()`, or 1-line `return $this->getFirst()->method($args)`) | Skip — both name and return type already convey the contract; the underlying method carries the docblock |
| Trivial helpers (`extensionFromMimeType`, single-line conversions) | Skip |
| Test methods | Never docblock — the `it('does X when Y')` name is the documentation |

### What to write

- **Imperative mood**, single sentence ending in a period: `Persist the upload and return the new Media.`
- **Multi-line only** when 2-3 sentences are required to capture a non-obvious constraint or rationale. If you need more, the docblock is masking a design smell — refactor or move the explanation to `docs/*.md`.
- **WHY over what** when explaining: "Extracted so the per-iteration try/catch covers every step" — not "Encodes and persists a conversion" (that's the name).

### Native types first

Always declare native PHP types on properties, parameters, and return values when possible. Drop redundant `@var` / `@param` / `@return` once the native type carries the contract. Untyped signatures combined with a docblock that names the type are an anti-pattern (the type system can't enforce what the docblock says).

Constructors don't need a return type. PHP's `resource` pseudo-type has no native equivalent — use `@param resource $stream` / `@return resource` there.

### When to add `@param` / `@return` / `@throws`

- **`@param` / `@return`**: only when they carry information **beyond** the native type (`array<string, callable>`, `string[]`, `Collection<int, Media>`, `array{conversion: string, exception: \Throwable}`, semantic constraint like "empty array returns all"). Never add when they merely repeat the type.
- **`@throws`**: list exceptions that are part of the method's contract (caller is expected to handle them). Skip generic `RuntimeException` of the "if it breaks it broke" variety.
- **Property `@var`**: only when the native type loses information (`array<int, array{conversion: string, exception: \Throwable}>`, `MediaChannel[]`, `array<string, Collection<int, Media>>`). Plain typed properties don't need it.

### What to always remove

- Auto-generated IDE docblocks: `/** Get the X. */` on `getX()` — pure noise
- `@author`, `@version`, `@since`, `@package` — git/Composer resolve these
- Multi-paragraph essays — move to `docs/*.md` and link
- `@param` / `@return` that just repeat the type-hint
- Comments inside method bodies explaining WHAT the next line does (rename a variable instead); keep only WHY when non-obvious
- Stale TODO/FIXME — convert to an issue or delete

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
- Review required; merge from the GitHub UI (not the CLI)
- Remote merge (GitHub UI):
    - **Default: squash-merge** — for PRs where iterative review/fixup commits should collapse into a single clean commit reflecting the deliverable
    - **Merge commit (no squash)** — for PRs that bundle multiple isolated changes that each deserve their own commit in history
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
- Tags are **v-prefixed** (`vX.Y.Z`, not `X.Y.Z`)
- Versioning follows semver:
    - Patch (`vX.Y.Z+1`): bugfixes
    - Minor (`vX.Y+1.0`): new features
- **Cut the CHANGELOG before tagging.** In a separate commit, rename `## [Unreleased]` → `## [X.Y.Z] — YYYY-MM-DD` in `CHANGELOG.md` and add a fresh empty `## [Unreleased]` above. Push the cut commit BEFORE pushing the tag, otherwise the release workflow fails extracting notes.
- Annotated tag + push:
    ```bash
    git tag -a vX.Y.Z -m "vX.Y.Z"
    git push origin vX.Y.Z
    ```
- The release workflow (`.github/workflows/release.yml`) runs on `v*` tag push and creates the GitHub Release automatically from the matching CHANGELOG section. **Do not pre-create with `gh release create`** — `softprops/action-gh-release` collides with manually created releases.
- CHANGELOG entry format:
    - Title: `## [X.Y.Z] — YYYY-MM-DD`
    - One-sentence summary right under
    - Keep a Changelog sub-sections: `### Added`, `### Changed`, `### Fixed`, `### Removed`. For feature releases, prose H3 sections with code examples are also OK (see `[3.0.0]`)
    - `**Full Changelog**: https://github.com/emaia/laravel-mediaman/compare/vX.Y.Z-1...vX.Y.Z` at the end
- Ask to confirm the message is correct before pushing
