[![Latest Version on Packagist](https://img.shields.io/packagist/v/emaia/laravel-mediaman.svg?style=flat-square)](https://packagist.org/packages/emaia/laravel-mediaman)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/emaia/laravel-mediaman/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/emaia/laravel-mediaman/actions?query=workflow%3Aci+branch%3Amain)
[![Coverage](https://img.shields.io/codecov/c/github/emaia/laravel-mediaman/main?style=flat-square&logo=codecov)](https://codecov.io/gh/emaia/laravel-mediaman)
[![Total Downloads](https://img.shields.io/packagist/dt/emaia/laravel-mediaman.svg?style=flat-square)](https://packagist.org/packages/emaia/laravel-mediaman)

# Laravel MediaMan

MediaMan is an elegant and powerful media management package for Laravel apps with a painless uploader, virtual collections, automatic conversions and responsive images, and per-model channel associations.

The API is fluent and UI-agnostic — you stay in control of how things look and behave. Equally, at home in a web app or an API server.

## Quick example

```php
$media = MediaUploader::fromRequest('featured_image')
    ->useCollection('Posts')
    ->upload();

$post = Post::find(1);
$post->attachMedia($media, 'featured-image-channel');
```

## Core concepts

- **Uploader** — fluent entry point. Uploads exist independently of any model.
- **Media** — any uploaded file, persisted as its own record. Validate types/sizes in your form requests.
- **Collections** — a virtual group of media; many-to-many with Media. Great for asset libraries.
- **Channels** — per-model tags (`avatar`, `gallery`, …) stored in the polymorphic `mediaman_mediables` pivot. They also drive conversions.
- **Conversions** — transformations registered globally (resize, watermark, format change) and run when media is attached to a channel.
- **Responsive Images** — multi-size, multi-format variants (AVIF/HEIC/WebP/JPG/PNG) with ready-made `<picture>`/`srcset` HTML.

## Documentation

|                                                    |                                                                                                                  |
|----------------------------------------------------|------------------------------------------------------------------------------------------------------------------|
| [**Installation**](docs/installation.md)           | Requirements, composer, publishing assets, upgrade paths                                                         |
| [**Configuration**](docs/configuration.md)         | Disk, queue, image driver, validation, security, MediaResolver, per-variant disk, responsive images, placeholder |
| [**Media**](docs/media.md)                         | The `Media` entity: retrieve, update, delete, HTTP responses, mail, copy/attachTo                                |
| [**Collections**](docs/collections.md)             | `MediaCollection`: CRUD, validation, auto-prune                                                                  |
| [**Models & Channels**](docs/models.md)            | `HasMedia` trait: attach, sync, retrieve, order; channel fallbacks, conversions, validation rules                |
| [**Conversions**](docs/conversions.md)             | Global registration, per-channel execution, per-conversion disk, format detection, failure isolation             |
| [**Responsive images**](docs/responsive-images.md) | Generation, per-format quality, HEIC/AVIF/WebP support, `<picture>` / `srcset`, width calculators                |
| [**Uploads**](docs/uploads.md)                     | `MediaUploader`: `source`, `fromRequest`, `fromDisk`, `fromBase64`, `fromUrl`, `fromStream`, `fromString`        |
| [**Security**](docs/security.md)                   | SVG sanitizer, disallowed extensions, file-size bounds, SSRF guard, orphan cleanup                               |
| [**Events**](docs/events.md)                       | The six package events                                                                                           |
| [**Artisan commands**](docs/commands.md)           | Doctor health check, publish assets, orphan cleanup, conversion/responsive generation and stats                  |
| [**Recipes**](docs/recipes.md)                     | PDF/video thumbnails, image optimization, ZIP downloads, multi-file uploads, partial attach                      |
| [**API reference**](docs/api.md)                   | Public surface by class/trait                                                                                    |

Release history: [CHANGELOG.md](CHANGELOG.md). Upgrade notes: [UPGRADING.md](UPGRADING.md).

## Requirements

| Laravel | Package | PHP  |
|---------|---------|------|
| v12–v13 | 2.x–3.x | 8.3+ |

## Quick install

```bash
composer require emaia/laravel-mediaman
php artisan mediaman:publish
php artisan storage:link
php artisan migrate
```

See [Installation](docs/installation.md) for the full setup, including the upgrade path for existing installations.

## Contribution and license

Found a bug? Open an issue. Feature requests & PRs are welcome.

The MIT License (MIT). Please read the [License File](LICENSE.md) for more information.
