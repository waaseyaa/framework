# CW-v1 WP-2 Implementation Plan — node revisions + forward drafts

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Opt node into revisionable storage with a safe migration path for existing data, implement the forward-draft mechanic (`WorkflowState::defaultRevision`), and close the revision-pointer-path bypass of the workflow guard.

**Architecture:** Node's `EntityType` flips `revisionable: true` (schema handled by the existing idempotent `revisions:enable` machinery); `TransitionService`/`WorkflowStateGuard` learn the two-pointer model (current vs published revision) so a draft edit of published content never clobbers the live version; a new L1 pre-pointer-move event closes the `rollback()`/`setCurrentRevision()`/`setPublishedRevision()` guard bypass; a CLI backfill handler + ops runbook make binding activation safe on deployments with existing content.

**Tech Stack:** existing two-axis revision substrate (`docs/specs/revision-system-unified.md` — LIVE canonical, read first), `Waaseyaa\Foundation\Migration\Migration` schema migrations, WP-1 engine (merged, alpha.256).

## Global Constraints

Everything from the WP-0/WP-1 plan's Global Constraints applies verbatim (strict_types, final, PHPUnit 10.5 attributes/no `-v`, Waaseyaa LoggerInterface pattern, getquery gate, layer rules, commit trailer, PR references `#1920` never `Closes`). Additionally:

