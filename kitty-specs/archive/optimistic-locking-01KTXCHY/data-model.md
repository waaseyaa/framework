# Data Model: Optimistic Locking

**Date**: 2026-06-12 | **Plan**: [plan.md](plan.md)

No new entity types, no schema changes (C-001 — `revision_id` is the version column revision tracking already provides). The "data model" of this mission is the SaveContext extension, the conflict exception, the rejection matrix, and the two surface error payloads.

## SaveContext extension

| Member | Type | Default | Semantics |
|---|---|---|---|
| `expectedRevisionId` (private ctor param) | `?int` | `null` | The revision the caller read; `null` ⇔ no expectation stated |
| `withExpectedRevisionId(?int)` (builder) | `self` | — | Returns a NEW instance; `null` argument = explicit no-expectation pass-through (so `->withExpectedRevisionId($maybeNull)` needs no caller branch); `< 1` → `InvalidArgumentException` |
| `expectedRevisionId()` (accessor) | `?int` | — | Read by `EntityRepository::doSave()`; `null` means every conflict branch is skipped |

- Mirrors the `withActorUid()` builder shape (trailing ctor param, accessor, re-threaded through every existing builder — and the new builder re-threads `withoutNewRevision`/`langcode`/`isImport`/`translations`/actor pair).
- No paired boolean pin (unlike `actorOverridden`): a null expectation has no third meaning — there is no "I expect no revision" state on a persisted revisionable row (revision 1 exists from the first save).

## RevisionConflictException (FR-002, NFR-003)

`Waaseyaa\EntityStorage\Exception\RevisionConflictException` — `final class extends \RuntimeException` (PartialSaveException house shape):

| Promoted property | Type | Semantics |
|---|---|---|
| `entityTypeId` | `string` | The entity type of the refused save |
| `entityId` | `string` | The entity id (real id, not the request locator) |
| `expectedRevisionId` | `int` | What the caller stated |
| `currentRevisionId` | `?int` | The head at refusal time; `null` = the row no longer exists (deleted concurrently) |
| `errorCode` | `string` | Always `'REVISION_CONFLICT'` (canonical name `$errorCode`, not `$code` — typed redeclaration of `\Exception::$code` is impossible; see PartialSaveException docblock) |

Constructor-composed message naming all four. Deterministic: no timestamps, no timing-dependent content beyond the revision ids (NFR-003).

## Conflict decision per save (storage layer)

```
expectation stated (expectedRevisionId() !== null)?
  no  → byte-identical legacy behavior (FR-003; every new branch skipped)
  yes →
    isNew                                  → LogicException (caller error)
    type not revisionable                  → LogicException (D2 — no change marker exists)
    type revisionable AND translatable     → LogicException (two-axis carve-out)
    no DatabaseInterface wired             → LogicException (no transaction, no guard)
    save not revision-creating             → LogicException (head pointer would not move)
    original entity missing (row vanished) → RevisionConflictException (current = null)
    head ≠ expected (pre-check)            → RevisionConflictException (before any write/event)
    head = expected → write revision row; guarded pointer-claim UPDATE
        affected = 1 → full base write (same txn) → commit (save succeeds)
        affected = 0 → rollback → re-read head → RevisionConflictException
```

The guarded claim:

```sql
UPDATE <base> SET <revisionKey> = :newRevisionId
WHERE <idKey> = :id AND <revisionKey> = :expectedRevisionId
-- affected rows unambiguous on every backend: newRevisionId ≠ expected always
```

## Rejection matrix (D6, FR-007)

| Path + stated expectation | Result | Surface translation |
|---|---|---|
| New (unsaved) entity | `\LogicException` | tool: `revision_expectation_unsupported`; API: n/a (PATCH targets existing) |
| Non-revisionable type | `\LogicException` | tool: `revision_expectation_unsupported`; API: 422 (screened) |
| Two-axis (revisionable + translatable) type | `\LogicException` | tool: `revision_expectation_unsupported`; API: 422 (screened) |
| Non-revision-creating save (`withoutNewRevision`, `setNewRevision(false)`, `revisionDefault: false`) | `\LogicException` | direct repository callers only |
| No `DatabaseInterface` wired | `\LogicException` | direct repository callers / driver-only test setups |
| `rollback` / `setCurrentRevision` / `setPublishedRevision` / `saveTranslation*` / `saveMany` | no SaveContext parameter — an expectation cannot reach these paths | documented, not code |

## Tool surfaces (D3/D5)

`entity.update` input schema gains:

```json
"expected_revision_id": { "type": "integer", "minimum": 1,
  "description": "Optional optimistic-locking expectation: the revision_id the caller read. The save is refused with a revision_conflict error if the entity's current revision differs. Revisionable entity types only." }
```

Conflict error result (Mission 1 two-block shape — text + json):

```json
{ "error": "revision_conflict",
  "entity_type": "<type>", "id": "<id>",
  "expected": 5, "current": 6 }
```

Unsupported-expectation error result (same two-block shape):

```json
{ "error": "revision_expectation_unsupported", "entity_type": "<type>", "reason": "<message>" }
```

- Success payload gains `"revision_id": <new head>` (post-save readback).
- Dry-run with `expected_revision_id`: loads the entity, compares heads, reports a mismatch with the byte-identical `revision_conflict` payload; match → existing `would_update` success.
- `entity.read` payload gains top-level `"revision_id": <int>` (omitted on non-revisionable types); `entity.list` items gain the same member; `entity.list_revisions` unchanged (already exposes per-revision ids).

## API surfaces (D4/D5)

PATCH request (the expectation seam — resource-object meta; headers are unreachable from the controller):

```json
{ "data": { "type": "<type>", "attributes": { "...": "..." },
            "meta": { "expected_revision_id": 5 } } }
```

| Request state | Response |
|---|---|
| `expected_revision_id` absent | byte-identical legacy update path (`getStorage()->save()`) — FR-003/SC-003 |
| present, not a positive integer | 400 `Bad Request` |
| present, type not single-axis revisionable | 422 `Unprocessable Entity` (screened; storage `\LogicException` is the backstop) |
| present, head moved | **409** — see below |
| present, head matches | update applies through `getRepository()->save(…, context:)` (revision-aware pipeline — a revision is cut); 200 with the updated resource (attributes include the new `revision_id`) |
| present, repository validation fails | 422 (`EntityValidationException` mapped) |

409 body (JsonApiError with the new additive `meta` member):

```json
{ "errors": [ { "status": "409", "title": "Conflict",
    "code": "REVISION_CONFLICT",
    "detail": "Entity of type '<type>' with ID '<id>' was modified: expected revision 5, current revision is 6.",
    "meta": { "expected_revision_id": 5, "current_revision_id": 6 } } ] }
```

- `JsonApiError` gains `array $meta = []` (trailing ctor param; emitted by `toArray()` only when non-empty — all existing error bytes unchanged); `conflict()` gains optional `code`/`meta` passthrough. The pre-existing `data.id`-vs-uuid 409 keeps its codeless shape; `code: REVISION_CONFLICT` disambiguates machine-readably.
- `GET /api/{type}/{id}` already emits `revision_id` as an attribute on revisionable types — now pinned by test and documented load-bearing (FR-008).
