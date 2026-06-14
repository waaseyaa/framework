---
work_package_id: WP01
title: "MediaVersion entity + schema, ContentAddressedFileRepositoryDecorator, MediaVersionRepository"
dependencies: []
requirement_refs:
  - FR-001
  - FR-002
  - FR-006
  - FR-007
  - NFR-001
  - NFR-003
  - NFR-005
  - C-002
  - C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: "Planning artifacts generated on main. WP branches from main; completed work merges back into main."
subtasks:
  - T-A
  - T-B
  - T-C
  - T-D
authoritative_surface: "packages/media/src/Version"
execution_mode: code_change
owned_files:
  - packages/media/src/Version/MediaVersion.php
  - packages/media/src/Version/MediaVersionType.php
  - packages/media/src/Version/MediaVersionRepository.php
  - packages/media/src/Version/ContentAddressedFileRepositoryDecorator.php
  - packages/media/src/File/FileWriteResult.php
  - packages/media/src/MediaServiceProvider.php
  - packages/media/migrations/2026_05_25_000005_create_media_version_table.php
  - packages/media/tests/Unit/Version/MediaVersionTest.php
  - packages/media/tests/Unit/Version/ContentAddressedFileRepositoryDecoratorTest.php
  - packages/media/tests/Unit/Version/MediaVersionRepositoryTest.php
tags: ["substrate", "media", "versioning", "content-addressing", "layer-2"]
history: []
---

# WP01 — `MediaVersion` entity + content-addressed file decorator + repository

**Mission:** `versioned-blob-media-abstraction-01KSEFTJ`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## Pattern references — READ FIRST

- `docs/specs/entity-storage-two-axis.md` §"Schema shape" — the `(entity_id, vid)` pattern. `MediaVersion` mirrors `(media_uuid, vid)`.
- `packages/media/src/{Media,File,FileRepositoryInterface,LocalFileRepository,MediaServiceProvider,UploadHandler}.php`.
- `packages/entity-storage/src/Schema/SqlSchemaHandler.php` (schema build pattern).
- `packages/entity-storage/src/Driver/RevisionableStorageDriver.php` — per-`(parent, vid)` write semantics; mirror lessons (NOT the implementation — `MediaVersion` is its own table).
- `.claude/rules/entity-storage-invariant.md` — canonical pipeline.
- `CLAUDE.md` §"Adding an entity type" + §"Architecture Gotchas" (entity-system constructor shape, `enforceIsNew()`).

## Subtasks

### T-A — `MediaVersion` entity + schema + migration

1. `packages/media/src/Version/MediaVersion.php extends ContentEntityBase`. Constructor `__construct(array $values = [])` hardcodes `entity_type_id = 'media_version'`, `entity_keys = ['id' => 'id', 'uuid' => 'uuid']`. Class-level `@api`.
2. `packages/media/src/Version/MediaVersionType.php` — `EntityType` value (mirror how `MediaType` is constructed elsewhere; verify in `packages/media/src/Media.php`).
3. Schema columns per spec.md §In-scope.
4. Migration `packages/media/migrations/2026_05_25_000005_create_media_version_table.php` — `media_version` + `media_version_data`. Indices: `(media_uuid, vid DESC)`, `(sha256)`, `(created_at)`.
5. Register the entity type in `MediaServiceProvider::register()` via `EntityTypeManager::addEntityType()`.

### T-B — `ContentAddressedFileRepositoryDecorator` + `FileWriteResult`

1. `packages/media/src/File/FileWriteResult.php` — readonly DTO: `string $uri`, `string $sha256`, `bool $dedupHit`, `int $sizeBytes`. `@api`. (If a similar DTO exists in the package, reuse instead of duplicating.)
2. Verify `FileRepositoryInterface` exposes `write()` returning some structure and `exists()`. If not, extend it (additive — verify backward compat of existing impls `LocalFileRepository` + `InMemoryFileRepository`).
3. `packages/media/src/Version/ContentAddressedFileRepositoryDecorator.php implements FileRepositoryInterface`. Constructor `(FileRepositoryInterface $inner, ?LoggerInterface $logger = null)`. `@api`.
4. `write()` body per plan.md §T-B (1-5 steps).
5. `read()`, `delete()`, `exists()` delegate to `$this->inner`.

### T-C — `MediaVersionRepository`

1. `packages/media/src/Version/MediaVersionRepository.php`. `@api`. Constructor: `(EntityRepositoryInterface $entityRepo, DatabaseInterface $db, ?AccessChecker $checker = null)`.
2. `findVersionsForMedia(string $mediaUuid, ?AccountInterface $account = null): iterable<MediaVersion>`:
   - Uses `DatabaseInterface::select('media_version')` query builder; `condition('media_uuid', $mediaUuid)`; `orderBy('vid', 'DESC')`.
   - If `$account !== null && $this->checker !== null`: yield only versions where `$this->checker->setAccount($account)` allows `view`.
   - **NEVER `getQuery()`.** Keep `bin/check-getquery-bindings` baseline at zero new entries.
3. `tip()`, `findByVid()` per plan.md §T-C.

### T-D — Unit tests

Per plan.md §T-D. Use `DBALDatabase::createSqlite()` for repository tests.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/media/tests/Unit/Version/`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. DIR-005: `git diff main -- packages/entity-storage/` MUST be empty (no two-axis substrate changes — C-003).
6. CAS dedup smoke: in a scratch test file or `--filter`, write 1MB of random bytes; write again; assert second call returns `dedupHit=true` and identical URI.

## Commit + handoff

- `feat(media): MediaVersion entity + MediaVersionType + schema + migration`
- `feat(media): FileWriteResult DTO + ContentAddressedFileRepositoryDecorator`
- `feat(media): MediaVersionRepository with AccessChecker-filtered queries`
- `test(media): unit tests for entity, CAS decorator, repository`

```
spec-kitty agent tasks mark-status T-A T-B T-C T-D --status done --mission versioned-blob-media-abstraction-01KSEFTJ
spec-kitty agent tasks move-task WP01 --to for_review --mission versioned-blob-media-abstraction-01KSEFTJ --note "Entity + CAS decorator + repository in place; DIR-005 axis preserved (entity-storage untouched)"
```

## Report back with

1. Commit SHAs.
2. Output of `git diff main -- packages/entity-storage/` (must be empty).
3. CAS dedup smoke output (paste the two write calls + their URIs — must be identical).
4. `bin/check-package-layers` + `bin/check-getquery-bindings` green.
5. Whether `FileRepositoryInterface` needed extension (paste the diff if so).

## Activity Log
- 2026-05-25T06:01:39Z – unknown – code already on main
- 2026-05-25T06:01:42Z – unknown – code already on main
- 2026-05-25T06:01:45Z – unknown – Opus review: WP01 (MediaVersion entity + CAS) and WP02 (storage hook + audit kind extension) shipped on main earlier by partial subagent; phpstan-clean; reconciled to canonical lifecycle state
- 2026-05-26T18:55:52Z – unknown – Done override: Sprint merge to main
