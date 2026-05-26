---
work_package_id: WP02
title: "MediaVersionStorageDriver save hook, audit-enum amendment (3 new kinds), cascade-delete, classification parent-resolver"
dependencies:
  - WP01
requirement_refs:
  - FR-003
  - FR-004
  - FR-005
  - FR-011
  - NFR-002
  - NFR-003
  - C-001
  - C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: "Branches from WP01 merge commit. Completed work merges back into main."
subtasks:
  - T-E
  - T-F
  - T-G
  - T-H
  - T-I
authoritative_surface: "packages/media/src/Version/MediaVersionStorageDriver.php"
execution_mode: code_change
owned_files:
  - packages/media/src/Version/MediaVersionStorageDriver.php
  - packages/media/src/Version/Classification/MediaVersionParentResolver.php
  - packages/media/src/Version/MediaCascadeDeleteSubscriber.php
  - packages/audit/src/Enum/AuditEventKind.php
  - packages/audit/tests/Unit/Enum/AuditEventKindAmendmentTest.php
  - packages/media/tests/Unit/Version/MediaVersionStorageDriverTest.php
  - packages/media/tests/Unit/Version/CascadeDeleteTest.php
tags: ["substrate", "media", "versioning", "audit-enum-amendment", "classification"]
history: []
---

# WP02 — Storage-driver hook + audit-enum amendment + cascade-delete + classification parent-resolver

**Mission:** `versioned-blob-media-abstraction-01KSEFTJ`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01 (must be approved). Coordinates with `ocap-audit-log-substrate-01KSEFTF` (amends its `AuditEventKind` enum, additively).

## Pattern references — READ FIRST

- `packages/media/src/UploadHandler.php` — how uploaded bytes flow into the save path; determine the integration point for the storage driver hook (subscriber on save event? wrapper around `MediaRepository::save()`?).
- `packages/audit/src/Enum/AuditEventKind.php` — the closed enum to amend additively.
- `packages/audit/tests/Contract/AuditWriterContractTest.php` (or equivalent) — verify the new kinds are exercised by the contract test after amendment.
- `packages/entity-storage/src/Driver/RevisionableStorageDriver.php` — cascade-delete pattern lessons.
- `packages/field/src/Classification/ClassificationParentResolverInterface.php` (from `classification-retention-engine-01KSEFTH`) — interface to implement.
- CLAUDE.md §"Adding an entity type" + §Logging "best-effort side effects".

## Subtasks

### T-E — `MediaVersionStorageDriver`

1. `packages/media/src/Version/MediaVersionStorageDriver.php`. Subscribes to `Media` save events (verify FQCN in `packages/media/src/`). Constructor `(MediaVersionRepository $versionRepo, ContentAddressedFileRepositoryDecorator $cas, DatabaseInterface $db, AuditWriterInterface $auditWriter, ?LoggerInterface $logger = null)`.
2. On `Media::save()` AFTER persistence:
   - Detect upload presence via `UploadHandler` state on the entity (or a transient property set by the upload controller). If no new upload → return (C-001).
   - Compute `$nextVid`. Mirror two-axis storage's `MAX(vid) + 1` per-`media_uuid` pattern. Use `DatabaseInterface::select()` for the MAX query.
   - Call `$cas->write($bytes, $mime)` → `FileWriteResult`.
   - Re-derive `$sizeBytes` + `$mime` from actual stored stream (Risks mitigation): `$sizeBytes = strlen($bytes)`; `$mime = mime_content_type($tmpFile) ?? $requestMime`.
   - Create `MediaVersion` via `$versionRepo` (or directly via the entity repo); REMEMBER `->enforceIsNew()` per CLAUDE.md entity-system gotcha.
   - Dispatch `AuditEventDescriptor(eventKind: AuditEventKind::MediaVersionCreated, entityType: 'media', entityUuid: $media->uuid(), accountUid: $account->id(), attributes: ['vid' => $nextVid, 'sha256' => $result->sha256, 'mime' => $mime, 'size_bytes' => $sizeBytes, 'dedup_hit' => $result->dedupHit])`.
3. WHOLE body try-catch wrapped per CLAUDE.md §Logging best-effort pattern.

### T-F — Audit-enum amendment (coordinated with audit substrate mission)

1. Edit `packages/audit/src/Enum/AuditEventKind.php`: append three cases:
   ```php
   case MediaVersionCreated = 'media.version.created';
   case MediaVersionRead = 'media.version.read';
   case MediaVersionDedupHit = 'media.version.dedup_hit';
   ```
