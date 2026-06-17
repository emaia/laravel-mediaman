[![Latest Version on Packagist](https://img.shields.io/packagist/v/emaia/laravel-mediaman.svg?style=flat-square)](https://packagist.org/packages/emaia/laravel-mediaman)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/emaia/laravel-mediaman/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/emaia/laravel-mediaman/actions?query=workflow%3Aci+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/emaia/laravel-mediaman.svg?style=flat-square)](https://packagist.org/packages/emaia/laravel-mediaman)

# Laravel MediaMan

MediaMan is an elegant and powerful media management package for Laravel apps with a painless uploader, virtual collections, automatic conversions and responsive images, and per-model channel associations.

The API is fluent and UI-agnostic — you stay in control of how things look and behave. Equally at home in a web app or an API server.

## Quick example

```php
$media = MediaUploader::fromRequest('featured_image')
    ->useCollection('Posts')
    ->upload();

$post = Post::find(1);
$post->attachMedia($media, 'featured-image-channel');
```

## Core concepts

- **Media** — any uploaded file, persisted as its own record. Validate types/sizes in your form requests.
- **MediaUploader** — fluent entry point. Uploads exist independently of any model.
- **MediaCollection** — a virtual group of media; many-to-many with Media. Great for asset libraries.
- **Channel** — a per-model tag (`avatar`, `gallery`, …) stored in the polymorphic `mediaman_mediables` pivot. Channels also drive conversions.
- **Conversion** — a transformation registered globally (resize, watermark, format change) and run when media is attached to a channel.
- **Responsive Images** — multi-size, multi-format variants (AVIF/WebP/JPG/PNG) with ready-made `<picture>`/`srcset` HTML.

## Documentation

|                                                    |                                                                                   |
|----------------------------------------------------|-----------------------------------------------------------------------------------|
| [**Installation**](docs/installation.md)           | Requirements, composer, publishing assets, upgrade paths                          |
| [**Configuration**](docs/configuration.md)         | Disk, queue, image driver, custom models, URL prefix                              |
| [**Media**](docs/media.md)                         | The `Media` entity: retrieve, update, delete, HTTP responses, mail, copy/attachTo |
| [**Uploads**](docs/uploads.md)                     | `MediaUploader`: `source`, `fromRequest`, `fromDisk`, `fromBase64`, `fromUrl`     |
| [**Models & associations**](docs/models.md)        | `HasMedia` trait, channels, ordering, fallbacks                                   |
| [**Collections**](docs/collections.md)             | `MediaCollection`: CRUD, validation, auto-prune                                   |
| [**Conversions**](docs/conversions.md)             | Global registration, per-channel execution, format detection                      |
| [**Responsive images**](docs/responsive-images.md) | Generation, `<picture>` / `srcset`, width calculators                             |
| [**Security**](docs/security.md)                   | Disallowed extensions, SSRF guard, orphan cleanup                                 |
| [**Events**](docs/events.md)                       | The four package events                                                           |
| [**Artisan commands**](docs/commands.md)           | Publish assets, orphan cleanup, responsive image generation and stats             |
| [**Recipes**](docs/recipes.md)                     | PDF/video thumbnails, image optimization, ZIP downloads, multi-file uploads       |
| [**API reference**](docs/api.md)                   | Public surface by class/trait                                                     |

Release history: [CHANGELOG.md](CHANGELOG.md).

## Requirements

| Laravel | Package | PHP  |
|---------|---------|------|
| v12–v13 | 1.x–2.x | 8.3+ |

`fromUrl()` uploads (introduced in 2.5.0) also require **ext-curl**.

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
