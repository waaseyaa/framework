# Implementation Plan: Author-Path Remediation

**Branch:** main | **Date:** 2026-06-17 | **Spec:** [spec.md](spec.md)

## Technical context

PHP 8.5, Symfony-Console CLI (`packages/cli`), entity/access/field core, SSR + api + ai-tools +
foundation. Tests: PHPUnit split suites; `CliTester` for CLI; live `curl` + CLI on Windows for
the acceptance gate. No BC shims (C-001).

## Findings → decisions (grounded in investigation)

1. **CLI input (FR-001/002).** `EntityCreateHandler` takes only `--values` JSON. `HandlerOption`
   supports `Array_` (repeatable). `SymfonyCommandIO` exposes options + stdin. The Windows
   `vendor/bin/waaseyaa.bat` is Composer-generated and already passes `%*` faithfully — the bug
   was PowerShell JSON quoting, not arg passthrough. **Fix:** add `--field name=value` (repeatable),
   `--field-file name=@path` (repeatable), `--values-file path.json`, and stdin (`--values-file -`);
   merge with deterministic precedence (values-file/stdin < --field < --field-file). Verify the
   bin entry passes args faithfully; document.

2. **make:content-type (FR-003).** `MakeEntityTypeHandler` renders an attribute-decorated
   `ContentEntityBase` inline; `EntityType::fromClass()` reads `#[ContentEntityType]` +
   `#[ContentEntityKeys]` + `#[Field]`; `schema:sync` materializes tables. **Fix:** new
   `make:content-type <name> --fields="f:type,..."` generating `App\Entity\{Name}` with a
   `status` (published) field + each spec field (with `entity_reference` target metadata when
   `:entity_reference:target` given), registered for discovery (see WP01), immediately usable.
   No generated access policy needed — the default (WP02) covers published public-read.

3. **Access default + boundary (FR-004, NFR-001/002/003).** Entity-level read is deny-unless-
   granted; a published node still needs `access content`. **Fix:** add an additive default
   policy granting anonymous `view` for PUBLISHED content entities (published = `status` field
   truthy — generic, L1-safe, no workflows import); registered as a framework default in the
   kernel's access-handler composition so it applies to all content types without per-type
   attributes. Additive only: returns `Allowed` for published view / `Neutral` otherwise — a
   more specific `Forbidden` still wins (`orIf`). **MCP `entity.read`** currently dumps raw
   values (NFR-003 gap) — route its serialization through the same internal/credential/field-
   access filtering as `ResourceSerializer` so stored content fields show but internal/restricted
   never leak.

4. **Serving + field exposure (FR-005/006).** **Markdown body-drop:** `EntityMarkdownPresenter::
   resolveDisplay()` only renders the view-mode display when one exists, dropping stored fields
   not in it. **Fix:** union the configured display with all access-safe stored fields (configured
   fields keep weights; extras appended), so `body` always shows. **Canonical serving:**
   `SsrPageHandler` resolves only path aliases. **Fix:** add a published-gated canonical
   `/{entityTypeId}/{id}` resolution fallback so fresh content is reachable without a manual
   alias (HTML + same-URL markdown).

5. **Dev auto-discovery (FR-007/NFR-004).** The manifest fingerprint covers only
   `composer.json` + `installed.json`, so new `App\Entity`/`App\Policy` classes need
   `optimize:manifest`; and the classmap needs `--optimize`. But the compiler's PSR-4 directory
   scan finds new app files without `--optimize`. **Fix:** in `AbstractKernel::compileManifest()`,
   when `isDevelopmentMode()`, compile fresh (no cache) instead of `load()`; production keeps the
   cached manifest. This eliminates both `dump-autoload -o` and `optimize:manifest` in dev.

## Work packages (dependency order)

- **WP01 — Dev-mode manifest auto-discovery** (foundation). `compileManifest()` dev→fresh,
  prod→cached. Tests: dev recompiles / prod uses cache. FR-007, NFR-004.
- **WP02 — Default public-read-for-published + MCP field-filter** (access + ai-tools). Additive
  default policy + kernel composition; MCP `entity.read` field-filtering. Security tests:
  published view allowed anon, unpublished denied, Forbidden still wins, internal/restricted
  never leak. FR-004, NFR-001/002/003. **Security gate.**
- **WP03 — Canonical serving + full field exposure** (ssr + api). Canonical `/{type}/{id}`
  published-gated resolution; markdown display union (body shown). Tests + cache-variant intact.
  FR-005/006.
- **WP04 — Cross-platform `entity:create` input** (cli). `--field`/`--field-file`/`--values-file`/
  stdin + precedence; bin-passthrough check. Tests for file + stdin paths. FR-001/002.
- **WP05 — `make:content-type` scaffold** (cli). Generate entity + registration + field defs.
  Tests. FR-003.
- **WP06 — Cold-Windows acceptance gate + release.** Live: make→schema:sync→entity:create→serve
  3 ways, documented CLI only. Then cut the release. SC-006.

## Charter check

Layer discipline: default policy lives L1 (access) using generic `status` check (no upward
import); MCP filter in ai-tools (L5) reuses api (L4) serializer surface — downward. No new
`KERNEL_EXEMPT_FILES`. Security boundary + dev/prod split are the do-not-guess gates (C-002).
