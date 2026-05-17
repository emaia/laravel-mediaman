# Migration Plan: intervention/image v3.x → v4.x

## Current State

- **Package**: `intervention/image` `^3.0` (locked at `3.11.8`)
- **PHP constraint**: `^8.3` (bumped in v1.6.4)
- **Installed PHP**: `8.4.20`
- **Driver**: centralized in `MediaManServiceProvider::registerImageManager()` via `config('mediaman.driver')`
  - Default `imagick`, env `MEDIAMAN_DRIVER`
  - All classes receive `ImageManager` via DI — no hardcoded fallbacks

## Breaking Change Impact

**SIM — esta atualizacao e BC para usuarios do pacote.**

Os usuarios que registram conversoes customizadas via `Conversion::register()` escrevem callbacks que invocam metodos diretamente no objeto `Image` da Intervention. Com a migracao para v4, varios metodos desses objetos mudam de nome ou assinatura, causando `Call to undefined method` em runtime.

### Exemplo do que quebra

```php
// v3 (quebra em v4)
Conversion::register('thumb', function (Image $image) {
    return $image->resize(200, 200)->toWebp(80);
});

// v4 equivalente
Conversion::register('thumb', function (Image $image) {
    return $image->resize(200, 200)->encodeUsingFormat(Format::WEBP, quality: 80);
});
```

### Quem NAO e afetado

- Usuarios que usam apenas as funcionalidades padrao do pacote (sem conversions customizadas)
- Usuarios que nao injetam `ImageManager` diretamente (o ServiceProvider gerencia)

### Mitigacao

O pacote precisara de um **major version bump** proprio e documentar claramente as mudancas nos callbacks.

---

## Pre-requisitos

| Item | De | Para | Status |
|------|-----|------|--------|
| PHP | `^8.2` | `^8.3` | Feito (v1.6.4) |
| intervention/image | `^3.0` | `^4.0` | Pendente |

---

## Mapeamento Completo de APIs (v3 → v4)

### ImageManager — Instanciacao

| v3 | v4 |
|----|----|
| `ImageManager::imagick()` | `ImageManager::usingDriver(ImagickDriver::class)` |
| `ImageManager::gd()` | `ImageManager::usingDriver(GdDriver::class)` |
| `new ImageManager(Driver::class)` | `new ImageManager(Driver::class)` — sem mudanca |

### ImageManager — Leitura de imagens

| v3 | v4 |
|----|----|
| `$manager->read($mixed)` | `$manager->decode($mixed)` |
| — | `$manager->decodePath($path)` (novo, especifico) |
| — | `$manager->decodeStream($stream)` (novo, especifico) |
| — | `$manager->decodeBinary($binary)` (novo, especifico) |
| — | `$manager->decodeBase64($base64)` (novo, especifico) |
| — | `$manager->decodeDataUri($dataUri)` (novo, especifico) |
| — | `$manager->decodeSplFileInfo($spl)` (novo, especifico) |

`decode()` aceita todos os mesmos tipos de `read()` — e drop-in replacement.

### ImageManager — Criacao

| v3 | v4 |
|----|----|
| `$manager->create($w, $h)` | `$manager->createImage($w, $h)` |
| `$manager->animate(callable)` | `$manager->createImage($w, $h, $callback)` |

### Image — Encoding (Alto Impacto)

| v3 | v4 |
|----|----|
| `$image->toJpeg($q)` | `$image->encodeUsingFormat(Format::JPEG, quality: $q)` |
| `$image->toPng()` | `$image->encodeUsingFormat(Format::PNG)` |
| `$image->toGif()` | `$image->encodeUsingFormat(Format::GIF)` |
| `$image->toWebp($q)` | `$image->encodeUsingFormat(Format::WEBP, quality: $q)` |
| `$image->toAvif($q)` | `$image->encodeUsingFormat(Format::AVIF, quality: $q)` |
| `$image->toBmp()` | `$image->encodeUsingFormat(Format::BMP)` |
| `$image->toTiff()` | `$image->encodeUsingFormat(Format::TIFF)` |
| `$image->toHeic()` | `$image->encodeUsingFormat(Format::HEIC)` |
| `$image->toJp2()` | `$image->encodeUsingFormat(Format::JPEG2000)` |
| `$image->encodeByMediaType()` | `$image->encode()` (AutoEncoder detecta formato) |
| `$image->encodeByMediaType($type)` | `$image->encodeUsingMediaType($type)` |
| `$image->encodeByExtension($ext)` | `$image->encodeUsingFileExtension($ext)` |
| `$image->encodeByPath($path)` | `$image->encodeUsingPath($path)` |

