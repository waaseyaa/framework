# Versioned-Blob Media Abstraction — `MediaVersion` Entity, Content-Addressed Storage, Per-Version Access

**Mission:** `versioned-blob-media-abstraction-01KSEFTJ`
**Target branch:** `main`
**Tracks:** No GitHub issue (Wave 2 framework substrate). Closes gap-matrix row **A1** ("Versioning on files/blobs is not yet first-class") + alpha-to-beta-plan §1 substrate item **#5**. Charter directives: **DIR-004** (OCAP-by-architecture), **DIR-005** (two-axis storage preservation — this mission is an axis-preserving EXTENSION of two-axis storage to blob content, NOT a replacement), **DIR-006** (codified gates).
**Pattern reference (CANONICAL):** `docs/specs/entity-storage-two-axis.md` (the canonical `(entity_id, langcode, vid)` substrate that this mission mirrors at the blob layer) and `docs/specs/entity-storage-translatable-revisions.md`; `packages/media/src/{Media,File,FileRepositoryInterface,LocalFileRepository,MediaAccessPolicy,UploadHandler}.php` (existing media surface that this mission extends); `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` (M5A read-contract pattern for the version-listing endpoint); CodifiedContext pattern (`docs/specs/codified-context-integration.md`).
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` (must be merged — WP02 onward writes `media.version.created` + `media.version.read` audit events). SHOULD have `classification-retention-engine-01KSEFTH` merged before WP02 enters implementation (label inheritance from parent entity propagates to media versions); if classification mission has not landed, WP02 ships without label propagation and the gap is documented as a known follow-up.

## Why this mission exists

Per gap-matrix row A1: `media` backends exist (S3, local, custom) and `attachment` for file-on-entity composition exists, but **revisioning on files/blobs is not yet first-class** — revisions live on entities, not on file content. The framework's two-axis substrate (`docs/specs/entity-storage-two-axis.md`) gives entities a revisionable axis and a translatable axis; blobs get neither. A Nation that uploads version 2 of a sacred-knowledge PDF cannot view the v1 / v2 / v3 history with the same audit fidelity as an entity revision; restoring a previous version requires re-uploading; an admin investigating a leak cannot prove which version was downloaded at audit time.

This mission ships `MediaVersion` — an axis-preserving extension that mirrors `entity-storage`'s revisionable axis specifically for blob content. Each save creates a new `MediaVersion` row carrying `blob_uri`, `sha256`, `created_at`, `created_by`. Storage uses content-addressing (sha256 keys): two uploads of byte-identical content share one blob URI but get distinct `MediaVersion` rows tying the upload event to its provenance. Per-version access checks run through `AccessChecker` so a forbidden version is not enumerable. Cross-mission integration: `MediaVersion` inherits classification labels from its parent media entity (composing on the classification mission's `LabelInheritanceResolver`); creation + read fire audit events through the OCAP substrate.

Two downstream missions compose on this: `offline-first-sync-substrate-01KSEFTM` (Drive WP03 / Docs WP03 use version metadata for offline-blob caching) and the M-A5 flagship `per-record-ai-access-flagship-*` (AI tools that read attached media check per-version `AccessChecker` decisions).

## Scope

### In scope

**Layer 2 — `packages/media` extension:**
- New entity `MediaVersion` (`extends ContentEntityBase`). Fields: `id` (autoinc), `uuid`, `media_uuid` (string — points to parent `Media` entity), `vid` (auto-increment per-`media_uuid`, mirroring two-axis storage's `vid` semantics), `blob_uri` (string — points into the storage backend), `mime` (string), `size_bytes` (int), `sha256` (string, indexed — content hash), `created_at` (datetime), `created_by` (int — author uid).
- Schema: standalone table `media_version` + `media_version_data`. Indices: `(media_uuid, vid DESC)` for fast tip-resolution; `(sha256)` for dedup; `(created_at)` for retention scans.
- `MediaVersionType` registered via `MediaServiceProvider::register()`.

**Layer 2 — content-addressed write path:**
- `Waaseyaa\Media\Version\ContentAddressedFileRepositoryDecorator` (`@api`) — decorates `FileRepositoryInterface`. On write, computes `sha256` of the incoming bytes BEFORE writing; checks whether a blob with that hash already exists at a canonical content-addressed URI (e.g. `cas://sha256/{first2chars}/{rest}`); if yes, reuses the URI (no new write); if no, writes once and returns the URI.
- Storage backends keep their existing semantics; the decorator only changes the URI scheme (the decorator stores a small key→URI mapping in a `media_version_blob` reference table OR the decorator computes the URI deterministically from sha256 + backend prefix — implementer chooses; pre-resolved decision: deterministic prefix-based URI under `cas://` is simpler and survives backend change).
- `MediaVersionRepository` (`@api`) — high-level: `findVersionsForMedia(string $mediaUuid): iterable<MediaVersion>` (ordered `vid DESC`), `tip(string $mediaUuid): ?MediaVersion`, `findByVid(string $mediaUuid, int $vid): ?MediaVersion`.

