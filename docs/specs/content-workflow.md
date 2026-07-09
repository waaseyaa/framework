# Content Workflow (CW-v1)

<!-- Spec reviewed 2026-07-06 - Initial design spec, approved by Russell pre-implementation
     (anchoring issue #1920, resolves audit decision D1). Status: DESIGN — no engine code
     exists yet; the code this spec describes REPLACES the dead editorial machinery in
     packages/workflows (see "Cleanup inventory"). Update this spec as WPs land. -->

**Status: DESIGN (approved 2026-07-06).** Anchoring issue: [#1920](https://github.com/waaseyaa/framework/issues/1920). Resolves audit decision D1 (wire-or-delete the dead editorial machinery) as a client-driven build-fresh. Design bar: Drupal content-moderation parity and beyond.

| WP | Delivers | Status |
|---|---|---|
| WP-0 | Interim `status`/`workflow_state` field gate (self-publish fix) | pending (ships on R16 timeline) |
| WP-1 | Config schema, TransitionService, save-path guard, events, permissions, default `editorial` workflow | pending |
| WP-2 | Node opt-in to revisionable storage + migration; forward-draft flow end-to-end | pending |
| WP-3 | Group (department) transition constraints | pending (groups readiness assessment first) |
| WP-4 | API transition endpoints + admin SPA transition UI | pending |
| WP-5 | Delete dead machinery + README truth-up | pending (never before its replacement lands) |

## Purpose

A general content-workflow engine: named editorial states, permission- and group-gated transitions between them, enforced in the write path, with transition audit history. The v1 consumer case is department routing — content drafted in one department routes to decision-makers for approval before publication — but nothing in the engine knows about any specific department: departments, states, and approval chains are site configuration.

**What Drupal core does that we match:** workflows as config, moderation state on revisions, forward drafts of published content, per-transition permissions.
**What Drupal needs contrib for that we ship (the "beyond"):** group-scoped transition constraints (department routing) as a first-class optional dimension.

## Concepts and config schema

A **workflow** is a config entity (CMI-syncable, validated on import):

```yaml
# config: workflows.workflow.editorial
id: editorial
label: Editorial
states:
  draft:      { label: Draft,     published: false, default_revision: false }
  review:     { label: In review, published: false, default_revision: false }
  published:  { label: Published, published: true,  default_revision: true }
  archived:   { label: Archived,  published: false, default_revision: true }
initial_state: draft
transitions:
  submit_for_review: { label: Submit for review, from: [draft],            to: review,    permission: 'use editorial transition submit_for_review' }
  publish:           { label: Publish,           from: [draft, review],    to: published, permission: 'use editorial transition publish' }
  reject:            { label: Send back,         from: [review],           to: draft,     permission: 'use editorial transition reject' }
  archive:           { label: Archive,           from: [published],        to: archived,  permission: 'use editorial transition archive' }
  restore:           { label: Restore to draft,  from: [archived],         to: draft,     permission: 'use editorial transition restore' }
```

A transition MAY additionally carry `group_constraint: content_groups` (WP-3): fireable only by members of the group(s) the content belongs to (departments are `groups` entities; content carries a department relationship). Permission answers *may this kind of person do this*; the group constraint answers *may they do it to this content*. A workflow with no group constraints behaves exactly like Drupal core.

**Bindings** map `entity_type` + `bundle` → workflow id (config: `workflows.assignments`). Binding requires the entity type to be revisionable — rejected at config-import validation otherwise.

The default `editorial` workflow ships as **config data, not code**. The retired machinery's preset-in-code (`EditorialWorkflowPreset` as canonical definition) is an explicit anti-pattern here: replacing a self-contained populated default with an empty generic silently broke consumers once already (CLAUDE.md gotcha).

## State lives on revisions

`workflow_state` is stored **per revision** on workflow-bound types (two-axis storage substrate: `RevisionableStorageDriver`, `docs/specs/revision-system-unified.md`).

- A state flagged `published: true` sets the entity's `status` accordingly when its revision is the default.
- `default_revision: true` states promote their revision to default on entry — this is the **forward draft** mechanic: editing published content creates a new non-default revision in `initial_state`; the live revision stays published; the `publish` transition promotes the draft revision to default.
- Creating content starts it in `initial_state` (see save-path guard).

**Staged limitation (documented, not hidden):** v1 state is per *revision*, not per revision-*translation*. Drupal moderates per translation; per-translation state is a post-v1 stage on the same storage axis. Until then, translations share their revision's state.

## TransitionService — the one door

`Waaseyaa\Workflows\Transition\TransitionService` (final, container-bound):

- `transition(ContentEntityInterface $entity, string $transitionId, AccountInterface $account): TransitionResult`
  1. **Validate:** binding exists for the entity type/bundle; transition exists in the bound workflow; current revision state ∈ `from[]`; `$account` holds the transition's permission; group constraint (if any) satisfied. Failure → typed exception (`TransitionDeniedException` with a machine-readable reason), never a silent no-op.
  2. **Apply:** set `workflow_state` on the target revision; set `status` and promote to default revision per the target state's flags; persist through `EntityRepository` (the canonical pipeline — no direct storage writes).
  3. **Announce:** dispatch `WorkflowTransitionEvent` (pre + post, Symfony `Event` subclasses like the live `EntityEvent` lifecycle events); write a transition audit entry (see Integration).
- `getAvailableTransitions(ContentEntityInterface $entity, AccountInterface $account): array<TransitionDefinition>` — the read-side the admin UI renders buttons from. This resurrects the *concept* behind the retired dry-run/guards controllers as a real engine method.

## Save-path guard

An entity pre-save subscriber makes the raw write path equivalent to the service:

- A save that mutates `workflow_state` is validated as if `TransitionService::transition()` had been called by the acting account (`_account` semantics) — same denial, same exception. A raw `PATCH /api/node/{id}` cannot do what the service would refuse.
- **Create forces `initial_state`** and, for workflow-bound types, a non-published `status` unless the acting account holds a transition permission into a published state. This closes the born-published hole (`Node` defaults `status = 1` in its constructor) for workflow-bound types.

**Wiring invariants (both are lessons from the machinery this replaces):**
1. The subscriber registers against the **Symfony-contracts dispatcher FQCN** via the kernel-services bus — resolving the foundation FQCN silently no-ops (this exact bug left `DomainValidationListener` dead and is still latent in Media/Field). A **wiring regression test** asserts the subscriber actually fires on a real kernel-dispatched save; a unit test on the subscriber class alone does not count.
2. **Deny-path tests are first-class:** every gate (permission, from-state, group, create-initial-state) has an explicit test proving denial, not just tests proving the happy path.

## Pointer-move guard (WP-2 task 2.5)

The save-path guard only observes `EntityRepository::save()` (via `EntityEvents::PRE_SAVE`). But `rollback()`, `setCurrentRevision()`, and `setPublishedRevision()` move the base-row pointer (or, for `rollback()`, copy a revision forward) WITHOUT a `doSave()` write, so a state change made through any of them bypassed workflow validation entirely. `Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent` (L1, dispatched by FQCN before any write on all six pointer-affecting methods, including the translation trio) is the choke point; `Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard` subscribes to it and validates the implied state change exactly like a transition (edge must exist; permission required only when an acting account context exists; a null context checks edge-legality only) — same reasoning as the save-path guard, applied to a different trigger.

- **Implied state change:** the target revision's `workflow_state` (read from the event's `revisionValues`, no second load) vs. the currently-effective state. For `publish`, that's the **published-pointer** revision's state (`fromRevisionId` is the prior `published_revision_id`); for `rollback`/`revert`, the **current-pointer** revision's state (`fromRevisionId` is the prior `revision_id`). `EntityRepository` already resolves the correct prior pointer per operation, so the guard reads it uniformly. No prior revision (never-published, or unresolvable) falls back to the workflow's initial state.
- **`translation_save` is a deliberate, unconditional pass-through.** Per "State lives on revisions" above, v1 `workflow_state` is tracked per revision, not per revision-translation — translations share their revision's state. A translation write carries translated field values only; it implies no `workflow_state` change, so there is nothing to validate. Kept consistent with the save-path guard, which is likewise translation-unaware today.
- **Bundle resolution:** the event carries only `entityTypeId` + `entityId`, not a bundle. The guard derives it from `revisionValues` using the entity type's own `bundle` key, mirroring `EntityBase::bundle()` (default bundle = entity type id when no bundle key/value exists) rather than loading the entity a second time.

**Adjacent gap closed in the same task (deliberate side-scope, not a refactor):** pointer moves previously invalidated no cache tags, and `rollback()` was invisible to audit (`PublishPointerAuditListener` only covers `setCurrentRevision()`/`setPublishedRevision()` via `RevisionPointerMovedEvent`, which `rollback()` never dispatches — that event is reserved for pointer moves WITHOUT a new revision). `EntityCacheSubscriber` now also invalidates on `RevisionPointerMovedEvent` and the legacy `EntityEvents::REVISION_REVERTED`. `Waaseyaa\Audit\Listener\RollbackAuditListener` gives rollback its own audit kind (`AuditEventKind::RevisionRollback`), discriminating it from the other two pointer paths by correlating rollback's own back-to-back `REVISION_CREATED` → `REVISION_REVERTED` dispatch for the same entity/revision (a single, always-consumed pending slot — never a growing per-request map).

## Permissions

One permission string per transition, registered through the standard permission surface, named `use <workflow_id> transition <transition_id>`. Roles grant permissions as usual. `getAvailableTransitions()` is the only sanctioned way for UIs to decide what to offer.

**WP-0 interim gate** (ships first, on the R16 timeline): `NodeAccessPolicy::fieldAccess()` returns Forbidden for `status` and `workflow_state` writes unless the account holds `use editorial transition publish` (exact engine-compatible name — nothing renames when the engine lands), and node create forces `status = 0` for accounts lacking it. The save-path guard supersedes this gate for workflow-bound types in WP-1/2; the field gate remains as defense-in-depth for unbound types. This changes the documented "editorial booleans intentionally NOT gated" stance in `NodeAccessPolicy` — that docblock is updated as part of WP-0.

## Integration

- **Audit (L3 → L1, downward):** `TransitionService` writes transition entries (who, entity/revision, from → to, timestamp) through the audit package's public API. Deliberately *not* implemented as an audit-side subscriber to a workflows event — that would be an upward type import requiring a `KERNEL_EXEMPT_FILES` entry.
- **Notifications (L3 → L3):** the notification package (or app code) subscribes to `WorkflowTransitionEvent`. The framework ships the event surface; recipient routing is app configuration.
- **Visibility (read side):** unchanged ownership — `WorkflowVisibility` / `WorkflowVisibilityFilter` / `EditorialVisibilityResolver` remain the read gates (fail-closed per R16 #1915). For workflow-bound types they derive published-semantics from the bound workflow's state flags instead of assuming `status === 1`.
- **API (WP-4):** `POST /api/{type}/{id}/workflow/transition` (`_gate` + service validation; body `{ transition: "publish" }`) and `GET /api/{type}/{id}/workflow/transitions` (available transitions for `_account`). Raw `PATCH` of `workflow_state` stays legal-but-guarded (see save-path guard) for parity with generic clients.
- **Admin SPA (WP-4):** transition buttons on the content edit/view surface fed by the available-transitions endpoint; state badge in listings. (Nuxt nested-dir component prefix gotcha applies; test the populated path.)

## Layering

`workflows` stays **L3 (Services)**. Imports: entity/entity-storage/access/audit/config (L1, downward), groups (L2, downward — WP-3), relationship (L2, downward — WP-3). Nothing upward; no new `PL006` same-layer cycles.

## Cleanup inventory (WP-5 — only after the replacement lands)

Delete: `EditorialWorkflowService` (+ `transitionNode`), `EditorialTransitionAccessResolver`, `AuthoringRoleMatrix`, `DomainValidationListener` (never subscribed; kept alive in the dead-code gate only by its own unit test), `packages/api/src/Workflow/WorkflowDryRunController.php`, `packages/api/src/Controller/WorkflowGuardsController.php`, their route registrations and ~950 lines of tests. Evaluate in WP-1 and delete here if unused: `ContentModerator`, `ContentModerationState`, `EditorialWorkflowPreset`.
Keep: `Workflow`/`WorkflowState`/`WorkflowTransition` primitives where they fit the new engine; the visibility classes (live).
Truth-up: `packages/workflows/README.md` currently advertises `transitionNode` as live API — rewrite around the new engine.

## Testing requirements

- **Integration spine:** one end-to-end editorial story — create draft (forced initial state) → submit for review → publish → forward-draft edit of published content → approve → promoted default revision — driven through the real save path and `TransitionService`, real SQLite (`DBALDatabase::createSqlite()`).
- **Deny paths at every gate** (permission, from-state mismatch, group constraint, create-born-published attempt, raw-PATCH bypass attempt).
- **Wiring regression test** (subscriber fires on kernel-dispatched save; correct dispatcher FQCN).
- **Config validation tests:** unknown state in `from`/`to`, binding a non-revisionable type, missing `initial_state` — all rejected at import.
- Unit tests per component; access-policy tests use anonymous classes for intersection types (PHPUnit `createMock()` limitation).

## Design invariants (why it looks like this)

1. **One door.** Every state change flows through `TransitionService` validation — the service directly, or the save-path guard proving equivalence. The predecessor died precisely because enforcement was optional.
2. **Definitions are data.** No preset-in-code as the canonical definition.
3. **Revision-aware from day one.** Retrofitting revisions under a state-on-entity engine is a redesign; shipping revision-aware with staged delivery is not.
4. **Nothing generalizes from `node`.** The engine addresses entity type + bundle via bindings; `node` is just the first bound type (WP-2).
5. **Fail closed, deny loudly.** Typed denial exceptions with machine-readable reasons; no silent no-ops, no fail-open defaults.