### EncodedImage

| v3 | v4 |
|----|----|
| `$encoded->toFilePointer()` | `$encoded->toStream()` |
| `$encoded->toString()` | `(string) $encoded` (via `__toString()`) |
| `$encoded->mediaType()` | `$encoded->mediaType()` — sem mudanca |
| `$encoded->toDataUri()` | `$encoded->toDataUri()` — retorna `DataUriInterface` em vez de `string` |
| `$encoded->save($path)` | `$encoded->save($path)` — sem mudanca |

### Image — Outros metodos renomeados (Medio Impacto)

| v3 | v4 |
|----|----|
| `$image->crop(w, h, offset_x, offset_y)` | `$image->crop(w, h, x, y)` |
| `$image->pickColor(x, y, frame_key)` | `$image->colorAt(x, y, frame)` |
| `$image->pickColors(x, y)` | `$image->colorsAt(x, y)` |
| `$image->place(src, offset_x, offset_y, opacity)` | `$image->insert(src, x, y, transparency)` (float) |
| `$image->pad(w, h)` | `$image->containDown(w, h)` |
| `$image->flop()` | `$image->flip(Direction::HORIZONTAL)` |
| `$image->greyscale()` | `$image->grayscale()` |
| `$image->blendTransparency()` | `$image->fillTransparentAreas()` |
| `$image->setBlendingColor()` | `$image->setBackgroundColor()` |
| `$image->blendingColor()` | `$image->backgroundColor()` |
| `$image->rotate()` | gira clockwise por padrao (v3 era counter-clockwise) |
| `$image->reduceColors()` | assinatura do parametro `$background` mudou |
| `Font::filename()` | `Font::filepath()` |
| `Font::valignment()` | `Font::verticalAlignment()` |
| `Font::alignment()` | `Font::horizontalAlignment()` |

### Cores

| v3 | v4 |
|----|----|
| `ColorInterface::convertTo()` | `ColorInterface::toColorspace()` |
| `ColorInterface::isGreyscale()` | `ColorInterface::isGrayscale()` |
| `ColorInterface::toArray()` | Removido — usar `channels()` |
| string `'transparent'` | `Color::transparent()` |
| Alpha: inteiro 0–100 | Alpha: float 0.0–1.0 |
| Config `blendingColor` | Config `backgroundColor` |

### APIs que PERMANECEM inalteradas

- `$image->width()`, `$image->height()`
- `$image->resize($w, $h)`
- `$image->resizeCanvas($w, $h)`
- `$image->scale($w, $h)`, `$image->scaleDown($w, $h)`
- `$image->crop($w, $h)`
- `$image->fill($color)`
- `$image->save($path, ...$options)`
- `$image->flip()` (nova assinatura com `Direction`)
- `$encoded->mediaType()`
- `clone $image`

---

## Arquivos a Modificar

> **Nota:** A infraestrutura de driver foi centralizada em v1.6.0. O `ImageManager` e injetado via container em todas as classes. Nao ha mais `ImageManager::imagick()` / `ImageManager::gd()` hardcoded nos arquivos individuais.

### 1. `src/MediaManServiceProvider.php` — Driver singleton (1 linha)

```php
// v3 (hoje)
return config('mediaman.driver') === 'gd'
    ? ImageManager::gd()
    : ImageManager::imagick();

// v4
$driver = config('mediaman.driver');
return ImageManager::usingDriver(
    match ($driver) {
        'imagick' => \Intervention\Image\Drivers\Imagick\Driver::class,
        'gd' => \Intervention\Image\Drivers\Gd\Driver::class,
        default => throw new \InvalidArgumentException("..."),
    }
);
```

### 2. `src/ImageManipulator.php` — 4 API changes

| v3 | v4 |
|----|----|
| `$this->imageManager->read($stream)` | `$this->imageManager->decode($stream)` |
| `$image->toFilePointer()` | `$image->toStream()` |
| `$image->encodeByMediaType()` | `$image->encode()` |
| `$encoded->toFilePointer()` | `$encoded->toStream()` |

### 3. `src/Traits/ResponsiveImages.php` — 1 API change

| v3 | v4 |
|----|----|
| `$imageManager->read($tempFile)` | `$imageManager->decode($tempFile)` |