**Layer 2 — storage driver hook (every media save creates a version):**
- `Waaseyaa\Media\Version\MediaVersionStorageDriver` wraps `Media` save: on every `Media::save()`, after the entity is persisted, the driver:
  1. Reads the current uploaded byte stream (if the save included a file upload — via `UploadHandler`).
  2. Calls `ContentAddressedFileRepositoryDecorator::write()` → returns `blob_uri` + `sha256` (+ dedupes against prior content).
  3. Computes next `vid` for this `media_uuid` (`MAX(vid) + 1`, or 1 for first).
  4. Inserts a `MediaVersion` row with `media_uuid`, `vid`, `blob_uri`, `mime`, `size_bytes`, `sha256`, `created_at = now()`, `created_by = $account->id()`.
  5. Dispatches a `Waaseyaa\Audit\Contract\AuditEventDescriptor` of kind `media.version.created` (NEW audit-event-kind that must be added to the audit substrate enum — see below) via injected `AuditWriterInterface`. Attributes: `{media_uuid, vid, sha256, mime, size_bytes, dedup_hit: bool}`.
- If the media save does NOT include a new upload (metadata-only update), the driver does NOT create a new `MediaVersion`. Documented in spec.md §"Decisions pre-resolved".

**Layer 2 — access policy:**
- `Waaseyaa\Media\Version\MediaVersionAccessPolicy` (`@api`) `implements AccessPolicyInterface, FieldAccessPolicyInterface`. Delegates to the parent media entity's `MediaAccessPolicy` for the canonical access decision; per-version refinement: a future per-version classification override (if WP02 implements it) is consulted here. For this mission's MVP: a version's access = parent media's access. The class exists for future per-version policy extensions.
- A **forbidden version is not enumerable**: `MediaVersionRepository::findVersionsForMedia()` filters via `AccessChecker` per the canonical `setAccount()` pattern. The list endpoint (WP03) returns only versions the requesting account can access.

**Layer 4 — `packages/api`:**
- `Api\Media\MediaVersionResource` — JSON:API resource DTO.
- `Api\Media\MediaVersionReadModelInterface` (api-local; CodifiedContext pattern) — `findForMedia(string $mediaUuid, AccessChecker $checker, AccountInterface $account): iterable<MediaVersionResource>`, `findByVid(string $mediaUuid, int $vid, ...): ?MediaVersionResource`.
- `Controller\MediaVersionController::index(string $mediaUuid)` → `GET /api/media/{uuid}/versions`. `Controller\MediaVersionController::show(string $mediaUuid, int $vid)` → `GET /api/media/{uuid}/versions/{vid}`. Both: `_authenticated` at route level (per-version access enforced at adapter layer via `AccessChecker` + parent-media policy).
- `Http/Router/MediaVersionApiRouter` — JSON:API router.
- `ApiServiceProvider::httpDomainRouters()` adds the resolveOptional + wire block.
- `packages/api/composer.json` adds `"waaseyaa/media": "^<exact-tag>"` to **require-dev** + path repo (mirror M5A `ai-observability` pattern).
- Optional: download endpoint `GET /api/media/{uuid}/versions/{vid}/blob` — streams content from `FileRepositoryInterface` for the resolved `blob_uri`, after `AccessChecker` decision. This MAY ship in WP03 OR may be deferred to a "media-version-download-*" follow-up — implementer chooses; preference: ship the metadata endpoints in WP03, defer the binary-stream endpoint (binary streaming has its own perf/range-request complexity).

