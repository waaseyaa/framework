# Content Publishing v1 — agent-operable editorial CRUD over the entity substrate

<!-- Spec reviewed 2026-08-16 - S1-FW-DB-03: ContentPublisher rollback preserves the page-builder expected-current-revision precondition, then requires the loaded EntityBase snapshot's opaque aggregate mutation token at the transactional repository boundary. A missing or stale token refuses the copy-forward; revision creation, pointer semantics, audit, and idempotency behavior otherwise remain as specified below. Canonical concurrency contract: s1-concurrency-fencing.md. -->

<!-- Spec reviewed 2026-08-15 - S1-FW-CFG-04: preview links moved from single-scheme ApplicationSecret HMAC to versioned application-master custody. PreviewLinkService signs with keyring-sealed versioned tags (purpose waaseyaa.publishing.preview-hmac.v1), enforces the 30-minute hard TTL maximum, and fail-closed verification accepts only keyring-declared versions; legacy unversioned signatures verify only behind publishing.preview.accept_legacy_application_secret_signatures (default false). New PublishingServiceProvider owns the composition and contributes the zero-row PublishingPreviewRekeyAdapter — the framework's sole ephemeral-no-persistence application-master purpose. See PreviewLinkService and PublishingServiceProvider sections. -->

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
| Short-lived signed preview links | **`waaseyaa/publishing`** | Access-control-adjacent; every CMS needs shareable draft previews. Versioned application-master signatures (legacy `ApplicationSecret`-derived HMAC only behind an explicit compatibility flag); no route shipped (apps wire routes, same pattern as `seo`). |
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
    public array $writableFields;       // field => FieldSpec{type, required, html, maxLength, nullable, maxItems}
    public array $htmlFields;           // fields sanitized with the app's HtmlSanitizerConfig
    public HtmlSanitizerConfig $sanitizerConfig; // explicit editorial allowlist (Symfony)
    public array $validators;           // list<ContentValidatorInterface> — app editorial rules
    public string $publishCapability;    // permission string gating every mutation
}
```

`FieldSpec` supports `string`, `text`, `bool`, `int`, `date`, and `reference_list`. Dates are real `YYYY-MM-DD` calendar dates rather than arbitrary strings. Reference lists accept only positive integer or bounded non-empty string identifiers, reject duplicates, and may declare `maxItems`. Optional fields may explicitly opt into `nullable`; required-field validation still rejects null when creating or publishing a complete document. The generated MCP schema mirrors these constraints, including date format, null alternatives, unique items, and list bounds.

`ContentValidatorInterface::validate(array $values, ValidationErrors $errors): void` — app rules append **field-specific** errors (`$errors->add('body_html', 'em dash U+2014 is not allowed')`).

### ContentPublisher (the service — the only mutation door)

`createDraft()` and `updateDraft()` accept a trailing optional list of
candidate-bound save-advisory acknowledgement tokens (#2467). The normalized
tokens are part of the idempotency request fingerprint and join actor/revision
state in the same `SaveContext`; changing the token set under a reused key is an
idempotency conflict. A storage advisory is translated to the structured
`ContentSaveAdvisoryException` with code
`SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` and
`meta.save_advisories`. Publish, unpublish, and rollback do not accept tokens in
this contract.

`ContentPublisher` also implements the public composite-authoring mutation
seam used by the page-builder publishing adapter. The adapter may project only
its configured canonical layout field, and it must forward the caller's
observed entity revision and idempotency key unchanged.

Because a layout edit is an ordinary entity save, a pre-save policy can hold it
for review over a field the edit never touched. `PublishingLayoutDraftGateway`
therefore implements `AdvisoryAwareLayoutDraftGatewayInterface`, hands any
receipts to `SaveAdvisoryAcknowledgementDispatcher`, and translates both of that
dispatcher's advisory outcomes into page-builder types:
`ContentSaveAdvisoryException` becomes `LayoutSaveAdvisoryException` with the
advisory payloads intact, and `UnsupportedSaveAdvisoryAcknowledgementException`
— raised when the adapter advertises receipt support but the publisher it wraps
is frozen at five arguments — becomes
`UnsupportedLayoutSaveAdvisoryAcknowledgementException`. Both translations exist
because a layout gateway is not required to be publishing-backed and
`waaseyaa/admin-surface` does not depend on this package, so an untranslated
outcome escapes the page-builder host uncaught; they follow the same pattern the
adapter already uses for authorization and not-found outcomes. See
`docs/specs/save-advisories.md` §11. Admin SPA and Anokii
therefore share the same publication authority; neither client receives a
direct repository save path.

Editor preview uses the additive exact-revision grant. Its HMAC input is
domain-separated from legacy working-copy grants and binds entity type, entity
identity, positive revision identity, and expiry. Under application-master
custody the grant signature additionally carries the exact active master
version (see PreviewLinkService). `ContentPublisher` verifies
that the requested revision is still the working copy before issuing and
auditing the grant. The application preview route must load that exact revision;
loading whichever working copy happens to be current is not compliant.

#### Read authorization

The six read operations — `list()`, `get()`, `revisions()`, `revision()`,
`preview()`, `previewRevision()` — are **not** capability-only. The publish
capability is a coarse editorial credential; on its own it cannot substitute for
the per-entity decision, because a namespace-scoped authoring credential
necessarily covers entities a bundle- or entity-level policy is meant to
restrict (framework #2516).

- **Collections.** `list()` resolves its candidate window through the
  access-checked query API — `EntityRepository::getQuery()->setAccount($actor)`
  — and only then hydrates with `findMany()`. Rows the principal may not view
  never leave storage, so they can leak neither content nor cardinality; the
  response carries no total or cursor derived from an unchecked candidate set.
  A publisher composed with an access handler therefore requires a
  query-capable (database-backed) repository: `getQuery()` is the only path
  that can prove per-row viewability, and there is deliberately no fallback to
  an unchecked read.
- **Single reads.** `get()`, `preview()` and `previewRevision()` load the entity
  and require an `Allowed` entity-level `view` decision.
- **History.** `revisions()` and `revision()` apply a decision at the
  **revision** level, through the handler's `view_revision` operation
  (composed by `RevisionPolicyComposition`, which falls back to the
  entity-level `view` decision when no policy expresses a revision-level
  opinion). The decision is applied **before** any historical field data is
  projected into the response: `revisions()` omits refused revisions,
  `revision()` refuses outright.
- **Refusal is not an oracle.** Every refusal raises exactly what absence
  raises: `ContentNotFoundException`, code `NOT_FOUND`, identical message. A
  distinct `UNAUTHORIZED` outcome would let a caller enumerate content it may
  not see.
- **Composed without an access handler**, the publisher has no entity-level
  decision authority and the capability gate remains the only authority — the
  same rule the create/update gates already follow.

**Deliberate carve-out — `assertSlugFree()`.** The slug-uniqueness pre-check
keeps reading through the **non-access-checked** repository path. It is not a
read of user-visible content: nothing from the conflicting row reaches the
caller, only the fact that the slug is taken. Routing it through the
access-checked query would hide the conflicting row from an unprivileged caller
and let them create a colliding slug, so two rows would share one slug and the
app's slug route would resolve to whichever row storage happened to return. The
carve-out is pinned by
`ContentPublisherReadAccessTest::slug_uniqueness_still_sees_entities_the_caller_may_not_view()`
and must not be converted in a later sweep.

All mutations require: (1) the descriptor's `publishCapability` on the acting principal (`AccountInterface::hasPermission`), (2) the entity-level gate (`EntityAccessHandler` create/update) — defense in depth, (3) a non-empty **idempotency key**, (4) validation + sanitization pass. Every mutation stamps `revision_log` and cuts a revision (repository semantics); actor comes from the ambient `AccountContextInterface` (already scoped by the MCP endpoint).

Publisher reads and mutation responses expose only a closed projection fixed by the descriptor: structural identity, publication status, slug, and the declared writable fields. First-party entities are projected through an internal reader after the publish capability and applicable entity gate have succeeded, so publishing does not require an unrelated broad ambient field-read permission such as `administer nodes`. Callers cannot choose additional fields. Third-party entity implementations retain the canonical guarded-accessor fallback.

`ContentPublisher` accepts an optional `ContentPublicationTransitionerInterface`. When the transitioner reports that an entity is workflow-bound, publication changes must pass through that transitioner after the publisher has asserted the caller's expected revision. The workflows package supplies the canonical adapter: it chooses exactly one currently permitted transition whose target state has the requested `published` value and is a default revision, invokes `TransitionService`, and reloads the working copy. No candidate or an ambiguous candidate set fails with the structured `WORKFLOW_TRANSITION_UNAVAILABLE` publishing error. Direct status-field writes remain the compatibility path only for content without a workflow binding.

| Method | Semantics |
|---|---|
| `list(query)` / `get(idOrSlug)` / `revisions(id)` / `revision(id, revisionId)` / `preview(idOrSlug)` / `previewRevision(idOrSlug, revisionId)` | Capability **and** per-entity access decision (see "Read authorization" below); `get` returns `revision_id` (the concurrency token) and full payload. |
| `createDraft(values, idemKey)` | `status=false` forced; slug required + unique (bundle-scoped query); returns id + revision_id. Draft is never public. |
| `updateDraft(id, values, expectedRevisionId, idemKey)` | Optimistic concurrency via `SaveContext::withExpectedRevisionId` → `RevisionConflictException` maps to a structured `REVISION_CONFLICT` error carrying expected/current. Slug change re-checked for uniqueness. |
| `publish(id, expectedRevisionId, idemKey, note)` | Optimistically guarded publication. Workflow-bound content uses the canonical transition to a published default revision; unbound content uses one revision-cutting save setting `status=true`. Listings/search/render-cache update via the existing POST_SAVE listeners (best-effort, outside the write transaction — publish never blocks on ingestion). |
| `unpublish(id, expectedRevisionId, idemKey, note)` | Optimistically guarded unpublication. Workflow-bound content uses the canonical transition to an unpublished default revision; unbound content saves `status=false`. Record and full history are preserved. |
| `rollback(id, targetRevisionId, idemKey, note)` | `EntityRepository::rollback()` — a NEW revision restoring the target; history never deleted. Publication status is DELIBERATELY untouched (framework rollback never moves `status`/pointers — CW-v1 decision 2); restoring a published look requires an explicit `publish()` after rollback. |

Sanitization is **lossy-at-input by design** for this surface (unlike the read-boundary `RichTextSanitizer`): HTML fields are sanitized against the descriptor's allowlist *before* persistence, so unsanitized markup never enters storage from an agent. (The read boundary still sanitizes on output; belt and braces.)

### IdempotencyStore

Table `publishing_idempotency` (`idem_key` PK, `operation`, `request_hash` (sha256 of canonicalized args), `response_json`, `created_at`). `ContentPublisher` namespaces the client key by entity type and bundle before storage, so independent bundle-scoped surfaces cannot replay or conflict with one another. The stored namespaced key is a fixed-length SHA-256 digest; the client key still appears in structured conflict errors. Within one surface, same key + same hash → replay the stored response without re-executing, while same key + different hash → `IDEMPOTENCY_CONFLICT`. The content operation and replay-record insert execute in one database transaction; any later projection, serialization, or duplicate-key failure rolls back the mutation with the missing replay record. TTL sweep (default 48 h). Self-creating table (portable schema builder, mirrors `rate_limits`).

### PreviewLinkService

`issue(entityTypeId, id, ttl): PreviewToken{expiresAt, signature}` / `verify(entityTypeId, id, expiresAt, signature): bool`, plus the exact-revision pair. TTL is bounded: 1 ≤ ttl ≤ 1800 seconds — 30 minutes is the hard maximum grant lifetime, not just the default. Under application-master custody (the production composition) signatures are versioned tags `hmac-sha256.application-master.preview.v1:<masterVersion>:<base64url digest>`, sealed by the keyring under purpose `waaseyaa.publishing.preview-hmac.v1` over a NUL-domain-separated message; new grants always carry the exact active master version, and verification accepts only versions the keyring declares. Legacy unversioned signatures (HMAC-SHA256 of `type|id|expiresAt` with the purpose-derived `ApplicationSecret` key, constant-time compare) verify only when legacy custody is configured. Verification is fail-closed and never throws: a malformed prefix/version/digest, an undeclared master version, a custody error, or a legacy signature with no legacy secret configured all return `false`; expired → invalid. The signer refuses serialization and redacts custody in debug output. The package ships **no route** — the app wires `GET .../preview/{id}?exp&sig`, renders the **working copy** (`loadWorkingCopy()`) through its real layout, and MUST send `X-Robots-Tag: noindex, nofollow` + the meta tag. Preview mutates nothing. Keyring mechanics (versions, tag format, purpose registry) live in `docs/specs/infrastructure.md`.

### PublishingServiceProvider (custody composition and rekey ownership)

`PublishingServiceProvider` (auto-discovered via `extra.waaseyaa.providers`) composes `PreviewLinkService` keyring-first: when the kernel exposes an `ApplicationMasterKeyring`, previews sign with versioned application-master custody, and legacy unversioned signatures are accepted only when config `publishing.preview.accept_legacy_application_secret_signatures` is explicitly `true` (default false). Without a keyring the service falls back to the purpose-derived `ApplicationSecret` legacy custody alone.

`waaseyaa/publishing` owns the `waaseyaa.publishing.preview-hmac.v1` purpose in the application-master rekey roster through `PublishingPreviewRekeyAdapter` — the framework's sole `ephemeral-no-persistence` purpose. Preview grants are stateless: there are no persisted rows to transition or export, so the adapter snapshots exactly zero records and refuses transition and rollback batches. Its purpose policy declares owner `waaseyaa/publishing`, lifetime and retention equal to the 30-minute maximum grant lifetime, and rollback behavior `verify-declared-version-until-expiry` — grants issued under a predecessor master version stay verifiable through their lifetime because reads accept any keyring-declared version. Coordinator and keyring mechanics live in `docs/specs/infrastructure.md`.

### Draft-mutation seam (public extension point)

`ContentDraftMutationInterface` is the adapter seam applications implement to
compose their own authoring services — id-resolving decorators and page-builder
gateways. It is classified **public** in `docs/public-surface-map.php`, because
`SaveAdvisoryAcknowledgementDispatcher` is an `@api` entry point that takes it
as a parameter, and a public entry point may not require consumers to implement
an internal contract.

Its five-parameter `updateDraft()` is **frozen**. PHP checks an implementing
method against every parameter its interface declares, so adding even a trailing
optional parameter is a load-time fatal for every existing implementor — a
breaking change, not an additive one. Acknowledgement support is therefore opt-in
through `AdvisoryAwareContentDraftMutationInterface`, which extends the frozen
contract; `ContentPublisher` implements the extension. Callers route through
`SaveAdvisoryAcknowledgementDispatcher::updateDraft()`, which calls the ordinary
five-argument method when no receipts are supplied, requires the extension when
they are, and otherwise throws `UnsupportedSaveAdvisoryAcknowledgementException`
(`SAVE_ADVISORY_UNSUPPORTED`) before any write rather than discarding receipts.

`ContentRevisionHistoryInterface` and `ContentRevisionPreviewInterface` remain
internal: no public entry point takes them as a parameter.

The full compatibility promise, including how future capability must be added,
is in `docs/specs/save-advisories.md` §10.

### Audit

Every successful mutation records via `AuditWriterInterface` (best-effort): kinds `content.draft_saved`, `content.published`, `content.unpublished`, `content.rolled_back`; preview issuance records `content.preview_issued`. Subject URI `/content/{entityType}/{id}`; attributes carry `revision_id`, `slug` — never body content, never credentials. (These app-visible kinds are additive `AuditEventKind` cases.) The MCP transport already records `mcp.dispatch` (hashed params) + `agent.tool_execute` per call.

## Content tool set (Layer 5, `packages/ai-tools/src/Content/`)

`ContentToolSet::register(ToolRegistryInterface, ContentTypeDescriptor, prefix, MediaAssetPolicy)` hand-registers (hand-registered tools win over discovery) the tool set under app-chosen stable names — for rhtcircle:

`article.list`, `article.get`, `article.createDraft`, `article.updateDraft`, `article.preview`, `article.publish`, `article.unpublish`, `article.revisions`, `article.rollback`, `asset.upload`, `asset.get`.

- Every tool: `#`capability = descriptor's `publishCapability`; mutation tools `destructive: true` → structurally absent from the public `/mcp` registry; reachable only through `/mcp/write` when the capability is on the write-tier allowlist.
- Input schemas: JSON Schema draft 2020-12, `additionalProperties: false`, derived from the descriptor's writable fields; mutations require `idempotency_key`; update/publish/unpublish require `expected_revision_id`.
- Errors: structured `{code, message, errors?: [{field, message}], meta?: object}` in the MCP `isError` envelope — `VALIDATION_FAILED` (field-specific), `REVISION_CONFLICT` (with expected/current), `IDEMPOTENCY_CONFLICT`, `SLUG_TAKEN` (field-level on the slug field), `SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` (candidate-bound advisory metadata), `NOT_FOUND`, `UNAUTHORIZED`.
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
