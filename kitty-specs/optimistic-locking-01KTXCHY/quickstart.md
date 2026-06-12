# Quickstart: Optimistic Locking

**Mission**: optimistic-locking-01KTXCHY

Reviewer's hands-on script — each step maps to an acceptance scenario in [spec.md](spec.md). Step 2 is the SC-002 deliverable: the approve-time staleness recipe a consumer can copy.

## 1. The primitive (scenarios 1–3)

```bash
./vendor/bin/phpunit packages/entity-storage/tests/Unit/SaveContext/ --no-progress
./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryOptimisticLockingTest.php --no-progress
./vendor/bin/phpunit tests/Integration/Locking/ --no-progress
```

By hand, in any kernel-booted context (e.g. `php -r` against a scratch sqlite, or a test):

```php
$repo = $entityTypeManager->getRepository('document');   // a revisionable type
$entity = $repo->find('42');
$readRevision = $entity->getRevisionId();                // e.g. 5 — the head you read

$entity->set('title', 'My edit');
$repo->save($entity, context: SaveContext::default()->withExpectedRevisionId($readRevision));
// head still 5  → save succeeds, head moves to 6
// head moved    → RevisionConflictException BEFORE any write:
//                 ->entityTypeId, ->entityId, ->expectedRevisionId (5), ->currentRevisionId (6)
```

A save with no expectation (or `withExpectedRevisionId(null)`) is byte-identical to today — last-write-wins, disjoint-field merge preserved (scenario 3, pinned by `NoExpectationInvarianceTest`).

## 2. SC-002 — the approve-time staleness check (the consumer recipe)

The dual-writer pattern this mission exists for: an agent drafts a change, a human approves it later, and the entity may have moved in between. Using only framework primitives:

1. **At draft time, record what was read.** Read the entity and keep its revision id alongside the draft:
   - tool: `entity.read {entity_type, id}` → payload `revision_id` (FR-008)
   - API: `GET /api/{type}/{id}` → `data.attributes.revision_id`
   - PHP: `$entity->getRevisionId()`
2. **At approve time, state the expectation — do NOT re-read-and-hope.** Apply the draft with the recorded revision:
   - tool: `entity.update {entity_type, id, values, expected_revision_id: R}`
   - API: `PATCH /api/{type}/{id}` with `data.meta.expected_revision_id: R`
   - PHP: `save($entity, context: SaveContext::default()->withExpectedRevisionId(R))`
3. **Handle the conflict as the feature, not an error.** If the head moved since the draft:
   - tool returns `{"error": "revision_conflict", "expected": R, "current": M}` (isError, machine-correctable)
   - API returns `409` with `code: REVISION_CONFLICT` and `meta: {expected_revision_id, current_revision_id}`
   - PHP throws `RevisionConflictException`
   The payload carries the current head — re-read (or `entity.list_revisions` to see what changed in between), re-diff the draft against the new head, and re-approve with the new revision id. The competing writer's work is never silently reverted.

```bash
./vendor/bin/phpunit tests/Integration/AgentRun/DualWriterConflictTest.php --no-progress   # SC-001: this exact loop, end to end
```

## 3. Agent tool surface (scenario 4)

```bash
./vendor/bin/phpunit packages/ai-tools/tests/Unit/Entity/EntityUpdateToolConflictTest.php --no-progress
./vendor/bin/phpunit packages/ai-tools/tests/Unit/Entity/EntityToolRevisionExposureTest.php --no-progress
```

By hand: `entity.update` with a stale `expected_revision_id` → structured `revision_conflict` (text block + json block); with the current one → update applies and the success payload carries the new `revision_id`. **Dry-run** with a stale expectation reports the byte-identical conflict payload (edge case). A non-revisionable type → `revision_expectation_unsupported` (do not retry). `values.revision_id` is still refused by the key guard — the expectation is an argument, never a writable field.

## 4. API surface (scenario 5)

```bash
./vendor/bin/phpunit packages/api/tests/Unit/JsonApiControllerConflictTest.php packages/api/tests/Unit/JsonApiErrorTest.php --no-progress
```

By hand against a dev server: `PATCH /api/{type}/{id}` with `{"data": {"type": "<type>", "attributes": {...}, "meta": {"expected_revision_id": R}}}` — stale → `409` `REVISION_CONFLICT` with both ids in `meta`; current → `200`, the response attributes show the new `revision_id`. Without `data.meta` the PATCH is byte-identical to before (scenario 3). Note: an expectation-stated PATCH cuts a revision (repository pipeline) — that is the point.

## 5. Rejection semantics (scenario 6 + edge cases)

Non-revisionable type + expectation → clean rejection everywhere (storage `LogicException`, tool `revision_expectation_unsupported`, API 422) — never silently ignored, never a fake check (no change marker exists on non-revisionable types; research D2). Same family: new entities, two-axis types, non-revision-creating saves. To make a type conflict-checkable: register it `revisionable: true` and run `revisions:enable`.

## 6. Gates

```bash
composer verify           # suite + phpstan + composer policy + dead-code + getQuery gate
bin/check-package-layers  # the two new edges (ai-tools→entity-storage, api→entity-storage) are downward
composer check-composer-policy  # CP-NEW: new edges pinned ^<latest tag>
```

CHANGELOG check: entries under `[Unreleased]` directly after the heading line (never a pre-stamped alpha.207 heading), leading with the opt-in feature and the expectation-stated-PATCH pipeline note. Spec docs updated in the same PR: `revision-system-unified.md`, `entity-system.md` (incl. the sanctioned Mission 3 `discoverable` cross-ref), `api-layer.md`, `ai-integration.md`.