**Audit substrate amendment (coordinates with OCAP audit mission):**
- This mission adds three new event kinds to `Waaseyaa\Audit\Enum\AuditEventKind` (in `packages/audit`, owned by `ocap-audit-log-substrate-01KSEFTF`): `media.version.created`, `media.version.read`, `media.version.dedup_hit`. The OCAP audit spec.md called out (in §"Out-of-band") that downstream missions may amend the enum; this mission performs that amendment. The audit substrate enum is closed (FR-004 of that mission), so this MUST be an enum extension committed in this mission's WP02 with a corresponding test in `packages/audit/tests/` updated (or asserting the new kinds via the contract test in that package).

**Layer 6 — `packages/admin` SPA:**
- New composable `app/composables/useMediaVersions.ts` — `{versions, loading, error, fetchVersions(mediaUuid)}`.
- New component `app/components/media/MediaVersionBrowser.vue` — table of versions for a given media item: vid, mime, size (formatted), sha256 (truncated), created_at, created_by, "view" link (to the binary endpoint if shipped; else a coming-soon placeholder).
- Integrated into the existing media-edit page (verify location: likely `packages/admin/app/pages/media/[id].vue` — if no such page exists, create the smallest viable shell). The version browser shows below the main editor.
- i18n keys in `packages/admin/app/i18n/en.json`.

**Docs:**
- `docs/specs/versioned-blob-media.md` — new spec.
- `CLAUDE.md` orchestration table — add row for `packages/media/src/Version/*` → `docs/specs/versioned-blob-media.md`.
- `docs/specs/entity-storage-two-axis.md` — append cross-reference note that blob-versioning extends this substrate pattern at the blob layer (DIR-005 axis-preservation).
- `CHANGELOG.md` `[Unreleased]` → **Added**.

### Out of scope (→ separate missions / follow-ups)

