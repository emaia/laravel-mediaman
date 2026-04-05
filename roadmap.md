# MediaMan Roadmap

## Concluido

### Qualidade de Codigo (Q1-Q6)

- [x] **Q1** Type declarations completas em ~17 metodos across 8 arquivos
- [x] **Q2** Logging nos catches silenciosos (5 locais em 3 arquivos)
- [x] **Q3** Enums `MediaFormat` e `MediaType` + constantes no modelo Media, substituindo magic strings
- [x] **Q4** Testes para 5 comandos Artisan console
- [x] **Q5** Testes para responsive images trait e width calculators
- [x] **Q6** Testes de cenarios de erro (upload, model, HasMedia, responsive)

---

## Pendente

### Performance

- [ ] **P1** Extrair mapa de MIME types duplicado para classe utilitaria
  - Arquivos: `Media::getExtensionFromMimeType()` e `ImageManipulator::getExtensionFromMimeType()` possuem mapas identicos
  - Acao: Criar metodo estatico compartilhado (ex: em `MediaFormat` enum ou classe utilitaria)
  - Esforco: Baixo

- [ ] **P2** Cache de reflection no format detection
  - `ReflectionFunction` e chamado repetidamente em `Media::detectFormatWithReflection()`
  - Acao: Cachear resultado da reflection por closure hash
  - Esforco: Baixo | Impacto: Alto

- [ ] **P3** Memory streams ao inves de temp files no `ResponsiveImageGenerator`
  - `calculateImageDimensions()` e `generateResponsiveImages()` criam arquivos temporarios em disco
  - Acao: Usar `php://memory` ou `php://temp` streams
  - Esforco: Medio

- [ ] **P4** Indice composto em `mediaman_mediables`
  - Adicionar indice `(mediable_type, mediable_id, channel)` para acelerar queries de canal
  - Acao: Nova migration
  - Esforco: Baixo | Impacto: Alto

- [ ] **P5** Bulk operations para attach/detach
  - `attachMedia`/`detachMedia` fazem queries individuais por item
  - Acao: Otimizar para batch inserts com `insert()` ao inves de loop com `attach()`
  - Esforco: Medio

### Seguranca

- [ ] **S1** Validacao de MIME type no upload
  - O package nao valida MIME type; depende 100% da aplicacao
  - Acao: Adicionar opcao configuravel `mediaman.allowed_mime_types` no `MediaUploader`
  - Esforco: Baixo | Impacto: Alto

- [ ] **S2** Sanitizacao de filename mais robusta
  - Atualmente so remove `#`, `/`, `\`, espacos
  - Acao: Adicionar protecao contra `..`, caracteres unicode, extensoes duplas (ex: `file.php.jpg`), null bytes
  - Esforco: Baixo | Impacto: Alto

- [ ] **S3** Limite de tamanho de arquivo configuravel
  - Acao: Adicionar `mediaman.max_file_size` e validar em `MediaUploader::upload()`
  - Esforco: Baixo

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

- [ ] **F2** Eventos Laravel
  - Disparar `MediaUploaded`, `MediaDeleted`, `ConversionCompleted`, `ResponsiveImagesGenerated`
  - Permite integracao com listeners, notificacoes, logging externo
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

---

## Prioridade Sugerida

### Fase 1 — Quick Wins (baixo esforco, alto impacto)
1. S1 — Validacao de MIME type
2. S2 — Sanitizacao de filename
3. P4 — Indice composto
4. F2 — Eventos Laravel
5. P1 — MIME type map compartilhado

### Fase 2 — Funcionalidades de Valor
6. F1 — Validation rules
7. F4 — Signed URLs
8. F5 — Bulk upload
9. P2 — Cache de reflection

### Fase 3 — Refinamentos
10. S3 — Limite de tamanho
11. P3 — Memory streams
12. P5 — Bulk operations
13. F3 — Soft deletes
14. S4 — SHA-256
15. S5 — Validacao custom_properties
