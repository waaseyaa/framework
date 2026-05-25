# Implementation Plan: Versioned-Blob Media Abstraction

**Mission:** `versioned-blob-media-abstraction-01KSEFTJ` — see `spec.md`.
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` merged. `classification-retention-engine-01KSEFTH` SHOULD be merged before WP02 enters implementation (label propagation).
**Pattern references (READ FIRST):** `docs/specs/entity-storage-two-axis.md` + `entity-storage-translatable-revisions.md`; `packages/media/src/{Media,File,FileRepositoryInterface,LocalFileRepository,MediaAccessPolicy,MediaServiceProvider,UploadHandler}.php`; `packages/entity-storage/src/Driver/RevisionableStorageDriver.php` (mirror the per-`(parent, vid)` write semantics); `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + `ObservabilityServiceProvider.php` (M5A shipped exemplar); `docs/specs/codified-context-integration.md`; `.claude/rules/entity-storage-invariant.md`.

**Four WPs.** WP01 ships the entity + CAS decorator + repository. WP02 ships the storage-driver hook + audit-enum amendment + classification parent-resolver. WP03 ships the API surface. WP04 ships the admin SPA browser + docs. WP02 depends on WP01; WP03 depends on WP01 + WP02; WP04 depends on WP03.

## WP01 — `MediaVersion` entity, CAS decorator, repository

### `MediaVersion` entity + schema (T-A)
- `packages/media/src/Version/MediaVersion.php extends ContentEntityBase`. `__construct(array $values = [])` hardcodes `entity_type_id = 'media_version'` + `entity_keys = ['id' => 'id', 'uuid' => 'uuid']`. `@api`.
- `packages/media/src/Version/MediaVersionType.php` — `EntityType` value. Properties match spec.md.
- Migration `packages/media/migrations/2026_05_25_000005_create_media_version_table.php` — `media_version` + `media_version_data`. Indices: `(media_uuid, vid DESC)`, `(sha256)`, `(created_at)`.