- **Translatable blob content** (parallel to two-axis storage's translatable axis applied to blobs — e.g. an audio recording in Anishinaabemowin AND English). Deferred. The current axis extension is revisionable only.
- **Binary streaming endpoint** (`GET /api/media/{uuid}/versions/{vid}/blob` with range support, content-disposition, range requests). May ship in WP03 if implementer judges it bounded; otherwise deferred to `media-version-download-*`.
- **Garbage-collection of orphaned content-addressed blobs.** If a `MediaVersion` row is deleted (which today does NOT happen — versions are append-only by convention) the blob remains. A future `media-version-gc-*` mission can sweep orphans. Documented as a known follow-up.
- **Per-version classification override.** This mission ships `MediaVersionAccessPolicy` as a delegate-to-parent-media. A future mission can add per-version classification labels (if a Nation classifies v1 as `confidential` but v2 (redacted) as `internal`). Documented.
- **Storage-backend migrations.** Existing `MediaServiceProvider` backends (local / S3 / custom) keep their semantics. The CAS decorator is additive — existing media URIs don't change shape; only NEW writes go through CAS.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | `MediaVersion` entity is registered in `MediaServiceProvider::register()`. Schema fields and indices match spec.md §In-scope exactly. Persistence via `SqlStorageDriver` over `DatabaseInterface` per `.claude/rules/entity-storage-invariant.md`. |
| FR-002 | functional | `ContentAddressedFileRepositoryDecorator implements FileRepositoryInterface`. On `write($bytes, $mime)`: computes sha256; if a blob with that hash exists at `cas://sha256/{first2}/{rest}`, returns the existing URI; else writes once at that URI; returns `{uri, sha256}`. Idempotent — repeated writes of byte-identical content return the same URI. |
| FR-003 | functional | `MediaVersionStorageDriver` is installed in the `Media` save pipeline. On every `Media::save()` that includes a new upload (verified via `UploadHandler` state), creates exactly one `MediaVersion` row with `vid = max(vid) + 1` for that `media_uuid` (or `1` for first version). Saves with no new upload do NOT create a new version. |
| FR-004 | functional | Each `MediaVersion` creation dispatches `AuditEventDescriptor(eventKind: AuditEventKind::MediaVersionCreated, attributes: {media_uuid, vid, sha256, mime, size_bytes, dedup_hit})` via injected `AuditWriterInterface`. Best-effort: audit-write failure does not block the save. |
| FR-005 | functional | The audit-substrate enum (`packages/audit/src/Enum/AuditEventKind.php` owned by `ocap-audit-log-substrate-01KSEFTF`) is extended with three new kinds: `media.version.created`, `media.version.read`, `media.version.dedup_hit`. The OCAP audit substrate's contract / unit test in `packages/audit/tests/` is updated to assert the new kinds. This is a coordinated amendment — see Risks. |
| FR-006 | functional | `MediaVersionRepository` provides `findVersionsForMedia()` + `tip()` + `findByVid()`. `findVersionsForMedia()` filters per-version via `AccessChecker::setAccount($account)->accessCheck(true)` so a forbidden version is NOT returned (FR-014 enforces). Uses `DatabaseInterface::select()` (NOT `getQuery()` — no new `bin/check-getquery-bindings` baseline entries). |
| FR-007 | functional | `MediaVersionAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface`. Class-level `#[PolicyAttribute(entityType: 'media_version')]`. Delegates `access()` decisions to the parent `Media` entity's `MediaAccessPolicy`. `fieldAccess()` follows open-by-default per `docs/specs/field-access.md`. |
| FR-008 | functional | `GET /api/media/{uuid}/versions` returns a JSON:API list of versions the authenticated account can access, ordered `vid DESC`. Response shape: `{data: [...MediaVersionResource], meta: {total}}`. `_authenticated` at route level. |
| FR-009 | functional | `GET /api/media/{uuid}/versions/{vid}` returns the single `MediaVersionResource` for that `vid`, OR 403 if the version is access-restricted, OR 404 if the `vid` does not exist for the given `media_uuid`. |
| FR-010 | functional | `MediaVersionReadModelInterface` lives in `packages/api/src/Media/`. `Waaseyaa\Media\Version\Adapter\ApiMediaVersionAdapter` implements it. Binding via `MediaServiceProvider` → `resolveOptional` in `ApiServiceProvider::httpDomainRouters()` → wire `MediaVersionApiRouter` only when bound. Null read-model → empty-shape responses. |
| FR-011 | functional | Classification-label inheritance (if `classification-retention-engine-01KSEFTH` is merged): `MediaVersion` inherits its parent `Media`'s classification label via `LabelInheritanceResolver` (registered for the `media_version` entity type via `MediaVersionParentResolver implements ClassificationParentResolverInterface`). Verified by integration test. If the classification mission has NOT merged at WP02 implementation time, the parent-resolver class is still shipped (marked `@api`) but the field-side wiring is documented as a follow-up. |
| FR-012 | functional | Admin SPA: `useMediaVersions` composable + `MediaVersionBrowser.vue` component, integrated into the media-edit page. Renders the version table; clicking a version row opens a detail view (vid + sha256 + provenance). Vitest unit test for the composable. |
| FR-013 | functional | A **kernel-boot integration test** (`MediaVersioningIntegrationTest`) seeds: one media item, three uploads of distinct content + one re-upload of identical-to-v1 content. Asserts: 4 `MediaVersion` rows created; v1 and v4 share the same `blob_uri` + `sha256` (dedup); 4 `media.version.created` audit events recorded with `dedup_hit = true` on v4; `GET /api/media/{uuid}/versions` returns 4 entries ordered `vid DESC`; `GET /api/media/{uuid}/versions/2` returns the v2 resource. |
| FR-014 | functional | A **forbidden-version-not-enumerable test** seeds: one media item visible to admin but with a per-policy filter that hides versions before vid=2 for non-admin accounts (use a test-only `MediaVersionAccessPolicy` extension or stub). Asserts: admin sees all versions; restricted account sees only versions ≥ vid=2; the count/total reflects the filtered list. |
| FR-015 | functional | `docs/specs/versioned-blob-media.md` is created and cross-referenced from CLAUDE.md orchestration table. `docs/specs/entity-storage-two-axis.md` is appended with a cross-reference note about blob-versioning being an axis-preserving extension. `CHANGELOG.md` `[Unreleased]` → **Added** records the new substrate. |
| NFR-001 | non-functional | DIR-005 honoured: this mission EXTENDS the two-axis storage substrate's revisionable-axis pattern to blob content — it does NOT replace, remove, or alter the existing two-axis substrate. The `vid` semantics in `MediaVersion` mirror `(entity_id, vid)` from two-axis storage (entity_id maps to `media_uuid`); langcode is NOT applied to blob content in this mission (translatable blob content is out of scope per spec.md). |
| NFR-002 | non-functional | DIR-004 honoured: every blob write produces a `media.version.created` audit event; every blob read (download via the binary endpoint, if shipped) produces a `media.version.read` audit event; dedup hits are auditable via the `dedup_hit` attribute. |
| NFR-003 | non-functional | DIR-006 honoured: codified gates green (`bin/check-package-layers`, `bin/check-dead-code` with `@api` on every public surface, `bin/check-getquery-bindings` with no new unbound chains, `bin/check-composer-policy` with pinned internal constraints). |
| NFR-004 | non-functional | Cross-layer wiring follows the CodifiedContext pattern: `api` (L4) uses an api-local `MediaVersionReadModelInterface`; the adapter lives in api itself (composing the L2 `MediaVersionRepository`); `media` (L2) does not import api symbols. `bin/check-package-layers` green. |
| NFR-005 | non-functional | Performance: dedup-hit path adds < 5ms per save (sha256 of typical-payload-size buffer + indexed table lookup). Documented as a perf-budget note in the spec; not a merge blocker but smoke-tested in the integration test. |
| C-001 | constraint | Per-save behaviour: a `Media` save that does NOT include a new file upload does NOT create a new `MediaVersion`. (Metadata-only saves don't bump the blob-version axis.) |
| C-002 | constraint | Content-addressed URI scheme `cas://sha256/{first2}/{rest}` is canonical. Storage backends may translate this to their native scheme internally, but the public URI shape returned by `ContentAddressedFileRepositoryDecorator::write()` is `cas://...`. |
| C-003 | constraint | DIR-005 axis preservation: this mission MUST NOT alter the existing two-axis storage substrate (`packages/entity-storage`, `docs/specs/entity-storage-two-axis.md`). `MediaVersion` is a NEW table for blob versioning; it composes alongside two-axis entity storage, not on top of it. |
| C-004 | constraint | The audit-substrate enum amendment (FR-005) is the ONLY change this mission makes to `packages/audit`. Coordinates with the OCAP audit substrate mission per its §"Out-of-band" — that mission anticipated downstream-mission amendments to the enum. The amendment is additive (new cases); existing cases are NOT renamed or removed. |
| C-005 | constraint | A version-deletion path is NOT implemented in this mission. `MediaVersion` rows are append-only by convention (no delete API, no admin delete button). Future GC of orphaned CAS blobs is a separate mission. |

## Acceptance

- All 15 FRs / 5 NFRs / 5 constraints honoured.
- Gates green: `vendor/bin/phpunit`, `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- Integration test `MediaVersioningIntegrationTest` (FR-013) passes; dedup correctness + audit-event count verified.
- Forbidden-version test (FR-014) passes; admin sees all, non-admin sees filtered list.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Reviewer (Opus) confirms: (a) DIR-005 axis preservation — no change to entity-storage two-axis; (b) CAS dedup correctness — identical bytes share URI; (c) audit-substrate enum amendment is additive and coordinated; (d) forbidden version is NOT enumerable in the API list.

## Risks

- **Audit-substrate enum coordination.** FR-005 amends `packages/audit/src/Enum/AuditEventKind.php` owned by another mission. Risk: a parallel change in that package by another stream. Mitigation: WP02 explicitly owns the enum file in `wps.yaml` (overlap with the audit mission's WP01); the audit mission's reviewer should already have approved the principle that downstream missions amend the enum (per OCAP audit spec.md §"Out-of-band"). Coordinate via the cluster's wave-plan document.
- **CAS dedup race.** Two concurrent identical uploads could both compute the same sha256 and both attempt to write. The decorator checks-then-writes; if the second `write()` overwrites the first, the content is byte-identical so the URI semantics still hold. Mitigation: use `file_put_contents` with `LOCK_EX` for local backend; S3 backend's PUT is atomic. Document the semantic ("last-writer-wins with byte-identical content is a no-op").
- **Blob deletion orphans.** Per C-005, version-deletion is not in scope. If a `Media` entity is fully deleted via the framework's entity-delete path, its `MediaVersion` rows SHOULD cascade-delete (mirror what `RevisionableStorageDriver` does for two-axis entities). Mitigation: WP01 ships the cascade-delete; orphaned CAS blobs are a separate GC mission's problem.
- **MIME / size-byte spoofing.** The `mime` and `size_bytes` recorded on `MediaVersion` come from the upload metadata, not from re-reading the stored blob. A malicious caller could lie. Mitigation: `MediaVersionStorageDriver` re-computes `size_bytes` from the actual byte stream after CAS write; `mime` is recomputed server-side via `mime_content_type()` or `fileinfo`. Document the canonical re-derivation.
- **Classification inheritance brittleness.** If `classification-retention-engine-01KSEFTH` has NOT merged at WP02 implementation time, FR-011 is partial. Mitigation: WP02 ships the `MediaVersionParentResolver` class but the field-side wiring is documented as a follow-up + a TODO comment in the registration spot.

## Decisions pre-resolved

- **Content-addressing URI scheme: `cas://sha256/{first2}/{rest}`.** Two-char shard prefix avoids massive single-directory file counts; sha256 is the only hash algorithm at v1 (future migration to a different hash algorithm would be a re-version of the URI scheme, with both readable side-by-side for backward compat).
- **Append-only by convention, not by storage enforcement.** Unlike `AuditEvent` (which the audit substrate enforces append-only at the driver), `MediaVersion` is conventionally append-only — the framework's `delete()` path is intentionally not exposed in WP01 / WP02 / WP03 APIs, but the underlying storage driver permits it (needed for cascade-delete on parent media). A "soft delete" / tombstone strategy is NOT shipped at v1.
- **Per-save dedup, not async dedup.** Dedup happens synchronously on save via the decorator. Asynchronous post-write dedup (a job that walks new uploads and merges duplicates) is NOT shipped — premature optimisation; sync dedup is correct and the perf budget allows it.
- **The adapter lives in `packages/api/src/Media/` (the api side).** Mirroring M5A's pattern and the OCAP audit mission's resolution to the layer-conflict question. `packages/media` (L2) is below api (L4); api may import media types directly, but the adapter pattern preserves the option of a Nation swapping in their own version-listing read model.
- **Schedule integration: NO new scheduled job in this mission.** Cascade-delete on parent media-delete is sufficient for v1. Orphan-blob GC is a separate mission.
- **No binary-stream endpoint in this mission (default).** The list + detail metadata endpoints are sufficient for offline-first-sync substrate (which needs URIs to fetch through Workbox) and admin browser. Implementer may include the stream endpoint in WP03 if scope allows; reviewer approves either decision.

## Decisions deferred to implementer

- **Whether to deterministically derive the storage-backend URI from `cas://...` or to maintain a key→URI mapping table.** Prefer deterministic derivation (each backend prefixes `cas://sha256/AB/CD...` with its own root e.g. `s3://nation-bucket/cas/AB/CD...` or `local:///var/waaseyaa/cas/AB/CD...`). Mapping table is fallback only if a backend can't honour deterministic shapes.
- **Whether to ship the binary-stream endpoint (`GET /api/media/{uuid}/versions/{vid}/blob`) in WP03 OR defer.** Recommendation: defer (scope discipline). If shipped: include `Content-Disposition: attachment` + `ETag: {sha256}` + range-request support.
- **Whether `MediaVersionParentResolver implements ClassificationParentResolverInterface` is added in WP02 (if classification mission is merged) OR WP04 (if classification mission lands during this mission's lifetime).** Implementer should check the cluster wave-plan at WP02 start time.

Decision preference order per DIR-006: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- This mission SHOULD merge before `offline-first-sync-substrate-01KSEFTM` enters its WP01 (Drive metadata + downloaded-blob substrate composes on version URIs + sha256).
- This mission MAY merge in parallel with `per-record-ai-access-flagship-*` — the flagship mission's per-record `AccessChecker` integration covers `MediaVersion` access automatically once the version-access policy delegates to the parent media (FR-007).
- Future: a `media-version-translatable-*` mission for translatable blob content (multilingual audio/video). Out of scope here.
- Future: a `media-version-gc-*` mission for orphaned CAS blob garbage collection. Out of scope here.