> O `app(ImageManager::class)` ja esta correto — o container devolve o singleton.

### 4. `src/ResponsiveImages/ResponsiveImageGenerator.php` — 5 API changes

| v3 | v4 |
|----|----|
| `$this->imageManager->read($stream)` | `$this->imageManager->decode($stream)` |
| `$image->toWebp($quality)` | `$image->encodeUsingFormat(Format::WEBP, quality: $quality)` |
| `$image->toAvif($quality)` | `$image->encodeUsingFormat(Format::AVIF, quality: $quality)` |
| `$image->toPng()` | `$image->encodeUsingFormat(Format::PNG)` |
| `$image->toJpeg($quality)` | `$image->encodeUsingFormat(Format::JPEG, quality: $quality)` |
| `$encodedImage->toFilePointer()` | `$encodedImage->toStream()` |
| `$encodedImage->toString()` | `(string) $encodedImage` |

> Adicionar `use Intervention\Image\Format;`.

### 5. `src/ResponsiveImages/WidthCalculator/BreakpointWidthCalculator.php` — 1 API change

| v3 | v4 |
|----|----|
| `$this->imageManager->read($imagePath)` | `$this->imageManager->decode($imagePath)` |

### 6. `src/ResponsiveImages/WidthCalculator/FileSizeOptimizedWidthCalculator.php` — 1 API change

| v3 | v4 |
|----|----|
| `$this->imageManager->read($imagePath)` | `$this->imageManager->decode($imagePath)` |

### 7. `src/ConversionRegistry.php` — Format detection array

O metodo `detectFormat()` contem um array com strings como `'toWebp('`, `'toAvif('` etc. Na v4 os usuarios escreverao `encodeUsingFormat(Format::WEBP` em vez de `toWebp(`. O array precisa reconhecer ambos (v3 e v4) durante o periodo de transicao, ou ser atualizado apos a migracao:

```php
// Adicionar patterns v4 ao lado dos v3:
'toWebp(' => MediaFormat::WEBP->value,
'encodeUsingFormat(Format::WEBP' => MediaFormat::WEBP->value,
// ... mesmo para os demais formatos
```

### 8. `src/ResponsiveImages/ResponsiveConversion.php`

**Nenhuma alteracao necessaria** — apenas importa `Image` como type hint.

### 9. Test files

**`tests/Feature/ImageManipulatorTest.php`:**

| v3 | v4 |
|----|----|
| `$manager->create(640, 480)` | `$manager->createImage(640, 480)` |
| `$manager->read(...)` | `$manager->decode(...)` |

**`tests/Feature/FormatDetectionTest.php`:**

| v3 | v4 |
|----|----|
| + `use Intervention\Image\Format;` | |
| `->toWebp()` | `->encodeUsingFormat(Format::WEBP)` |
| `->toAvif()` | `->encodeUsingFormat(Format::AVIF)` |
| `->toPng()` | `->encodeUsingFormat(Format::PNG)` |

### 10. `composer.json`

| Linha | De | Para |
|-------|-----|------|
| `"intervention/image"` | `"^3.0"` | `"^4.0"` |

> PHP `^8.3` ja aplicado (v1.6.4).

---

## Ordem de Execucao Recomendada

1. Bump `composer.json`: `"intervention/image": "^4.0"` + `composer update`
2. Atualizar `MediaManServiceProvider.php` (driver singleton — 1 linha)
3. Atualizar `read()` → `decode()` nos 5 pontos (3 source files + 2 tests)
4. Atualizar encoding calls no `ResponsiveImageGenerator` + `FormatDetectionTest`
5. Atualizar `toFilePointer()` → `toStream()` (2 source files)
6. Atualizar `encodeByMediaType()` → `encode()` (ImageManipulator)
7. Atualizar `create()` → `createImage()` (ImageManipulatorTest)
8. Atualizar array `detectFormat()` no `ConversionRegistry` (adicionar patterns v4)
9. Rodar testes, ajustar

---

## Links de Referencia

- Upgrade Guide: https://image.intervention.io/v4/getting-started/upgrade
- Instantiation (decode/create): https://image.intervention.io/v4/basics/instantiation
- Configuration & Drivers: https://image.intervention.io/v4/getting-started/configuration-drivers
- Image Output (encoding): https://image.intervention.io/v4/basics/image-output
- Framework Integration (Laravel): https://image.intervention.io/v4/getting-started/frameworks
