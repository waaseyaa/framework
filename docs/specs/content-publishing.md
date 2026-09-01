# Content Publishing v1 — agent-operable editorial CRUD over the entity substrate

<!-- Spec reviewed 2026-09-01 - #2734: repository mutation tokens now advance only after the true outer transaction commits. Multi-step unbound publish and unpublish flows therefore reload the transaction-visible entity after each save before passing its current token to pointer promotion or clearing; publication, idempotency, and projection semantics are otherwise unchanged. -->

<!-- Spec reviewed 2026-08-26 - #2555: publishing idempotency is partitioned by acting principal. ContentPublisher binds the stable authorization principal id into the replay record's storage namespace alongside entity type and bundle, so two authorized publishers sharing a client key and payload execute independently and each receives its own response. Single-principal replay and payload-conflict behaviour are unchanged. See IdempotencyStore "Actor-scoped partitioning". -->

<!-- Spec reviewed 2026-08-26 - #2562: after a live published pointer, `updateDraft()` is a revision-only working-copy save (default-revision discipline; expected-revision claim asserted against the working copy then cleared). Unbound `publish()` pins `published_revision_id` via `EntityRepository::promotePublishedRevision()`, which rewrites the served base row from the selected revision. A pointerless record keeps the existing tip-tracking draft-save behaviour. Storage still does not infer discipline from pointer presence (Playbook H). -->
<!-- Spec reviewed 2026-08-26 - #2562 review: unbound `unpublish()` asserts the working-copy token, then `EntityRepository::clearPublishedRevision()` drops `published_revision_id` and sets served `status=0` without copying a diverged draft. Later unbound `publish()` restores the forked `workflow_state` and promotes the save-hydrated revision id. `loadPublishedRevision()` remains a pointer follow; unpublished records tip-track. -->

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
    public ?string $authorField;         // optional server-owned entity author field, e.g. 'uid'
    public array $writableFields;       // field => FieldSpec{type, required, html, maxLength, nullable, maxItems}
    public array $htmlFields;           // fields sanitized with the app's HtmlSanitizerConfig
    public HtmlSanitizerConfig $sanitizerConfig; // explicit editorial allowlist (Symfony)
    public array $validators;           // list<ContentValidatorInterface> — app editorial rules
    public string $publishCapability;    // permission string gating every mutation
}
```

`authorField` is application-declared server-owned identity, never a writable
client field. When present, `createDraft()` requires an authenticated positive
integer actor id and stamps it into the new entity before persistence.
`SaveContext::withActorUid()` independently records the same identity as the
revision author. This distinction is load-bearing: entity access policies
decide “own unpublished content” from the entity author, while revision audit
and history use revision authorship. The server-owned author also participates
in the create idempotency fingerprint; since #2555 the replay record's storage
namespace binds the acting principal directly, so that fingerprint component is
defence in depth rather than the thing preventing cross-principal replays (see
IdempotencyStore below). A descriptor without an author field keeps the existing
unauthored-entity behaviour.

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

`PublishingLayoutDraftGateway` and `PublishingPageBuilderRevisionGateway` each
accept an optional page-builder `InitialLayoutDocumentProviderInterface`
(#2556). A migrated entity has no stored layout document and the draft
snapshot cannot legally hold that state; a composed provider supplies the
application's initial document for exactly the absent case (`NULL` or an
empty/whitespace-only stored string) as a read projection — no write occurs,
an empty provider return is refused, a corrupt non-string stored value is
still refused, and composition without a provider preserves the historical
refusal byte-for-byte. See `docs/specs/page-builder.md` §7.1.

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
  — which decides each candidate row against the bound principal and returns
  only the surviving ids; only those ids are then hydrated with `findMany()`.
  (The decision is made inside the query, which hydrates its candidate window
  in order to evaluate the policy; nothing that fails the decision is
  returned.) A refused row therefore reaches the caller in no form — not as
  content, and not as cardinality, because the response carries no total or
  cursor derived from the candidate set. A publisher composed with an access
  handler therefore requires a query-capable (database-backed) repository:
  `getQuery()` is the only path that can prove per-row viewability, and there
  is deliberately no fallback to an unchecked read.
- **Paging is over viewable content.** For an access-checked query, offset and
  limit apply after the per-row decision. Offset therefore counts viewable
  rows, and a page is dense until the viewable result set is exhausted. The
  response still exposes no total derived from inaccessible candidates.
- **Single reads.** `get()` and `preview()` load the entity and require an
  `Allowed` entity-level `view` decision.
- **History.** `revisions()` and `revision()` apply a decision at the
  **revision** level, through the handler's `view_revision` operation
  (composed by `RevisionPolicyComposition`, which falls back to the
  entity-level `view` decision when no policy expresses a revision-level
  opinion). The decision is applied **before** any historical field data is
  projected into the response: `revisions()` omits refused revisions,
  `revision()` refuses outright.
- **Revision-targeted operations carry the same revision fence.**
  `previewRevision()` grants only the working copy (it asserts the supplied
  revision id *is* the working copy), so the working copy's own
  `view_revision` decision fences the grant; it is applied before the
  revision-conflict assertion, so a refused principal never learns the current
  revision id. `rollback()` is a mutation, but it copies the target revision's
  stored content forward and returns it — a read of that revision — so it too
  requires `view_revision` on the target. Both refuse as `NOT_FOUND` for the
  requested revision, exactly as `revision()` does. Neither fence changes the
  behaviour for a target revision that simply does not exist.
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

All mutations require: (1) the descriptor's `publishCapability` on the acting principal (`AccountInterface::hasPermission`), (2) the entity-level gate (`EntityAccessHandler` create/update) — defense in depth, (3) a non-empty **idempotency key**, whose replay record is partitioned by the acting principal (see IdempotencyStore below), (4) validation + sanitization pass. Every mutation stamps `revision_log` and cuts a revision (repository semantics); the explicit immutable principal supplies the revision actor, and, when the descriptor declares one, the server-owned entity author.

Publisher reads and mutation responses expose only a closed projection fixed by the descriptor: structural identity, publication status, slug, and the declared writable fields. First-party entities are projected through an internal reader after the publish capability and applicable entity gate have succeeded, so publishing does not require an unrelated broad ambient field-read permission such as `administer nodes`. Callers cannot choose additional fields. Third-party entity implementations retain the canonical guarded-accessor fallback.

`ContentPublisher` accepts an optional `ContentPublicationTransitionerInterface`. When the transitioner reports that an entity is workflow-bound, publication changes must pass through that transitioner after the publisher has asserted the caller's expected revision. The workflows package supplies the canonical adapter: it chooses exactly one currently permitted transition whose target state has the requested `published` value and is a default revision, invokes `TransitionService`, and reloads the working copy. No candidate or an ambiguous candidate set fails with the structured `WORKFLOW_TRANSITION_UNAVAILABLE` publishing error. Direct status-field writes remain the compatibility path only for content without a workflow binding.

| Method | Semantics |
|---|---|
| `list(query)` / `get(idOrSlug)` / `revisions(id)` / `revision(id, revisionId)` / `preview(idOrSlug)` / `previewRevision(idOrSlug, revisionId)` | Capability **and** per-entity access decision (see "Read authorization" above); `get` returns `revision_id` (the concurrency token) and full payload. |
| `createDraft(values, idemKey)` | `status=false` forced; slug required + unique (bundle-scoped query); returns id + revision_id. Draft is never public. |
| `updateDraft(id, values, expectedRevisionId, idemKey)` | Optimistic concurrency against the **working copy**. `RevisionConflictException` maps to a structured `REVISION_CONFLICT` error carrying expected/current. A loser of the post-publish disciplined save (CAS mutation conflict after the expected-revision claim is cleared) is remapped to the same exception. Slug change re-checked for uniqueness. After a live published pointer exists, the save arms default-revision discipline, forces a new working revision, and does **not** replace the served base-row projection (`find()`, access-checked public reads, `list(..., publishedOnly: true)`). The expected-revision claim is asserted then cleared: a disciplined save cannot carry `SaveContext::withExpectedRevisionId()` (that claim is a base-pointer UPDATE). Same-state published working copies are forked to `workflow_state=draft` (shipped editorial initial state) so a bound workflow cannot auto-republish the edit. With no published pointer, the base row still tracks the tip. |
| `publish(id, expectedRevisionId, idemKey, note)` | Optimistically guarded publication. Workflow-bound content uses the canonical transition to a published default revision; unbound content saves `status=true` and then `EntityRepository::promotePublishedRevision()` so `find()` and `loadPublishedRevision()` share one served snapshot. A later unbound publish restores a working copy that was forked to `workflow_state=draft` back to the served state's id, cuts a published revision, and promotes the **save-hydrated** revision id (not a post-save `loadWorkingCopy()` tip). Listings/search/render-cache update via the existing POST_SAVE listeners (best-effort, outside the write transaction — publish never blocks on ingestion). |
| `unpublish(id, expectedRevisionId, idemKey, note)` | Optimistically guarded unpublication against the **working copy** token the surface hands out. Workflow-bound content uses the canonical transition to an unpublished default revision. Unbound content with no forward draft cuts an unpublished revision then `EntityRepository::clearPublishedRevision()`. Unbound content with a forward draft does **not** save the working copy onto the base row: `clearPublishedRevision()` sets served `status=0` and drops `published_revision_id` while leaving the published snapshot as the base `revision_id`. After the pointer is cleared, later drafts tip-track. Record and full history are preserved. |
| `rollback(id, targetRevisionId, idemKey, note)` | `EntityRepository::rollback()` — a NEW revision restoring the target; history never deleted. The target revision must satisfy `view_revision` for the acting principal (see "Read authorization" above): rollback returns the restored content, so it is a read of that revision. Publication status is DELIBERATELY untouched (framework rollback never moves `status`/pointers — CW-v1 decision 2); restoring a published look requires an explicit `publish()` after rollback. |

Sanitization is **lossy-at-input by design** for this surface (unlike the read-boundary `RichTextSanitizer`): HTML fields are sanitized against the descriptor's allowlist *before* persistence, so unsanitized markup never enters storage from an agent. (The read boundary still sanitizes on output; belt and braces.)

### IdempotencyStore

Table `publishing_idempotency` (`idem_key` PK, `operation`, `request_hash` (sha256 of canonicalized args), `response_json`, `created_at`). `ContentPublisher` namespaces the client key by entity type, bundle, **and acting principal** before storage, so neither independent bundle-scoped surfaces nor independent publishers can replay or conflict with one another. The stored namespaced key is a fixed-length SHA-256 digest; the client key still appears in structured conflict errors. Within one partition, same key + same hash → replay the stored response without re-executing, while same key + different hash → `IDEMPOTENCY_CONFLICT`. The content operation and replay-record insert execute in one database transaction; any later projection, serialization, or duplicate-key failure rolls back the mutation with the missing replay record. TTL sweep (default 48 h). Self-creating table (portable schema builder, mirrors `rate_limits`).

#### Actor-scoped partitioning (framework #2555)

The replay partition is `(entityTypeId, bundle, principal)` — **not** the client key alone.

Idempotency keys are routinely derived deterministically from the payload (an agent that hashes its own request, a queue that keys on content identity), so two distinct authorized publishers submitting the same key with the same payload against the same bundle is plausible rather than theoretical. Before #2555 the second publisher received the first's stored response verbatim: their mutation never ran, their attempt produced no audit record, they had no signal that nothing had happened, and the first publisher's created entity id and revision id were disclosed to them. This was never anonymous-reachable — both callers pass the capability gate and the entity create/update gate first — but within that authorized population it is an integrity, attribution, and bounded-disclosure defect.

**Chosen semantics: partition, not conflict.** A cross-principal collision could instead have raised `IDEMPOTENCY_CONFLICT`, which is louder. Partitioning is chosen because a conflict would let one principal's key choice refuse another principal's unrelated and entirely legitimate mutation — one publisher could deny another by claiming keys. Under partitioning the two mutations are simply independent: each executes, each is audited, each receives its own response.

The identity bound is `AccountInterface::id()`, the only identity claim guaranteed stable across requests. `AuthorizationPrincipalInterface::claimsGeneration()` deliberately rotates, so keying on it would silently break a principal's own replays after an ordinary claims refresh. An integer uid and its digit-string form canonicalize to the same partition without narrowing digit strings through PHP's platform integer range, matching how the rest of this surface (`SaveContext::withActorUid()`, the audit record) treats ordinary integer ids while keeping distinct large external ids distinct; a non-numeric identifier is bound verbatim under a separate prefix. Namespace components are NUL-separated and a null bundle has a typed encoding distinct from a literal bundle value, so no entity type, bundle, or principal id can be re-partitioned by a colliding concatenation.

Consequences a consumer should know:

- **Within one principal, nothing changes.** Same key + same payload still replays the stored response without re-executing; same key + different payload still raises `IDEMPOTENCY_CONFLICT`. Both are pinned by test.
- **Cross-principal replay is removed, deliberately.** Any consumer that relied on one principal's key shielding another's duplicate submission no longer gets that. Deduplication across principals was never a contract this surface offered; it was a collision.
- **Existing stored records become unreachable** at upgrade, because the namespace they were written under no longer matches. No migration is required or supplied: the rows are TTL-swept within 48 hours, and the only observable effect in that window is that an in-flight retry re-executes instead of replaying — which is the ordinary at-least-once behaviour a caller retrying an unacknowledged mutation must already tolerate.
- **The server-owned author component of the `createDraft` fingerprint is retained** as defence in depth, but the namespace is now what separates two authors under one client key.

Pinned by `ContentPublisherTest::two_principals_sharing_a_key_and_payload_do_not_replay_each_others_response()`, `::each_principal_receives_its_own_response_under_a_shared_key()`, `::a_second_principal_reusing_a_publish_key_executes_rather_than_replaying()`, `::a_second_principal_reusing_a_rollback_key_executes_rather_than_replaying()`, and `::principal_partitioning_treats_an_integer_and_its_digit_string_as_one_principal()`, alongside the unchanged single-principal replay and conflict tests.

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

`ContentToolSet::register(ToolRegistryInterface $registry, string $prefix)`
hand-registers (hand-registered tools win over discovery) the tool set under
app-chosen stable names. The descriptor and the asset store are **constructor**
arguments, not `register()` arguments —
`new ContentToolSet(ContentPublisher, ContentTypeDescriptor, PreviewLinkService,
Closure $previewUrl, ?AssetStoreInterface $assets = null, string $assetPrefix = 'asset')`.
The `asset.*` tools are registered only when an `AssetStoreInterface` is
supplied; the framework binds no default, so a deployment that wants agent
uploads opts in by constructing one. For rhtcircle:

`article.list`, `article.get`, `article.createDraft`, `article.updateDraft`, `article.preview`, `article.publish`, `article.unpublish`, `article.revisions`, `article.rollback`, `asset.upload`, `asset.get`.

- Every tool: `#`capability = descriptor's `publishCapability`; mutation tools `destructive: true` → structurally absent from the public `/mcp` registry; reachable only through `/mcp/write` when the capability is on the write-tier allowlist.
- Input schemas: JSON Schema draft 2020-12, `additionalProperties: false`, derived from the descriptor's writable fields; content mutations require `idempotency_key` and update/publish/unpublish require `expected_revision_id` — but `asset.upload`, though `destructive: true`, requires **neither**; its schema is exactly `{filename, content_base64}`. Sending `idempotency_key` to it is rejected by `additionalProperties: false`, the same trap as the `alt` field. Retried uploads therefore accrete one `media` row per attempt over identical bytes (#1639).
- Errors: structured `{code, message, errors?: [{field, message}], meta?: object}` in the MCP `isError` envelope — `VALIDATION_FAILED` (field-specific), `REVISION_CONFLICT` (with expected/current), `IDEMPOTENCY_CONFLICT`, `SLUG_TAKEN` (field-level on the slug field), `SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED` (candidate-bound advisory metadata), `NOT_FOUND`, `UNAUTHORIZED`, and `ASSET_REJECTED` (the `asset.upload` refusal: empty upload, oversize, undecodable base64, unsniffable or non-approved type, undecodable image).
- No tool input is ever a filesystem path, SQL, Twig, or executable content; asset bytes are base64 with size caps; responses never include credentials or personal data.
- `asset.upload {filename, content_base64}`: media create access for the configured bundle is required before any bytes are written. Accepted bytes go through the media `UploadHandler` contract — fail-closed `finfo` MIME sniffing (client MIME ignored, and an unsniffable file is rejected), an approved-type allowlist, and a size cap. The client filename is never consulted — there is deliberately **no** filename/sniff agreement check — and never reaches the filesystem: bytes are stored under their own `sha256` content hash plus the **sniffed** type's extension, so PNG bytes named `photo.jpg` are accepted and land as `<sha256>.png`, and identical bytes deduplicate. A `media` entity is then created through the repository, so lifecycle events and auditing apply. Core `media` is **not** revisionable, so the save context alone cannot preserve authorship — the actor is recorded durably on the row's `uid` owner field whenever its id is numeric. Returns `{asset_id, media_id, url, mime, width, height, size}`. `asset.get {asset_id}` returns the same payload, gated on the catalog row's `view` access. Approved types are `image/png`, `image/jpeg` and `image/webp`, fixed by `MediaAssetStore::APPROVED_TYPES` — not descriptor-configurable. The size cap is the store's `$maxSizeBytes` constructor argument (default 5 MiB), and rows are written under its `$bundle` argument (default `image`).