- **Baseline is `origin/main`** (alpha.256, WP-0 #1927 + WP-1 #1929 + binding #1931 merged). Local `main` refs may be stale — always branch from `origin/main`.
- CI includes: Changelog discipline (add the `[Unreleased]` entry IN this PR), spec-drift (commit-trailer `spec-reviewed:` acks or real spec updates — PR-body acks do not work), `check-symfony-imports` (new subscriber/event files may need `.symfony-import-allowlist.json` `legacy_files` entries), dead-code gate (`@api` conventions).
- Layering: entity-storage (L1) must NOT import workflows (L3) — the bypass closure is an L1-dispatched event that L3 subscribes to (mirroring how `cache` listens to entity events), NOT a workflows import in the repository.
- One PR at the end (`feat(#1920): CW-v1 WP-2 — node revisions + forward drafts`), commit per task.

## Design decisions (locked — do not re-decide)

1. **`revisionDefault: true` for node** — every ordinary save creates a new revision (Drupal parity). Per-bundle opt-out comes from wiring the currently-inert `NodeType::isNewRevision()` (Task 2.3).
2. **Two-pointer status semantics:** the base row's `status` reflects the **published-pointer revision's** workflow state, not the tip's. A forward draft (new tip revision in a non-`default_revision` state) leaves `status` and `published_revision_id` untouched; entering a `default_revision: true` state moves the published pointer to the acting revision and sets `status` from that state's `published` flag (`published` state → 1; `archived` → 0).
3. **Bypass closure = new L1 event `BeforeRevisionPointerMoveEvent`** dispatched by `rollback()`, `setCurrentRevision()`, `setPublishedRevision()` (and the `saveTranslation*` trio) BEFORE any write; workflows subscribes and validates the implied state change like a transition. Deliberately NOT reusing `PRE_SAVE` — these are pointer moves, not saves, and reusing the name would silently activate save-semantics listeners.
4. **Backfill is binding-scoped, not framework-scoped:** the framework cannot know which workflow a site binds, so `workflow_state` backfill is a CLI handler (`workflows:backfill-state`) run as part of the documented binding-activation runbook, not a node-package migration. The node migration only guarantees revision schema.
5. **Adjacent gap included intentionally:** pointer-move paths currently invalidate no cache tags and `rollback()` is invisible to audit — Task 2.5 closes both while in the area, called out as deliberate side-scope in the PR body.

---

### Task 2.1: Node opts into revisionable storage

**Files:** Modify `packages/node/src/Node.php` (ContentEntityKeys + a declared `workflow_state` field), `packages/node/src/NodeServiceProvider.php` (fromClass flags). Tests: node package schema/registration tests.

- `#[ContentEntityKeys(id: 'nid', uuid: 'uuid', label: 'title', bundle: 'type', revision: 'revision_id')]` (`packages/entity/src/Attribute/ContentEntityKeys.php:19`).
- `EntityType::fromClass(Node::class, group: 'content', bundleEntityType: 'node_type', revisionable: true, revisionDefault: true)` (`packages/node/src/NodeServiceProvider.php:14-18`; override params exist at the call site per `EntityType::fromClass()`, `packages/entity/src/EntityType.php:196-242`).
- Declare `workflow_state` as a real `#[Field(type: 'string')]` on Node (today it rides the `_data` blob undeclared — works, but invisible to SchemaPresenter/JSON:API discovery).
- `ContentEntityBase` already provides the revision capability structurally — zero Node class changes beyond the attribute/field.
- TDD: failing test asserting `getEntityType('node')->isRevisionable()` and revision key `revision_id`; then a storage test that a save on a fresh SQLite schema creates a `node_revision` row (live dialect: single-underscore `<table>_revision`, `SqlSchemaHandler::getRevisionTableName()`, `packages/entity-storage/src/SqlSchemaHandler.php:314-317` — NOT the dormant `RevisionTableBuilder` double-underscore dialect).
- Commit: `feat(#1920): node opts into revisionable storage (WP-2)`.

### Task 2.2: Node revision-schema migration (existing deployments)

**Files:** Create `packages/node/migrations/2026_07_06_000001_node_revision_schema.php`; modify `packages/node/composer.json` (add `extra.waaseyaa.migrations: "migrations"` — currently ABSENT). Tests: migration idempotency test following media's pattern.

- Follow `packages/media/migrations/2026_07_01_000001_add_media_version_vid_unique_index.php` exactly: additive, shape-guarded (`if (!$schema->hasTable(...)) return;`), `down()` documented no-op.
- The migration ensures the `node_revision` table + base-row `revision_id`/`published_revision_id` pointer columns exist (delegate to the same idempotent primitives `revisions:enable` uses — `EntitySchemaSyncRunner` / `SqlSchemaHandler::ensureRevisionTable()` at `:256-268`), then seeds initial revisions for rows lacking history via `EntityRepository::backfillInitialRevisions()` (the exact machinery `RevisionsEnableHandler` runs, `packages/cli/src/Handler/RevisionsEnableHandler.php:70-83`). If invoking the runner inside a `Migration::up()` is awkward, the migration ensures schema only and the runbook (Task 2.7) mandates `bin/waaseyaa revisions:enable node` — implementer picks whichever is cleaner and documents the choice; either way the outcome must be provably idempotent.
- Commit: `feat(#1920): node revision schema migration (WP-2)`.

### Task 2.3: Wire the inert `NodeType::isNewRevision()` knob

**Files:** Create `packages/node/src/Listener/NodeRevisionDefaultListener.php`; modify `packages/node/src/NodeServiceProvider.php` (boot() wiring — Symfony-contracts dispatcher FQCN, mirror `RelationshipServiceProvider`). Tests: unit + a wiring test.

- Fact: `EntityRepository::shouldCreateRevision()` (`packages/entity-storage/src/EntityRepository.php:1805-1832`) consults `$entity instanceof RevisionableInterface ? $entity->isNewRevision() : null`, and NO `ContentEntityBase` subclass declares that legacy interface — so the per-entity branch is dead and everything falls to the type-wide `revisionDefault`. `NodeType::isNewRevision()` (`packages/node/src/NodeType.php:98-111`) is currently a knob wired to nothing.
- Fix: Node `implements RevisionableInterface` (the trait already supplies the methods); a `PRE_SAVE` listener in the node package resolves the node's `NodeType` and calls `$node->setNewRevision($nodeType->isNewRevision())` — UNLESS the save already carries an explicit per-save decision (respect an already-set value; check the trait's default-vs-set semantics and preserve explicit `setNewRevision()` calls by earlier actors like TransitionService).
- Deny-path/edge tests: bundle with `new_revision: false` → in-place update, no new revision row; default bundle → new revision per save.
- Commit: `feat(#1920): NodeType new_revision actually controls revision creation (WP-2)`.