### Content-addressed decorator (T-B)
- `packages/media/src/Version/ContentAddressedFileRepositoryDecorator.php implements FileRepositoryInterface`. `@api`. Constructor `(FileRepositoryInterface $inner, ?LoggerInterface $logger = null)`.
- `write(string $bytes, string $mime, ?string $hint = null): FileWriteResult` (extend or add to the existing interface — verify shape):
  1. Compute `$sha256 = hash('sha256', $bytes)`.
  2. Compute canonical URI: `$casUri = sprintf('cas://sha256/%s/%s', substr($sha256, 0, 2), substr($sha256, 2))`.
  3. Check existence via `$this->inner->exists($casUri)` (extend the interface if `exists()` doesn't exist).
  4. If exists → return `FileWriteResult(uri: $casUri, sha256: $sha256, dedupHit: true, sizeBytes: strlen($bytes))`.
  5. Else → `$this->inner->write($bytes, $mime, $casUri)`; return `FileWriteResult(uri: $casUri, sha256: $sha256, dedupHit: false, sizeBytes: strlen($bytes))`.
- `FileWriteResult` is a new readonly DTO in `packages/media/src/File/FileWriteResult.php` (if a similar DTO doesn't already exist — verify; otherwise reuse).

### `MediaVersionRepository` (T-C)
- `packages/media/src/Version/MediaVersionRepository.php` (`@api`). Constructor: `(EntityRepositoryInterface $entityRepo, DatabaseInterface $db, ?AccessChecker $checker = null)`.
- `findVersionsForMedia(string $mediaUuid, ?AccountInterface $account = null): iterable<MediaVersion>`:
  - Uses `DatabaseInterface::select('media_version')` query builder; condition `media_uuid = $mediaUuid`; order `vid DESC`.
  - If `$account !== null && $this->checker !== null`: filter each result via the policy (use `$this->checker->setAccount($account)->access($mediaVersion, 'view')`; only yield allowed).
  - NEVER `getQuery()` (keep `bin/check-getquery-bindings` baseline at zero new entries).
- `tip(string $mediaUuid): ?MediaVersion` — `findVersionsForMedia` first.
- `findByVid(string $mediaUuid, int $vid): ?MediaVersion`.

### Tests for WP01 (T-D)
- `packages/media/tests/Unit/Version/MediaVersionTest.php` — entity construction + schema sanity (table column presence).
- `packages/media/tests/Unit/Version/ContentAddressedFileRepositoryDecoratorTest.php` — write of new bytes returns dedupHit=false; second write of identical bytes returns dedupHit=true + same URI; concurrent writes are idempotent (last-writer-wins on byte-identical content).
- `packages/media/tests/Unit/Version/MediaVersionRepositoryTest.php` — seed 3 versions via SQLite; assert `findVersionsForMedia` ordering; with `AccessChecker` filtering out vid=1 → list returns [v3, v2].

## WP02 — Storage-driver hook + audit-enum amendment + parent resolver

### `MediaVersionStorageDriver` (T-E)
- `packages/media/src/Version/MediaVersionStorageDriver.php`. Listener / subscriber to `Media` save events (or wraps `MediaRepository::save()` — verify exact integration point in `MediaServiceProvider`).
- After every `Media::save()` that included an upload (detect via `UploadHandler` state or via a transient property on the entity set by the upload pipeline):
  1. Compute `$nextVid = $db->select('media_version')->fields('media_version', ['vid'])->condition('media_uuid', $media->uuid())->orderBy('vid', 'DESC')->range(0, 1)->execute()->fetchField() ?? 0` + 1.
  2. Call `$decorator->write($bytes, $mime)` → `FileWriteResult`.
  3. Re-derive size + mime from the actual stored stream (Risk mitigation in spec.md): `$sizeBytes = strlen($bytes)`; `$mime = mime_content_type($tmpFile) ?? $requestMime`.
  4. Create new `MediaVersion` via repository: `$repo->save(new MediaVersion(['media_uuid' => $media->uuid(), 'vid' => $nextVid, 'blob_uri' => $result->uri, 'mime' => $mime, 'size_bytes' => $sizeBytes, 'sha256' => $result->sha256, 'created_by' => $account->id()]))`. Remember `->enforceIsNew()` per CLAUDE.md entity-system gotcha.
  5. Dispatch `$auditWriter->record(new AuditEventDescriptor(eventKind: AuditEventKind::MediaVersionCreated, entityType: 'media', entityUuid: $media->uuid(), accountUid: $account->id(), attributes: ['vid' => $nextVid, 'sha256' => $result->sha256, 'mime' => $mime, 'size_bytes' => $sizeBytes, 'dedup_hit' => $result->dedupHit]))`.
- Whole body try-catch wrapped — audit-write or version-write failure logged but does NOT bubble up to break the primary `Media::save()`.

### Audit-enum amendment (T-F)
- Edit `packages/audit/src/Enum/AuditEventKind.php`: append three new cases — `MediaVersionCreated = 'media.version.created'`, `MediaVersionRead = 'media.version.read'`, `MediaVersionDedupHit = 'media.version.dedup_hit'`.
- Update the OCAP audit substrate's enum-related tests in `packages/audit/tests/` to include the new kinds (especially the contract test in `packages/audit/tests/Contract/EntityLifecycleAuditContractTest.php` or whichever asserts the closed enum membership).
- Update `docs/specs/ocap-audit-log.md` enum taxonomy section to include the new kinds. Cross-reference this mission.
- Coordinate with the OCAP audit reviewer per Risks in spec.md — additive amendment, no removed kinds.

### Cascade-delete on parent media deletion (T-G)
- Subscribe to `Media` delete events. On parent deletion: load all `MediaVersion` rows for that `media_uuid`; delete via the entity repository. Each per-version deletion fires its own `entity.delete` audit event (via the OCAP listener) PLUS this mission writes a `retention.purge` event (or a NEW `media.version.deleted` event if added in this WP — implementer's call; preference: reuse `entity.delete` for now, defer `media.version.deleted` to the cascade-delete behaviour landing).
- Reference: `packages/entity-storage/src/Driver/RevisionableStorageDriver.php` — that driver's cascade pattern (revisions deleted when entity is deleted) is the model.

### `MediaVersionParentResolver` for classification inheritance (T-H)
- `packages/media/src/Version/Classification/MediaVersionParentResolver.php implements ClassificationParentResolverInterface` (from `classification-retention-engine-01KSEFTH`). `supports()` matches `entity_type_id === 'media_version'`; `parentOf()` returns the parent `Media` entity.
- Register the resolver in `MediaServiceProvider`.
- IF classification mission has NOT merged at WP02 implementation time: ship the class with the interface name as a string-only stub OR comment out the `implements` clause with a `// TODO(post-classification-mission): re-enable implements clause` marker. Document the gap in the WP report.

### Tests for WP02 (T-I)
- `packages/media/tests/Unit/Version/MediaVersionStorageDriverTest.php` — fakes `UploadHandler` + `AuditWriterInterface`; asserts one `MediaVersion` row created per upload, vid increments correctly, audit event written, metadata-only save creates NO new version.
- `packages/media/tests/Unit/Version/CascadeDeleteTest.php` — seeds 3 versions; deletes parent media; asserts 3 version rows deleted.
- `packages/audit/tests/Unit/Enum/AuditEventKindAmendmentTest.php` — asserts the three new kinds exist (this test is owned by `packages/audit` but added by this mission per the coordinated-amendment principle).

## WP03 — API surface (read-model + adapter + controller + router)

### Read-model + adapter (T-J)
- `packages/api/src/Media/MediaVersionReadModelInterface.php` — `findForMedia(string $mediaUuid, AccountInterface $account): iterable<MediaVersionResource>`, `findByVid(string $mediaUuid, int $vid, AccountInterface $account): ?MediaVersionResource`. `@api`.
- `packages/api/src/Media/MediaVersionResource.php` — readonly DTO. Properties: `vid`, `mediaUuid`, `blobUri`, `mime`, `sizeBytes`, `sha256`, `createdAt`, `createdBy`. `@api`.
- `packages/api/src/Media/ApiMediaVersionAdapter.php implements MediaVersionReadModelInterface`. Constructor `(Waaseyaa\Media\Version\MediaVersionRepository $repo, AccessChecker $checker)`. Delegates to repo + maps `MediaVersion` → `MediaVersionResource`.

### Controller + router (T-K)
- `packages/api/src/Controller/MediaVersionController.php`. `__construct(private readonly ?MediaVersionReadModelInterface $readModel = null)`.
  - `index(string $mediaUuid, Request $request): array` — `{data: [...resources], meta: {total}}`. Null read-model → empty shape.
  - `show(string $mediaUuid, int $vid, Request $request): array` — `{data: resource}`. Null read-model or unknown vid → 404 with JSON:API error. Forbidden version (access policy) → 403.
- `packages/api/src/Http/Router/MediaVersionApiRouter.php` — mirror `WorkflowGuardsApiRouter` / `AiObservabilityApiRouter`. `supports()` matches `'MediaVersionController::'`; dispatch `index` + `show`.
- `packages/api/src/ApiServiceProvider.php`:
  - `use Waaseyaa\Api\Media\MediaVersionReadModelInterface;` (api-local; fine).
  - In `register()`: bind `MediaVersionReadModelInterface` → adapter factory (resolves `MediaVersionRepository` + `AccessChecker`).
  - In `httpDomainRouters()`: `$rm = $this->resolveOptional(MediaVersionReadModelInterface::class); if ($rm instanceof MediaVersionReadModelInterface) { $routers[] = new MediaVersionApiRouter(new MediaVersionController($rm)); }`.
- `packages/api/composer.json` — add `"waaseyaa/media"` to **require-dev** + path-repo entry.

### Routes (T-L)
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`:
  - `api.media.versions.index` → `GET /api/media/{uuid}/versions`, `_authenticated`, string FQCN `'Waaseyaa\\Api\\Controller\\MediaVersionController::index'`.
  - `api.media.versions.show` → `GET /api/media/{uuid}/versions/{vid}`, `_authenticated`, string FQCN `'Waaseyaa\\Api\\Controller\\MediaVersionController::show'`.

### Tests for WP03 (T-M)
- `packages/api/tests/Unit/Controller/MediaVersionControllerTest.php` — fake read-model returns canonical resources; assert mapped payload; null read-model → empty/404; forbidden version → 403.
- `packages/api/tests/Unit/Http/Router/MediaVersionApiRouterTest.php` — `supports()` matrix; dispatch matrix.
- `tests/Integration/PhaseMediaVersioning/MediaVersioningIntegrationTest.php` (`#[CoversNothing]`) — FR-013: seed media, 4 uploads (3 distinct + 1 dedup), assert 4 versions, dedup correctness, 4 audit events, endpoint payload.
- `tests/Integration/PhaseMediaVersioning/ForbiddenVersionIntegrationTest.php` (`#[CoversNothing]`) — FR-014: per-version filter; admin sees all, non-admin sees filtered list.

## WP04 — Admin SPA browser + docs + CHANGELOG

### Composable + component + page integration (T-N)
- `packages/admin/app/composables/useMediaVersions.ts` — `{versions, loading, error, fetchVersions(mediaUuid)}`. Mirror `useQueueJobs.ts`.
- `packages/admin/app/components/media/MediaVersionBrowser.vue` — table component. Props: `mediaUuid: string`. Columns: vid, mime, size (formatted via util), sha256 (truncated to first 12 chars + tooltip with full), created_at, created_by, action ("View" link — disabled if binary endpoint not shipped).
- Integration: add the `<MediaVersionBrowser :media-uuid="...">` to the existing media-edit page. Verify the page exists at `packages/admin/app/pages/media/[id].vue` — if not, create the minimum shell (do NOT build a full media editor in this WP).
- i18n keys: `media_versions_title`, `media_version_col_vid`, `..._col_mime`, `..._col_size`, `..._col_hash`, `..._col_created`, `..._col_creator`, `..._empty`.

### Tests + docs (T-O)
- `packages/admin/tests/unit/composables/useMediaVersions.test.ts` — vitest.
- `packages/admin/e2e/media-versions.spec.ts` — Playwright smoke (deferred).
- `docs/specs/versioned-blob-media.md` — new spec covering: Overview, Why, Architecture (entity + CAS decorator + storage-driver hook + cross-layer adapter), Schema, CAS URI scheme, Save semantics (when versions are vs aren't created), Access composition, API surface, Admin browser, Cross-mission integrations (audit, classification), Risks. ~250–400 lines.
- `docs/specs/entity-storage-two-axis.md` — append a brief cross-reference note: "Blob-versioning extends this substrate's revisionable-axis pattern at the blob layer; see `docs/specs/versioned-blob-media.md`."
- `CLAUDE.md` orchestration table — add row `packages/media/src/Version/*` → `docs/specs/versioned-blob-media.md`.
- `CHANGELOG.md` `[Unreleased]` → **Added**.

## Verification gate (each WP, in lane worktree)

1. `composer install`; (WP04) `cd packages/admin && npm install`.
2. WP01-WP03: `vendor/bin/phpunit packages/media/tests/Unit/Version/ packages/api/tests/Unit/Controller/MediaVersionControllerTest.php packages/audit/tests/Unit/Enum/AuditEventKindAmendmentTest.php tests/Integration/PhaseMediaVersioning/`. WP04: vitest + typecheck + lint.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. DIR-005 sanity: `git diff main -- packages/entity-storage/` must be EMPTY (no two-axis substrate changes — C-003).
6. CAS-dedup smoke (FR-002 integration in WP03): seed 1MB random bytes; write; write again; assert second write returns `dedupHit=true` and the same URI.

## Reviewer focus

- (a) **DIR-005 axis preservation:** zero diff in `packages/entity-storage/` and `docs/specs/entity-storage-two-axis.md` (except the cross-reference append in WP04). `MediaVersion` is parallel substrate, not a two-axis modification.
- (b) **CAS dedup correctness** (FR-002, FR-013): identical bytes produce identical URIs; dedup_hit flag accurate.
- (c) **Per-version access** (FR-014): forbidden versions are NOT enumerable in the list endpoint — verify with the FR-014 integration test.
- (d) **Audit-enum amendment** (FR-005, C-004): three new kinds added; existing kinds unchanged; audit substrate's contract / unit test updated.
- (e) **Cross-layer cleanliness** (NFR-004): `packages/media` does not import api symbols; the adapter is api-side.
- (f) **MIME / size re-derivation** (Risks): the storage driver re-computes mime + size_bytes from the actual stored stream, not from request headers.
- (g) **Cascade-delete on parent media** (T-G): deleting a Media entity sweeps its MediaVersion rows.
- (h) **Classification inheritance** (FR-011): if classification mission has merged, `MediaVersionParentResolver` is registered and integration test confirms label inheritance; if not, the gap is documented + TODO marker present.
