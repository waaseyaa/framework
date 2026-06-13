---
work_package_id: WP02
title: Tool + API Conflict Surfaces
dependencies:
- WP01
requirement_refs:
- C-001
- C-004
- FR-005
- FR-006
- FR-008
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
created_at: '2026-06-12T00:00:00+00:00'
subtasks:
- T007
- T008
- T009
- T010
- T011
- T012
agent: "claude:fable-5:reviewer:reviewer"
shell_pid: "5792"
history:
- date: '2026-06-12T00:00:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/ai-tools/
execution_mode: code_change
owned_files:
- packages/ai-tools/composer.json
- packages/ai-tools/src/Entity/EntityUpdateTool.php
- packages/ai-tools/src/Entity/EntityReadTool.php
- packages/ai-tools/src/Entity/EntityListTool.php
- packages/ai-tools/tests/Unit/Entity/EntityUpdateToolConflictTest.php
- packages/ai-tools/tests/Unit/Entity/EntityToolRevisionExposureTest.php
- packages/api/composer.json
- packages/api/src/JsonApiController.php
- packages/api/src/JsonApiError.php
- packages/api/tests/Unit/JsonApiControllerConflictTest.php
- packages/api/tests/Unit/JsonApiErrorTest.php
- tests/Integration/AgentRun/DualWriterConflictTest.php
tags: []
---

# WP02 — Tool + API Conflict Surfaces

**Mission**: optimistic-locking-01KTXCHY | **Tracks**: #1647
**Requirements**: FR-005, FR-006, FR-008, NFR-003 | **Dependencies**: WP01
**Command**: `spec-kitty agent action implement WP02 --agent <name>`

## Objective

After this WP: `entity.update` accepts `expected_revision_id` and turns a moved head into the Mission 1 two-block structured error `{error: 'revision_conflict', entity_type, id, expected, current}` — dry-run reports it identically; `PATCH /api/{type}/{id}` accepts `data.meta.expected_revision_id` and answers a moved head with **409** `code: REVISION_CONFLICT` + `meta: {expected_revision_id, current_revision_id}`; the current revision is readable on every surface a caller forms an expectation from (API attributes pinned; `entity.read`/`entity.list` gain a top-level/per-item `revision_id`); and the dual-writer story (SC-001) is proven end-to-end through the agent tool against a kernel-booted repository. Calls without an expectation are byte-identical to today on both surfaces.

## Context (read first)

