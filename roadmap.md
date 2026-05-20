# MediaMan Roadmap

## Concluido

### Qualidade de Codigo (Q1-Q6)

- [x] **Q1** Type declarations completas em ~17 metodos across 8 arquivos
- [x] **Q2** Logging nos catches silenciosos (5 locais em 3 arquivos)
- [x] **Q3** Enums `MediaFormat` e `MediaType` + constantes no modelo Media, substituindo magic strings
- [x] **Q4** Testes para 5 comandos Artisan console
- [x] **Q5** Testes para responsive images trait e width calculators
- [x] **Q6** Testes de cenarios de erro (upload, model, HasMedia, responsive)

### Performance

- [x] **P1** Mapa de MIME types unificado
  - `Media::getExtensionFromMimeType()` e `ImageManipulator::getExtensionFromMimeType()` delegavam para `MediaFormat::extensionFromMimeType()` (ja feito)
- [x] **P2** Pre-computar formato da conversao no registro (v1.7.0)
  - ReflectionFunction + SplFileObject movidos do Model para `ConversionRegistry::register()`
  - Formato detectado 1x no boot, nunca durante request. Compativel com Octane
- [x] **P4** Indice composto em `mediaman_mediables`
  - Migration com `(mediable_type, mediable_id, channel)`

### Seguranca

- [x] **S1** Validacao de MIME type no upload (ja implementado)
  - Config `allowed_mime_types`, `MediaUploader::validateMimeType()`, `allowMimeTypes()`
  - Excecao `MimeTypeNotAllowed`, testes extensivos
- [x] **S2** Sanitizacao de filename robusta (ja implementado)
  - Protecao contra `..`, caracteres unicode, extensoes duplas (`file.php.jpg`), null bytes

### Funcionalidades

- [x] **F2** Eventos Laravel (ja implementado)
  - `MediaUploaded`, `MediaDeleted`, `ConversionCompleted`, `ResponsiveImagesGenerated`
  - Disparados nos pontos corretos do ciclo de vida, testados

### Infraestrutura (v1.6.0–v1.7.0)

- [x] **I1** Configuracao centralizada de driver de imagem (`MEDIAMAN_DRIVER`)
- [x] **I2** Remocao de fallbacks hardcoded (`ImageManager::imagick()`/`gd()`) — injecao via container
- [x] **I3** Method injection nos `handle()` de Jobs (`GenerateResponsiveImages`, `PerformConversions`)
- [x] **I4** Parametros do `FileSizeOptimizedWidthCalculator` configuraveis via config
- [x] **I5** `ResponsiveConversions`: widths e quality hardcoded movidos para config
- [x] **I6** Validacao de driver com `match`/`throw` (`InvalidArgumentException`)
- [x] **I7** De-duplicacao do default de breakpoints (unica fonte: `config/mediaman.php`)
- [x] **I8** `WidthCalculator` tornado dependencia obrigatoria em `ResponsiveImageGenerator`
- [x] **I9** `MediaChannel` resolvido via container (`app()`) em `HasMedia`
- [x] **I10** Pest v4, PHP ^8.3, `composer.lock` removido do versionamento
- [x] **I11** CI: PHP 8.2 removido do matrix (6 combinacoes: 8.3/8.4/8.5 × Laravel 12/13)

---

## Pendente

### Performance

- [ ] **P3** Memory streams ao inves de temp files no `ResponsiveImageGenerator`
  - `calculateImageDimensions()` e `generateResponsiveImages()` criam arquivos temporarios em disco
  - Acao: Usar `php://memory` ou `php://temp` streams
  - Esforco: Medio

- [ ] **P5** Bulk operations para attach/detach
  - `attachMedia`/`detachMedia` fazem queries individuais por item
  - Acao: Otimizar para batch inserts com `insert()` ao inves de loop com `attach()`
  - Esforco: Medio

### Seguranca

- [ ] **S3** Limite de tamanho de arquivo configuravel
  - Acao: Adicionar `mediaman.max_file_size`, validar em `MediaUploader::upload()`, excecao `FileSizeExceeded`
  - Esforco: Baixo | Impacto: Alto

- [ ] **S4** Substituir MD5 por SHA-256 no `getDirectory()`
  - MD5 e considerado fraco para hashing
  - Acao: Usar `hash('sha256', ...)` mantendo backward compatibility via config
  - Esforco: Baixo | Impacto: Baixo

- [ ] **S5** Validacao de custom_properties
  - Prevenir injecao de dados maliciosos via JSON
  - Acao: Adicionar sanitizacao/validacao opcional de keys e valores
  - Esforco: Baixo

### Funcionalidades Novas

- [ ] **F1** Validation rules reutilizaveis
  - Criar `MediaRule::image()->maxSize(5000)->mimes(['jpg','png'])` para usar em Form Requests
  - Esforco: Baixo | Impacto: Alto

- [ ] **F3** Soft deletes no modelo Media
  - Adicionar `SoftDeletes` trait opcional para permitir recuperacao de midia deletada
  - Acao: Migration adicionando `deleted_at`, config `mediaman.soft_deletes`
  - Esforco: Baixo

- [ ] **F4** Media URL temporarias (signed URLs)
  - Suporte a presigned URLs para discos privados (S3, GCS)
  - Acao: `$media->getTemporaryUrl($expiration)` delegando para `Storage::temporaryUrl()`
  - Esforco: Medio | Impacto: Alto

- [ ] **F5** Bulk upload
  - Metodo para upload de multiplos arquivos de uma vez
  - Acao: `MediaUploader::sources($files)->upload()` retornando Collection de Media
  - Esforco: Baixo

### Divida Tecnica

- [ ] **V4** Upgrade intervention/image para v4
  - ~25 alteracoes em 8 arquivos (mapeadas em `migration-plan.md`)
  - Impacto BC para usuarios com conversions customizadas (metodos renomeados)
  - Bloqueia: nada. Pode ser feito a qualquer momento
  - Esforco: Medio | Impacto: Alto (prepara para futuro da lib)

---

## Prioridade Sugerida

### Fase 1 — Quick Wins (baixo esforco, alto impacto)
1. S3 — Limite de tamanho de arquivo
2. F1 — Validation rules
3. F5 — Bulk upload

### Fase 2 — Funcionalidades de Valor
4. F4 — Signed URLs
5. F3 — Soft deletes
6. V4 — Upgrade intervention/image v4

### Fase 3 — Refinamentos
7. P3 — Memory streams
8. P5 — Bulk operations
9. S4 — SHA-256
10. S5 — Validacao custom_properties
