# Content Publishing v1 — agent-operable editorial CRUD over the entity substrate

<!-- Spec reviewed 2026-07-29 - #2141: capability-authorized publisher results use a closed descriptor-defined internal projection rather than ambient FieldReadGuard authority, and each content mutation plus its idempotency replay record shares one database transaction. A post-save projection/serialization failure therefore rolls back the entity write and cannot strand a slug before the replay record exists. -->

**Status:** DESIGN → in build (anchor issue filed at creation; see Traceability).
**Audience:** framework maintainers; consuming-app authors (rhtcircle is the proving consumer).
**Origin:** the 2026-07-28 rhtcircle Trespass By-law publish — one routine article required a seed-template commit, `ArticleSeedData` edits, hand-edited `sitemap.xml`, hardcoded test-count bumps, an OG-card CI cycle, a container rebuild, and a production field-access preflight refresh. Routine editorial publishing must be a data operation, not an application release.

## Decision (ownership boundaries)

Per the standing rule: framework gaps are fixed in the framework, not papered over in apps. The release is cut only when the framework AND the rhtcircle consumer are ready together.

| Capability | Owner | Rationale |
|---|---|---|
| Draft/publish/unpublish/revisions/rollback service over `EntityRepositoryInterface` | **`waaseyaa/publishing` (NEW, Layer 3)** | Every CMS consumer re-derives this glue today; Drupal ships it as product. Composes only existing primitives (repository revisions, `SaveContext::withExpectedRevisionId`, access handler, audit writer) — no new write path. |
| Idempotency keys for mutations | **`waaseyaa/publishing`** (store) — extraction to `api` middleware is a follow-up | Net-new framework-wide; enterprise write APIs treat this as table stakes. |
| Short-lived signed preview links | **`waaseyaa/publishing`** | Access-control-adjacent; every CMS needs shareable draft previews. HMAC over the kernel `ApplicationSecret`; no route shipped (apps wire routes, same pattern as `seo`). |
| Bundle-scoped MCP content tool set (factory) | **`packages/ai-tools/src/Content/` (Layer 5)** | Hand-writing N tools per app does not scale. Apps declare a `ContentTypeDescriptor` + tool-name prefix and get the full tool set. L5 importing L3 `publishing` is downward — legal. |
| MCP per-principal rate limiting | **`packages/mcp` (Layer 6)** | The `RateLimiterInterface` primitive exists (auth, L1); the endpoint must consume it. |
| `content.*` audit kinds | **`packages/audit` (Layer 1)** | Closed taxonomy stays closed; five new first-party kinds. |
| Article schema, editorial rules, presentation, preview/sitemap routes, publisher auth binding | **rhtcircle (app)** | The app owns WHAT an article is and HOW it renders; the framework owns HOW content mutates safely. |
| MCP transport, auth tiers, capability registries | **existing `packages/mcp`** — unchanged | The write tier (`/mcp/write`, bearer auth, capability-scoped registry) already exists; tools ride it. |
| Media source plugins / versioned blobs | **out of scope** (#1742/#1762 unchanged) | `asset.upload` uses the existing media `UploadHandler` (fail-closed MIME sniffing) + `media` entity; finishing media is its own effort. |

Explicitly rejected: enabling the generic `entity.create/update/*` MCP tools for content editing. The content tool set is deliberately bundle-scoped: schema-validated payloads, editorial validation, sanitization, idempotency, and publish semantics that the generic tools rightly do not have.

## `waaseyaa/publishing` (Layer 3 — Services)

Namespace `Waaseyaa\Publishing`. Depends on: foundation (L0), entity, entity-storage, access, audit (L1). No routing/api/ai imports.

### ContentTypeDescriptor (app contract)

```php
final readonly class ContentTypeDescriptor {
    public string $entityTypeId;         // e.g. 'node'
    public ?string $bundle;              // e.g. 'article'
    public string $slugField;            // unique-per-bundle human key, e.g. 'slug'
    public string $statusField;          // publish flag, e.g. 'status'
    public array $writableFields;       // field => FieldSpec{type, required, htmlProfile?, maxLength?}
    public array $htmlFields;           // fields sanitized with the app's HtmlSanitizerConfig
    public HtmlSanitizerConfig $sanitizerConfig; // explicit editorial allowlist (Symfony)
    public array $validators;           // list<ContentValidatorInterface> — app editorial rules
    public string $publishCapability;    // permission string gating every mutation
}
```

`ContentValidatorInterface::validate(array $values, ValidationErrors $errors): void` — app rules append **field-specific** errors (`$errors->add('body_html', 'em dash U+2014 is not allowed')`).

### ContentPublisher (the service — the only mutation door)

All mutations require: (1) the descriptor's `publishCapability` on the acting principal (`AccountInterface::hasPermission`), (2) the entity-level gate (`EntityAccessHandler` create/update) — defense in depth, (3) a non-empty **idempotency key**, (4) validation + sanitization pass. Every mutation stamps `revision_log` and cuts a revision (repository semantics); actor comes from the ambient `AccountContextInterface` (already scoped by the MCP endpoint).

Publisher reads and mutation responses expose only a closed projection fixed by the descriptor: structural identity, publication status, slug, and the declared writable fields. First-party entities are projected through an internal reader after the publish capability and applicable entity gate have succeeded, so publishing does not require an unrelated broad ambient field-read permission such as `administer nodes`. Callers cannot choose additional fields. Third-party entity implementations retain the canonical guarded-accessor fallback.

| Method | Semantics |
|---|---|
| `list(query)` / `get(idOrSlug)` / `revisions(id)` | Reads via repository + access filter; `get` returns `revision_id` (the concurrency token) and full payload. |
| `createDraft(values, idemKey)` | `status=false` forced; slug required + unique (bundle-scoped query); returns id + revision_id. Draft is never public. |
| `updateDraft(id, values, expectedRevisionId, idemKey)` | Optimistic concurrency via `SaveContext::withExpectedRevisionId` → `RevisionConflictException` maps to a structured `REVISION_CONFLICT` error carrying expected/current. Slug change re-checked for uniqueness. |
| `publish(id, expectedRevisionId, idemKey, note)` | Atomic: one revision-cutting save setting `status=true` with the revision note. Listings/search/render-cache update via the existing POST_SAVE listeners (best-effort, outside the write transaction — publish never blocks on ingestion). |
| `unpublish(id, expectedRevisionId, idemKey, note)` | `status=false` save; record + full history preserved. |
| `rollback(id, targetRevisionId, idemKey, note)` | `EntityRepository::rollback()` — a NEW revision restoring the target; history never deleted. Publication status is DELIBERATELY untouched (framework rollback never moves `status`/pointers — CW-v1 decision 2); restoring a published look requires an explicit `publish()` after rollback. |

Sanitization is **lossy-at-input by design** for this surface (unlike the read-boundary `RichTextSanitizer`): HTML fields are sanitized against the descriptor's allowlist *before* persistence, so unsanitized markup never enters storage from an agent. (The read boundary still sanitizes on output; belt and braces.)

### IdempotencyStore

Table `publishing_idempotency` (`idem_key` PK, `operation`, `request_hash` (sha256 of canonicalized args), `response_json`, `created_at`). Same key + same hash → replay the stored response without re-executing. Same key + different hash → `IDEMPOTENCY_CONFLICT` error. The content operation and replay-record insert execute in one database transaction; any later projection, serialization, or duplicate-key failure rolls back the mutation with the missing replay record. TTL sweep (default 48 h). Self-creating table (portable schema builder, mirrors `rate_limits`).

### PreviewLinkService

`issue(entityTypeId, id, ttl): PreviewToken{expiresAt, signature}` / `verify(entityTypeId, id, expiresAt, signature): bool`. Signature = HMAC-SHA256(`type|id|expiresAt`) with the kernel `ApplicationSecret`; constant-time compare; expired → invalid. The package ships **no route** — the app wires `GET .../preview/{id}?exp&sig`, renders the **working copy** (`loadWorkingCopy()`) through its real layout, and MUST send `X-Robots-Tag: noindex, nofollow` + the meta tag. Preview mutates nothing.

### Audit

Every successful mutation records via `AuditWriterInterface` (best-effort): kinds `content.draft_saved`, `content.published`, `content.unpublished`, `content.rolled_back`; preview issuance records `content.preview_issued`. Subject URI `/content/{entityType}/{id}`; attributes carry `revision_id`, `slug` — never body content, never credentials. (These app-visible kinds are additive `AuditEventKind` cases.) The MCP transport already records `mcp.dispatch` (hashed params) + `agent.tool_execute` per call.

## Content tool set (Layer 5, `packages/ai-tools/src/Content/`)

`ContentToolSet::register(ToolRegistryInterface, ContentTypeDescriptor, prefix, MediaAssetPolicy)` hand-registers (hand-registered tools win over discovery) the tool set under app-chosen stable names — for rhtcircle:

`article.list`, `article.get`, `article.createDraft`, `article.updateDraft`, `article.preview`, `article.publish`, `article.unpublish`, `article.revisions`, `article.rollback`, `asset.upload`, `asset.get`.

- Every tool: `#`capability = descriptor's `publishCapability`; mutation tools `destructive: true` → structurally absent from the public `/mcp` registry; reachable only through `/mcp/write` when the capability is on the write-tier allowlist.
- Input schemas: JSON Schema draft 2020-12, `additionalProperties: false`, derived from the descriptor's writable fields; mutations require `idempotency_key`; update/publish/unpublish require `expected_revision_id`.
- Errors: structured `{code, message, errors?: [{field, message}]}` in the MCP `isError` envelope — `VALIDATION_FAILED` (field-specific), `REVISION_CONFLICT` (with expected/current), `IDEMPOTENCY_CONFLICT`, `SLUG_TAKEN` (field-level on the slug field), `NOT_FOUND`, `UNAUTHORIZED`.
- No tool input is ever a filesystem path, SQL, Twig, or executable content; asset bytes are base64 with size caps; responses never include credentials or personal data.
- `asset.upload {filename, content_base64, alt?}`: media create access for the configured bundle is required before any bytes are written. Accepted bytes go through the media `UploadHandler` contract — fail-closed `finfo` MIME sniffing (client MIME ignored), file-signature/extension agreement, size cap, randomized safe filename — then a `media` entity is created with the authenticated actor recorded in its save context (repository save, revisioned, audited). Returns `{asset_id, url, mime, width, height, size}`. `asset.get` returns the same by id. Approved types: png/jpeg/webp (descriptor-configurable subset of the media allowlist).

## MCP rate limiting (`packages/mcp`)

`McpEndpoint` uses `AtomicRateLimiterInterface` + config `mcp.rate_limit.{max_requests,window_seconds}` (default 120/60; explicit integer zero disables). It is keyed per resolved principal id + tier. Exceeded → JSON-RPC error `-32029` "Rate limit exceeded" with `retryAfter`; inability to obtain a durable decision fails closed with sanitized `-32030` / HTTP 503.

## rhtcircle (consumer — the app side of the same effort)

- `ArticleContentType` descriptor: maps the existing 22 `node/article` fields (no schema change → **no field-access preflight change**; the preflight fingerprints schema shape, not rows). Editorial validators: no U+2014 anywhere; `hero_alt`/`social_image_alt` required when the image is set; sources section required for publish; slug shape `[a-z0-9-]+`. Sanitizer allowlist derived from the existing seed markup (p, h2/h3, ul/ol/li, a[href][rel], blockquote, figure/figcaption, img[src][alt][width][height], table family, strong/em, br).
- Publisher principal: value-object account holding only `publish rht articles` + a fixed high sentinel uid; bearer token from `RHTCIRCLE_MCP_PUBLISHER_TOKEN` env (dotenv, outside Git) bound via `WriteTierAuthInterface`; `mcp.write_tier.capabilities = ['publish rht articles']`.
- Preview route `GET /news/preview/{nid}` (signed, TTL 30 min) rendering `loadWorkingCopy()` through `layouts/news_article.html.twig`, noindex.
- `/sitemap.xml` becomes a route: static page list + published articles from the existing listing; the hand-maintained `public/sitemap.xml` is deleted.
- Seed pipeline demoted to one-time migration; listing tests derive expectations from data, not constants.
- Requires `waaseyaa/mcp` (opt-in domain) + the new `waaseyaa/publishing`.

## Release coordination

New split package `waaseyaa/publishing` needs: root composer + package composer.json, `split.yml` matrix entry, `gh repo create waaseyaa/publishing`, layer-table row (L3), public-surface-map entries. Framework release (alpha.277) is cut ONLY once the rhtcircle branch consuming it passes its full acceptance flow; both land together.

## Acceptance (local, MCP-only)

The 10-step flow from the anchor issue: createDraft → asset.upload → preview (real layout, noindex) → updateDraft (with revision id) → publish → automatic `/news` + community listing appearance → canonical URL/metadata/social image → dynamic sitemap inclusion → unpublish + rollback → zero changes to app source, tests, deployment pin, or preflight artifact from content operations.