### The asset catalog row is the authority (#2517)

The `media` row `asset.upload` writes is not bookkeeping — it governs the
asset's reachability, on both surfaces:

- **`source_uri` is scheme-qualified** (`public://<path under the media files root>`),
  which is the only shape `MediaDownloadRouter::resolvePublicPath()` resolves. A
  scheme-less value made the framework write rows its own authorized download
  route could not serve, so a consumer needing gated retrieval had no supported
  path and was pushed toward serving the bytes directly. `MediaAssetStore` takes
  the media files root (config `files_root`, default `<project>/storage/files` —
  the same directory `MediaServiceProvider` hands the router) and refuses at
  construction if its uploads directory is not inside it, `..` included.
- **`media_id`** is the identifier `/media/{id}/download` and `/media/{id}/view`
  are keyed by. `url` remains the public URL and is unchanged.
- **`asset.get` is gated on that row's `view` access**, using the principal it
  has always accepted. Bytes on disk with no catalog row are not an asset.
  Re-uploading the same bytes writes another catalog row (the file is
  content-addressed); `asset.get` returns the first matching row the principal
  may `view`, so an unpublished duplicate does not hide a later published one.

**Retraction.** The row is the authority, so unpublishing or deleting it
withdraws the asset from `asset.get` and from the authorized download route
immediately. The bytes stay on disk: they are content-addressed, may be shared
by other rows, and `AssetStoreInterface` exposes no retraction primitive with
which to express byte deletion — that remains the open interface question the
issue records, not a prerequisite for the gate.

Rows written before this change carry the old scheme-less `source_uri`. They are
still matched on read — under the access check they never had — but the
authorized route cannot serve them until the asset is re-uploaded.

Pinned end to end by
`packages/ai-tools/tests/Integration/AgentUploadedAssetIsAuthorizedDownloadableTest.php`,
which composes the real store and the real `MediaDownloadRouter` over the real
`MediaAccessPolicy`: neither half is wrong alone, only the pair.

## MCP rate limiting (`packages/mcp`)

`McpEndpoint` uses `AtomicRateLimiterInterface` + config `mcp.rate_limit.{max_requests,window_seconds}` (default 120/60; explicit integer zero disables). It is keyed per resolved principal id + tier. Exceeded → JSON-RPC error `-31029` (`McpErrorCode::RATE_LIMIT_EXCEEDED`) "Rate limit exceeded" with `retryAfter`; inability to obtain a durable decision fails closed with sanitized `-31030` (`McpErrorCode::RATE_LIMITER_UNAVAILABLE`) / HTTP 503. Both were renumbered out of MCP's reserved sub-range by #2561 — see `docs/specs/mcp-endpoint.md` "Error Codes".

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