2. `packages/audit/tests/Unit/Enum/AuditEventKindAmendmentTest.php` — assert `AuditEventKind::tryFrom('media.version.created') === AuditEventKind::MediaVersionCreated` (and the other two).
3. If the audit substrate's contract test in `packages/audit/tests/Contract/` enumerates all enum cases via `count()`, update the asserted count (e.g. 14 → 17).
4. Update `docs/specs/ocap-audit-log.md` enum-taxonomy section to include the three new kinds with cross-reference: "Added by `versioned-blob-media-abstraction-01KSEFTJ` per the §Out-of-band downstream-amendment principle."
5. Coordinate: confirm the OCAP audit mission's reviewer signed off on additive amendments (per that mission's spec.md §"Out-of-band"). If not yet, flag the dependency and pause WP02 until coordinated.

### T-G — Cascade-delete on parent media

1. `packages/media/src/Version/MediaCascadeDeleteSubscriber.php` subscribes to `Media` delete events.
2. On parent deletion: load all `MediaVersion` for `media_uuid`; delete each via the entity repository (which fires `entity.delete` audit events for each version — natural integration with the OCAP listener from the audit substrate).
3. Best-effort try-catch wrap.

### T-H — `MediaVersionParentResolver` (classification inheritance)

1. `packages/media/src/Version/Classification/MediaVersionParentResolver.php`.
2. IF `classification-retention-engine-01KSEFTH` is merged: `implements Waaseyaa\Field\Classification\ClassificationParentResolverInterface`; `supports()` matches `entity_type_id === 'media_version'`; `parentOf()` returns the parent `Media` entity. Register in `MediaServiceProvider`. `@api`.
3. IF NOT merged: ship the class with the `implements` clause commented out and a `// TODO(post-classification-mission): re-enable implements clause and register in MediaServiceProvider`. Document the gap in the WP report. The class is still `@api` so the dead-code gate accepts it.

### T-I — Unit tests

Per plan.md §T-I.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/media/tests/Unit/Version/ packages/audit/tests/Unit/Enum/AuditEventKindAmendmentTest.php`.
3. Re-run the audit substrate's full test suite: `vendor/bin/phpunit packages/audit/tests/` — must still pass (additive amendment).
4. `composer cs-check && composer phpstan`.
5. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
6. Verify metadata-only save creates NO new version (C-001) — write a unit test where `Media::save()` is called WITHOUT an upload; assert `media_version` row count unchanged.
7. Verify cascade-delete: seed media + 3 versions; delete the media; assert all 3 version rows deleted.

## Commit + handoff

- `feat(media): MediaVersionStorageDriver — creates version per upload + writes audit event`
- `feat(audit): extend AuditEventKind with media.version.{created,read,dedup_hit} (coordinated amendment, additive)`
- `feat(media): MediaCascadeDeleteSubscriber — sweeps versions on parent delete`
- `feat(media): MediaVersionParentResolver for classification inheritance`
- `test(media): storage driver + cascade-delete + metadata-only save semantics`
- `docs(specs): ocap-audit-log.md enum taxonomy updated with media.version.* kinds`

```
spec-kitty agent tasks mark-status T-E T-F T-G T-H T-I --status done --mission versioned-blob-media-abstraction-01KSEFTJ
spec-kitty agent tasks move-task WP02 --to for_review --mission versioned-blob-media-abstraction-01KSEFTJ --note "Storage hook + audit amendment + cascade-delete + classification parent-resolver in place"
```

## Report back with

1. Commit SHAs.
2. Output of `vendor/bin/phpunit packages/audit/tests/` (all green — audit substrate untouched by amendment).
3. The three new enum case lines (paste).
4. The metadata-only test output (C-001 proof — saving Media without upload does NOT create new version).
5. Whether classification mission was merged at WP02 start time (so reviewer knows whether `MediaVersionParentResolver` is fully wired or has the TODO marker).
6. `bin/check-package-layers` + `bin/check-dead-code` + `bin/check-getquery-bindings` green.

## Activity Log
- 2026-05-25T06:01:48Z – unknown – code already on main
- 2026-05-25T06:01:51Z – unknown – code already on main
- 2026-05-25T06:01:54Z – unknown – Opus review: WP01 (MediaVersion entity + CAS) and WP02 (storage hook + audit kind extension) shipped on main earlier by partial subagent; phpstan-clean; reconciled to canonical lifecycle state
- 2026-05-26T18:55:55Z – unknown – Done override: Sprint merge to main