- `research.md` D3 (tool argument + error shape + the concrete-repository guard), D4 (the body-meta seam — headers verifiably cannot reach the controller — and the repository-pipeline consequence), D5 (what already exposes revision_id and what doesn't).
- `contracts/conflict-surfaces.md` — authoritative; every numbered clause (1–19) must hold. `data-model.md` "Tool surfaces" + "API surfaces" carry the exact payloads and the request-state table.
- **WP01's API you consume**: `SaveContext::default()->withExpectedRevisionId(int)`, `RevisionConflictException` (`->entityTypeId`, `->entityId`, `->expectedRevisionId`, `->currentRevisionId` ?int, `->errorCode`), and the storage `\LogicException` rejection family (distinct messages).
- **Tool reality** (`packages/ai-tools/src/Entity/EntityUpdateTool.php`): execute = capability → args → type known → `find()` → `requireEntityAccess('update')` → `EntityKeyGuard::refusedKeys` → set loop → `$repository->save($entity)` (`:102`); `EntityValidationException` caught before `\Throwable` (`:103-107`) — your `RevisionConflictException` catch joins that ladder. The error-shape precedent is `EntityKeyGuard::refusalError()` (`packages/ai-tools/src/Entity/EntityKeyGuard.php:82-102`): `new AgentToolResult(isError: true, content: [text, ['type' => 'json', 'data' => [...]]], summary)`.
- **`EntityKeyGuard` already refuses `revision_id` inside `values`** (kind `revision`, `:33`) — do not touch the guard; the expectation is a *top-level argument*.
- **Repository typing**: `getRepository()` returns `EntityRepositoryInterface` whose `save()` has NO context param; only concrete `EntityRepository` does. Guard with `instanceof \Waaseyaa\EntityStorage\EntityRepository` (the `EntityDestination` precedent — `packages/migration/src/Plugin/Destination/EntityDestination.php:136,149`). Stated expectation + non-concrete repository → `revision_expectation_unsupported` structured error (NEVER a silent plain save — FR-007 at the surface).
- **Controller reality** (`packages/api/src/JsonApiController.php:315-403`): update validates `data.type`/`data.id`, checks update + field access, sets only supplied attributes, then `$storage->save($entity)` (`:395`) — `getStorage()` is the revision-less `SqlEntityStorage` (kernel factory, `AbstractKernel:222-226`). The expectation-stated path MUST switch to `$this->entityTypeManager->getRepository($entityTypeId)->save($entity, context: …)`; the no-expectation path keeps `:395` byte-identical. The body reaches you as `array $data` — `$data['data']['meta']['expected_revision_id']` is the seam (headers verifiably unavailable: `JsonApiRouter:61`, `WaaseyaaContext:23-29`).
- **`JsonApiError`** (`packages/api/src/JsonApiError.php`): members status/title/detail/code/source, NO meta; `conflict()` exists (status '409', no code) and is already used for the `data.id`-vs-uuid mismatch (`JsonApiController:349-355`) — that shape must stay byte-identical; your `code: 'REVISION_CONFLICT'` is the discriminator. Existing construction sites use ≤3 positional args — trailing additive params are safe.
- **FR-008 ground truth**: API reads already emit `revision_id` (serializer excludes only id/uuid — `ResourceSerializer:129-142`); your job is the PIN, not the plumbing. `EntityReadTool::serialize()` (`:102-124`) and `EntityListTool` items (`:80-84`) need the explicit member; `EntityListRevisionsTool` already exposes ids (`:90`) — untouched.
- **Layer/manifest reality**: neither `packages/ai-tools/composer.json` nor `packages/api/composer.json` requires `waaseyaa/entity-storage` today. L5→L1 and L4→L1 are downward edges; CP-NEW requires the constraint literal `^<latest v-tag>` at merge time.

## Requirement / contract map

| Deliverable | Requirement | Contract anchor |
|---|---|---|
| `expected_revision_id` argument + threading | FR-005 | conflict-surfaces.md §1–2, 5 |
| `revision_conflict` structured error | FR-005, NFR-003 | §3 |
| `revision_expectation_unsupported` | FR-007 (surface) | §4 |
| Dry-run parity | FR-005 edge case | §6 |
| `data.meta.expected_revision_id` + 409 | FR-006 | §9–13 |
| No-expectation invariance (both surfaces) | FR-003/SC-003 | §8, §14 |
| Revision exposure on reads | FR-008 | §16–17 |
| End-to-end dual-writer | SC-001 | §19 |

## Out of scope for this WP (do not touch)

- `packages/entity-storage/**` — WP01 owns the primitive; if the storage behavior looks wrong, stop and flag, don't patch.
- `packages/foundation/**` (`JsonApiRouter`, `WaaseyaaContext`, kernels) — the If-Match seam is explicitly NOT this mission (research D4); no header plumbing.
- `EntityKeyGuard`, `EntityCreateTool`, `EntityDeleteTool`, rollback/set-current tools, `EntityListRevisionsTool`.
- `ResourceSerializer` — `revision_id` already flows; pin it, don't "improve" it.
- Routes, `JsonApiRouteProvider`, MCP/agent executor wiring.
- CHANGELOG and `docs/specs/**` — WP03.

## Subtasks

### T007 — Manifest edges

**Files**: `packages/ai-tools/composer.json`, `packages/api/composer.json`

1. Add `"waaseyaa/entity-storage": "^<latest>"` to both `require` blocks — determine `<latest>` with `git describe --tags --abbrev=0 --match='v*.*.*'` at implementation time (alpha.206 may have been cut since planning); keep `sort-packages` ordering.
2. Gates: `composer check-composer-policy` (CP-NEW literal), `bin/check-package-layers` (both edges downward).

### T008 — EntityUpdateTool conflict surface

**Files**: `packages/ai-tools/src/Entity/EntityUpdateTool.php`, `packages/ai-tools/tests/Unit/Entity/EntityUpdateToolConflictTest.php` (NEW)

1. `inputSchema()`: add `'expected_revision_id' => ['type' => 'integer', 'minimum' => 1, 'description' => …]` (data-model.md wording). Not in `required`.
2. `execute()`: parse after the existing arg block — present but not a positive int → `AgentToolResult::error('entity.update: expected_revision_id must be a positive integer.')`. Keep the check order of contract §5 (no new checks ahead of the access check).
3. Save call:
   ```php
   if ($expected !== null) {
       if (!$repository instanceof EntityRepository) {
           return self::unsupportedExpectation('entity.update', $entityType, 'repository does not support revision expectations');
       }
       $result = $repository->save($entity, context: SaveContext::default()->withExpectedRevisionId($expected));
   } else {
       $result = $repository->save($entity);   // byte-identical legacy line
   }
   ```
   (Helper shape per data-model.md — a small private method or local construction; do NOT add to EntityKeyGuard.)
4. Catch ladder: `RevisionConflictException` (→ two-block `revision_conflict` payload — `entity_type`, `id`, `expected`, `current` from the exception's readonly members) and `\LogicException` **only when an expectation was stated** (→ `revision_expectation_unsupported` with `reason`), both BEFORE the existing `\Throwable` arm; `EntityValidationException` arm unchanged and first (validation-before-conflict order comes from storage).
5. Success payload: add `'revision_id' => method_exists($entity, 'getRevisionId') ? $entity->getRevisionId() : null` to the success `data` (post-save readback — `writeRevision` set it).
6. Tests (fixture style: real sqlite repository à la WP01's unit suite — the existing `InMemoryToolRepository` fixture is NOT SaveContext-capable, which is itself a test case for the unsupported path): schema member present; invalid arg; conflict payload member-by-member (NFR-003); unsupported on non-revisionable type; unsupported on non-concrete repository; success carries `revision_id`; **no-expectation calls byte-identical** (run the existing update-tool assertions against the new code unchanged).

**Validation**: `./vendor/bin/phpunit packages/ai-tools/tests/ --no-progress` (whole package — nothing may regress).

### T009 — Dry-run conflict parity

**Files**: `packages/ai-tools/src/Entity/EntityUpdateTool.php` (same file as T008 — sequence within the WP)

1. `dryRun()`: when `expected_revision_id` is present and the type is known, load the entity (`$repository->find()`) and compare the duck-typed head: mismatch (or missing entity) → return the SAME `revision_conflict` payload construction as execute (extract one private payload builder so the bytes cannot fork — the Mission 3 single-factory lesson); non-revisionable type → `revision_expectation_unsupported`. Match → existing `would_update` success unchanged.
2. Without the argument, `dryRun()` performs no load — byte-identical to today (contract §6).
3. Tests (in `EntityUpdateToolConflictTest`): dry-run conflict payload `===` execute conflict payload for the same world-state (encode both, compare strings); dry-run match still `would_update`; no-argument dry-run unchanged.

**Validation**: targeted phpunit run of the new test class.

### T010 — Read exposure (FR-008)

**Files**: `packages/ai-tools/src/Entity/EntityReadTool.php`, `packages/ai-tools/src/Entity/EntityListTool.php`, `packages/ai-tools/tests/Unit/Entity/EntityToolRevisionExposureTest.php` (NEW)

1. `EntityReadTool::serialize()`: after `entity_type`/`id`, add `revision_id` when the entity duck-types `getRevisionId()` and it returns non-null; omit otherwise (absence = "no expectation formable" — contract §17).
2. `EntityListTool` item map: same optional member per item (entities already loaded — zero added queries).
3. Tests: revisionable type exposes the head on read and on each list item; non-revisionable type omits the member; list output otherwise unchanged.

**Validation**: `./vendor/bin/phpunit packages/ai-tools/tests/Unit/Entity/ --no-progress`.

### T011 — JsonApiError meta + conditional PATCH

**Files**: `packages/api/src/JsonApiError.php`, `packages/api/src/JsonApiController.php`, `packages/api/tests/Unit/JsonApiErrorTest.php` (extend), `packages/api/tests/Unit/JsonApiControllerConflictTest.php` (NEW)

1. `JsonApiError`: trailing ctor param `public array $meta = []` (after `$source`); `toArray()` appends `'meta' => $this->meta` only when non-empty (every existing error byte-identical — pin in `JsonApiErrorTest`); `conflict()` gains `string $code = ''`, `array $meta = []` passthrough.
2. `update()` in `JsonApiController`, after the existing `data.id` check and BEFORE the access checks: parse `$data['data']['meta']['expected_revision_id'] ?? null`. Present but not a positive int → 400 `badRequest`. Present on a type failing `isRevisionable() && !isTranslatable()` → 422 `unprocessable` (friendly screen; the storage `\LogicException` stays the backstop — wrap the repository save so an unexpected LogicException with a stated expectation maps to 422 too, never 500).
3. Save split (after the existing set loop):
   ```php
   if ($expected !== null) {
       $repository = $this->entityTypeManager->getRepository($entityTypeId);
       try {
           $repository->save($entity, context: SaveContext::default()->withExpectedRevisionId($expected));
       } catch (RevisionConflictException $e) {
           return $this->errorDocument(JsonApiError::conflict(
               "Entity of type '{$entityTypeId}' with ID '{$e->entityId}' was modified: expected revision {$e->expectedRevisionId}, current revision is " . ($e->currentRevisionId ?? 'none') . '.',
               code: 'REVISION_CONFLICT',
               meta: ['expected_revision_id' => $e->expectedRevisionId, 'current_revision_id' => $e->currentRevisionId],
           ));
       } catch (EntityValidationException $e) {
           return $this->errorDocument(JsonApiError::unprocessable(/* deterministic summary */));
       }
   } else {
       $storage->save($entity);   // byte-identical legacy line — do not touch
   }
   ```
   `$repository instanceof EntityRepository` guard mirrors the tool (non-concrete + expectation → 422, documented).
4. Repository save needs the real id: the entity came from `loadByIdOrUuid()` — conflict payloads name `$e->entityId` (the real id), not the request locator (contract §15; uuid-routed test case).
5. `show()` pin: a test asserting the serialized attributes of a revisionable entity include `revision_id` (FR-008, contract §16).
6. `JsonApiControllerConflictTest` matrix = the data-model.md request-state table: absent / invalid / non-revisionable / stale (409 body member-by-member incl. meta) / current (200, attributes carry the new revision_id, a revision was cut) / repository validation failure (422) / uuid locator / **no-expectation byte-invariance** (the existing CRUD + access-control suites must pass unchanged — run the whole `packages/api/tests/`).

**Validation**: `./vendor/bin/phpunit packages/api/tests/ --no-progress`; `composer phpstan`.

### T012 — End-to-end dual-writer (SC-001) + gates

**Files**: `tests/Integration/AgentRun/DualWriterConflictTest.php` (NEW)

1. Follow `tests/Integration/AgentRun/AgentRunObservabilityTest.php` for the kernel-booted fixture style (`#[CoversNothing]`). Register a revisionable type; persist an entity.
2. The SC-001 script (contract §19): writer A `entity.read` → note `revision_id` R; writer B `entity.update` (no expectation) → head R+1; writer A `entity.update` with `expected_revision_id: R` → `isError`, json block `error: 'revision_conflict'`, `expected: R`, `current: R+1`; assert B's field value is intact and A's attempted value absent; writer A re-reads → R+1, restates → success; final entity carries both writers' fields (the disjoint-merge success path).
3. Contrast case: the same interleave with NO expectation silently overwrites — one assertion documenting today's last-write-wins (the "before" of the mission).
4. Gates:
   ```bash
   ./vendor/bin/phpunit packages/ai-tools/tests/ packages/api/tests/ tests/Integration/AgentRun/DualWriterConflictTest.php --no-progress
   composer phpstan
   composer cs-check
   bin/check-package-layers
   composer check-composer-policy
   bin/check-dead-code
   ```

## Edge cases & risks (from the plan premortem)

- **No-expectation invariance is the contract, not a courtesy**: if any existing ai-tools or api test needs modification, you broke FR-003/SC-003 — stop and fix the source. (The ONLY sanctioned test-fixture knowledge: `InMemoryToolRepository` not being SaveContext-capable is the *unsupported-path* fixture, not a thing to "fix".)
- **Expectation-stated PATCH cuts a revision** (repository pipeline) — intended, documented in contract §11; do not "harmonize" by routing no-expectation saves through the repository.
- **`current_revision_id: null`** (vanished row) must serialize as JSON null in the 409 meta and tool payload, not be dropped.
- **Don't fork the conflict-payload bytes** between execute and dry-run — single private builder (T009).
- **CP-NEW literal**: copy the tag from `git describe`, not from this prompt.

## Definition of Done

- [ ] All six subtasks complete; `packages/ai-tools/tests/`, `packages/api/tests/`, and the AgentRun e2e green; zero modifications to existing tests (SC-003).
- [ ] Contract `conflict-surfaces.md` clauses 1–19 each verifiably hold (reviewer walks the list).
- [ ] SC-001 demonstrated end-to-end (dual-writer test) with the conflict payload machine-correctable (re-read → retry succeeds in the same test).
- [ ] FR-008: API attribute pin + tool read/list exposure green.
- [ ] `composer phpstan`, `composer cs-check`, `bin/check-package-layers`, `composer check-composer-policy`, `bin/check-dead-code` clean; no changes outside `owned_files`.

## Reviewer guidance

- Diff the two save call sites: each must have exactly one new conditional branch with the legacy line byte-identical in the else arm.
- Grep both surfaces for head comparisons: the execute paths must contain NONE (storage owns the check); only dry-run carries the documented read-compare.
- Verify the catch ladders: `RevisionConflictException` before `\Throwable`; tool `\LogicException` mapping gated on a stated expectation (a LogicException on a no-expectation call must keep today's generic handling).
- Assert the 409 discriminator: the uuid-mismatch conflict stays codeless; only the revision conflict carries `REVISION_CONFLICT`.
- Check composer diffs: exactly one new line per manifest, sorted, `^<latest-tag>`.

## Activity Log

- 2026-06-12T00:00:00Z – spec-kitty.tasks – created
- 2026-06-12T08:46:58Z – claude:fable-5:implementer:implementer – shell_pid=27620 – Started implementation via action command
- 2026-06-12T09:07:22Z – claude:fable-5:implementer:implementer – shell_pid=27620 – Ready for review
- 2026-06-12T09:08:20Z – claude:fable-5:reviewer:reviewer – shell_pid=5792 – Started review via action command
- 2026-06-12T09:13:18Z – claude:fable-5:reviewer:reviewer – shell_pid=5792 – Review passed: 12-file diff matches owned_files exactly; FR-007 holds at both surfaces (non-concrete repo -> revision_expectation_unsupported, non-single-axis type -> 422; no parsed-expectation path reaches a plain save); two-block revision_conflict via single shared builder with dry-run byte-parity proven by json_encode comparison and no-expectation dry-run load-free; 409 REVISION_CONFLICT member-by-member with meta incl. null current; JsonApiError widening additive, uuid-mismatch 409 stays codeless; legacy save lines untouched in else arms; SC-001 e2e with recovery loop plus last-write-wins contrast; 571 tests green, phpstan/cs-check/composer-policy/layers/dead-code all clean; no existing test modified. NOTE: the two new waaseyaa/entity-storage constraints are ^0.1.0-alpha.205 (correct for the worktree tag) but main is now at v0.1.0-alpha.206 - bump both literals at merge or CP-NEW fails on main.
- 2026-06-12T09:35:13Z – claude:fable-5:reviewer:reviewer – shell_pid=5792 – Done override: Mission squash-merged to main