### Task 2.4: `BeforeRevisionPointerMoveEvent` (L1) — the bypass choke point

**Files:** Create `packages/entity-storage/src/Event/BeforeRevisionPointerMoveEvent.php`; modify `packages/entity-storage/src/EntityRepository.php` (`rollback()` :1043-1099, `setCurrentRevision()` :1137-1200, `setPublishedRevision()` :1237-1317, `saveTranslationRevision()`/`saveTranslationRevisions()`/`saveTranslation()` :1335-1501). Tests: entity-storage unit tests per method.

- Event shape (mirror `RevisionPointerMovedEvent`'s payload, add the pre-phase semantics): `entityTypeId`, `entityId`, `operation` (`'rollback'|'revert'|'publish'|'translation_save'`), `fromRevisionId: ?int`, `toRevisionId: ?int`, `actorUid: ?int`, plus the loaded target revision's values array so subscribers can read its `workflow_state` without a second load. Extends Symfony contracts `Event` (stoppable is not enough — subscribers deny by THROWING, same convention as the guard).
- Dispatch BEFORE any write in each of the four call sites (fact: they currently dispatch nothing before writing; only `REVISION_*`/`RevisionPointerMovedEvent` after). No `SaveContext` change — the event carries what validators need.
- These methods are L1; the event is L1; no upward import. Test: each method dispatches the event with correct operation + a subscriber that throws aborts the write (assert storage unchanged).
- `.symfony-import-allowlist.json`: new event file needs a `legacy_files` entry (Symfony `Event` parent), same as `WorkflowTransitionEvent`.
- Commit: `feat(#1920): pre-pointer-move event on all revision pointer paths (WP-2)`.

### Task 2.5: Workflows subscribes — bypass closed; cache/audit ride along

**Files:** Create `packages/workflows/src/Listener/WorkflowPointerMoveGuard.php`; modify `packages/workflows/src/WorkflowServiceProvider.php` (boot). Modify `packages/cache/src/Listener/EntityCacheSubscriber.php` (subscribe invalidation to `RevisionPointerMovedEvent` + `REVISION_REVERTED` — fact: zero cache invalidation on pointer moves today) and add audit coverage for `rollback()` (fact: `PublishPointerAuditListener` covers only revert/publish pointer moves; rollback is invisible). Tests per listener.

- Guard logic: resolve binding (unbound type → return). Compute the implied state change: target revision's `workflow_state` vs the currently-effective state (for `'publish'` operations compare against the published-pointer revision's state; for `'rollback'`/`'revert'` against the current-pointer's). Same validation as a transition: edge must exist, permission required when `AccountContextInterface` has an account, null context = edge-legality only. Deny by `TransitionDeniedException`.
- Cache/audit additions are the flagged intentional side-scope: state them in the PR body as closing a pre-existing gap (pointer moves serving stale cache and unaudited rollbacks), with their own tests.
- Commit: `feat(#1920): pointer-move workflow guard + cache/audit coverage (WP-2)`.

### Task 2.6: Forward-draft mechanics in TransitionService + guard reconciliation

**Files:** Modify `packages/workflows/src/Transition/TransitionService.php`, `packages/workflows/src/Listener/WorkflowStateGuard.php` (`applyState()`). Tests: transition-service + guard suites.

- Fact: `transition()` today does `set('workflow_state') + set('status') + save()` — no `defaultRevision` awareness (README flags this as WP-2 debt). Implement decision 2:
  - Target state `defaultRevision: false` on an entity whose published pointer exists and stays (forward draft): save creates the new tip revision carrying the state; do NOT touch `status` or the published pointer. The live version keeps serving.
  - Target state `defaultRevision: true`: save the revision, then `EntityRepository::setPublishedRevision($entityId, $newRevisionId)` (:1237) to move the pointer, and set `status` per the state's `published` flag. (The pointer move fires Task 2.4's event; TransitionService is the sanctioned caller — its own validation already ran, so the pointer guard revalidating is harmless/idempotent, same as the WP-1 save-guard double-check.)
  - Entity with no published revision yet (never published): current WP-1 behavior stands (status follows state directly).
- `WorkflowStateGuard::applyState()` gets the same rule — it must stop unconditionally setting `status` when the save is a forward draft on published content.
- Integration test: publish a node → edit via raw save into `draft` (forward draft) → assert public read path (published pointer + status) still serves the OLD content and `status=1` → transition draft revision to `published` → assert pointer moved, new content live.
- Commit: `feat(#1920): forward drafts — default_revision drives the published pointer (WP-2)`.

### Task 2.7: `workflows:backfill-state` CLI + binding-activation runbook

**Files:** Create `packages/cli/src/Handler/WorkflowsBackfillStateHandler.php` (+ command registration mirroring `RevisionsEnableHandler`); modify `docs/specs/operations-playbooks.md` (runbook section). Tests: CliTester coverage.

- Handler: `workflows:backfill-state <entity_type> <workflow_id> [--bundle=]` — for every row of the type/bundle missing a non-empty `workflow_state`: set it to the workflow's published-flagged `default_revision` state where `status = 1`, else the workflow's `initial_state`. Idempotent (skips rows with state), reports counts, exits nonzero on partial failure (the R16 fail-fast lesson). Entity queries: `->accessCheck(false)` with justification comment (system-level backfill).
- Runbook (ops playbook): the safe activation sequence for existing deployments — (1) deploy WP-2 code, (2) `migrate:up`, (3) `revisions:enable node` if the migration deferred it, (4) `workflows:backfill-state node editorial`, (5) only THEN add the `workflows.assignments` binding. Include the failure mode it prevents (fact: `currentState()` falls back to `initial_state`, so binding before backfill makes every legacy published row read as `draft` — and note `WorkflowBindingResolver` hard-throws on non-revisionable types, so mis-ordering fails loudly at step 5, not silently).
- Commit: `feat(#1920): workflows:backfill-state + binding activation runbook (WP-2)`.

### Task 2.8: Integration spine + docs + PR

**Files:** Create `packages/workflows/tests/Integration/ForwardDraftFlowTest.php`; update `packages/workflows/README.md` (WP-2 debt notes resolved), CHANGELOG `[Unreleased]` entry, spec truth-ups.

- Spine: real SQLite, node bound to seeded editorial — create (forced draft, unpublished) → publish (pointer + status) → forward-draft edit (live untouched) → review → publish (promotion) → archive (pointer moves, status 0) → rollback attempt without permission DENIED via the pointer guard → rollback with permission succeeds and audits.
- Read-side note: `WorkflowVisibility` deriving published-ness from bound-workflow state flags (README flags it "tracked for WP-2") — implement if small once the above lands, else record explicitly as WP-4-adjacent with rationale in the PR body; do not silently drop it.
- Spec-drift: entity-storage changes map to `docs/specs/entity-system.md` (+ revision spec) — update `docs/specs/revision-system-unified.md` for the new pre-event (it documents the pointer methods' event behavior at §4a); `docs/specs/content-workflow.md` truth-up rides #1921 or a follow-up if still unmerged.
- Full gates, push, ONE PR: `feat(#1920): CW-v1 WP-2 — node revisions + forward drafts`. No auto-merge.

## Self-review notes (plan-writing time)

- Spec coverage: node opt-in + migration (2.1/2.2), forward drafts (2.6), tracker requirement (a) pointer bypass (2.4/2.5), (b) WP-0-vs-guard authority (documented in 2.6/2.7 runbook; field gate stays defense-in-depth), (c) `SaveContext::isImport` — the guard's create rule already denies null-context non-initial creates; the backfill handler is the sanctioned import path, so no guard change needed — implementer verifies imports via `isImport` saves don't hit the create guard incorrectly and reports, (d) state backfill (2.7).
- Known judgment calls locked in the Design decisions block; the one place the implementer may choose is 2.2's migration-vs-runbook split for `revisions:enable`.
- Verify-don't-trust: exact `applyState()` shape post-WP-1 amendments, the trait's `isNewRevision()` default semantics (2.3), and whether `setPublishedRevision()` accepts the just-created revision id atomically after `save()` in one request (check `TransitionResult` needs the new revision id — `doSave()` sets it on the entity's revision key).
