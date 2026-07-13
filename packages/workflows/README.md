# waaseyaa/workflows

**Layer 3 — Services**

Content moderation and editorial workflow states for Waaseyaa applications.

## CW-v1 engine (WP-1 + WP-2, live)

The **content-workflow engine** (docs/specs/content-workflow.md) is now the canonical surface: named
editorial states, permission-gated transitions, enforced in the write path, with transition audit
history. Workflows are config, not code — the default `editorial` workflow ships as declarative
seed data (`DefaultWorkflows::EDITORIAL`), not a hardcoded preset class.

- `Workflow` / `WorkflowState` / `WorkflowTransition` — the config-entity primitives. States now
  carry `published` / `defaultRevision` flags; transitions carry an explicit or derived
  `permission` string (`use {workflow_id} transition {transition_id}`); the workflow itself carries
  an `initial_state`.
- `Validation\WorkflowValidator` — structural checks (unknown states in `from`/`to`, unknown
  `initial_state`, zero states) used at seed/import time.
- `Binding\WorkflowBindingResolver` — resolves the workflow bound to an entity type + bundle via
  the `workflows.assignments` config (`{type}.{bundle}` exact key wins over `{type}.*` wildcard);
  throws for a non-revisionable bound type or an unknown workflow id.
- `Transition\TransitionService` — **the one enforcement door**: `transition()` validates (binding
  exists, transition exists, current state is a legal `from`, account holds the permission) →
  applies (`workflow_state` + `status` per the target state's `published` flag) → persists through
  `EntityTypeManagerInterface::getRepository()->save()` (never a direct storage write) → announces
  (`WorkflowEvents::PRE_TRANSITION` / `POST_TRANSITION`) → records a best-effort audit entry
  (`AuditEventKind::WorkflowTransition`). Denials throw `Transition\TransitionDeniedException` with
  a machine-readable `reason` (`unbound`, `unknown_transition`, `illegal_edge`, `permission`) —
  never a silent no-op. `getAvailableTransitions()` is the sanctioned read-side for UIs.
- `Listener\WorkflowStateGuard` — a `EntityEvents::PRE_SAVE` subscriber that makes the raw entity
  save path equivalent to `TransitionService`: a create is forced into `initial_state` unless the
  acting account can reach a different state via a single legal + permitted transition (closing the
  born-published hole); an update's `workflow_state` change is validated exactly like a transition
  (permission required whenever an acting `AccountContextInterface` context exists; a null context —
  CLI/queue/programmatic — checks edge-legality only).
- `WorkflowServiceProvider::boot()` wires both guards below onto the real dispatcher
  (Symfony-contracts FQCN) and seeds the default `editorial` workflow if absent (log-and-skip on
  validation failure, never boot-crash).
- `Listener\WorkflowPointerMoveGuard` — a `BeforeRevisionPointerMoveEvent` (L1) subscriber that
  closes the pointer-move bypass: `rollback()`, `setCurrentRevision()`, and `setPublishedRevision()`
  move the base-row pointer WITHOUT a `doSave()` write, so `WorkflowStateGuard` alone could not see
  them. Validates the implied state change like a transition — same-state moves (e.g. promoting a
  forward draft, or rolling back to an earlier same-state revision) need the permission of *any*
  transition targeting that state; different-state moves need the real edge's own permission, no
  exceptions.
- **Forward drafts (engine substrate, WP-2):** `node` opts into revisionable storage
  (`revisionDefault: true`, per-bundle opt-out via `NodeType::isNewRevision()`); `TransitionService`
  implements the two-pointer status semantics — the base row's `status` always reflects the
  *published-pointer* revision's state, never the tip's. The engine supports a forward-draft entry
  edge (editing content back into a `default_revision: false` state while the published pointer
  keeps serving the live revision) on any workflow that defines one; a later `publish` promotes it
  (pointer moves, `status` flips only after the pointer move commits — a guard denial never leaves
  `status` flipped with the pointer stuck). Raw saves never enact pointer moves — only
  `TransitionService` (or a direct, sanctioned repository call) moves the pointer. Forward drafts (a
  published → draft edge on the shipped `editorial` workflow) are deferred: the WP-2 review found no
  read path is pointer-aware, so a forward draft's tip content is served by `find()`-based readers
  while status/pointer reflect the published revision. Forward drafts return on true
  default-revision semantics (the base row keeps serving the published revision; drafts live only in
  revision rows). `restore_to_published` (archived → published) rounds out the shipped `editorial`
  workflow alongside `restore` (archived → draft) so archived-content republishing has real edges —
  that round trip does not carry the live-content read-side risk above, since the entity is
  unpublished throughout. Backfilling legacy content's `workflow_state` onto binding activation is a
  CLI step, `workflows:backfill-state` (see `docs/specs/operations-playbooks.md` Playbook H) —
  deliberately binding-scoped, not framework-scoped, since the framework cannot know in advance which
  workflow a site will bind. Full mechanics: `docs/specs/content-workflow.md` "Forward-draft
  mechanics".

- **Group (department) transition constraints, live (WP-3):** a transition MAY carry `group_constraint: content_groups`, fireable only by accounts that are members of a group the content itself belongs to — departments are `waaseyaa/groups` entities, membership and content-department assignment are `waaseyaa/relationship` rows, filtered to live (`status = 1`) rows only (`Group\GroupConstraintChecker`, backed by `Waaseyaa\Groups\Membership\GroupMembershipService`). Enforced at all four state-changing/state-revealing sites — `TransitionService::transition()` (immediately after the permission gate — permission wins when an account holds neither), `getAvailableTransitions()`, `WorkflowStateGuard::guardUpdate()`, and both branches of `WorkflowPointerMoveGuard` — with the same fail-closed rule throughout: content with no recorded group can never satisfy a constraint, an unrecognised constraint kind denies rather than degrading to unconstrained, and a missing (`null`) `GroupConstraintChecker` denies every group-constrained transition rather than un-gating it (unconstrained transitions are unaffected by a missing checker). `TransitionDeniedException::REASON_GROUP_CONSTRAINT` is the new denial reason. Full contract: `docs/specs/content-workflow.md` "Group constraints (WP-3)".

API transition endpoints + admin SPA are WP-4.

## Legacy machinery (superseded, removal tracked as WP-5 / #1920)

**WP1 (landed):** deleted the retired read-only dry-run/guards machinery — `AuthoringRoleMatrix`
(and its `WorkflowServiceProvider` singleton binding) plus the API-side `WorkflowDryRunController`
and `WorkflowGuardsController`. No compat shim; the endpoints are gone.

The classes below predate the CW-v1 engine and are **not wired to any enforcement path** —
`EditorialWorkflowService::transitionNode()` mutates fields in memory only; the caller must save
separately, with no guard proving the save is legitimate. They are kept only until a later WP-5
slice deletes them:

- `ContentModerator` / `ContentModerationState` — the original state-machine driver, superseded by
  `TransitionService`.
- `EditorialWorkflowService` / `EditorialTransitionAccessResolver` — the ungated
  mutate-and-hope-you-saved path and its permission/role lookups, superseded by `TransitionService`
  + `WorkflowStateGuard`. `EditorialTransitionAccessResolver` is retained only because
  `EditorialWorkflowService` still constructs one by default; it has no other live caller.
- `DomainValidationListener` — never subscribed to any dispatcher; dead code kept alive in the
  dead-code gate only by its own unit test.

**Live, unaffected by CW-v1 — with a known WP-2 read-side gap:** `WorkflowVisibility` /
`WorkflowVisibilityFilter` / `EditorialVisibilityResolver` remain the read-side gates (fail-closed
per R16 #1915). `WorkflowVisibility::nodeState()` trusts a raw `workflow_state` value over `status`
whenever both are present — correct pre-WP-2 (tip and published-facing state were always the same
row), but wrong for a forward draft: the tip's `workflow_state` (e.g. `draft`, mid-edit) now
disagrees with the base row's `status`, which stays correctly pointer-derived. Concretely: editing
published content into a forward draft makes `WorkflowVisibility` report the node as unpublished
while the published pointer still serves it live — confirmed to affect
`Waaseyaa\AI\Vector\EntityEmbeddingListener::onPostSave()`, which de-indexes still-live content on
every forward-draft save. Deferred out of WP-2 (a correct fix flips a precedence a pinned unit test
asserts and touches six consumer packages that each `new WorkflowVisibility()` inline) rather than
rushed; tracked as a WP-2 follow-up. Full write-up: `docs/specs/content-workflow.md` "Visibility
(read side)".

## Install

Ships as part of `waaseyaa/framework` — consumers who require the metapackage (or `core` / `cms` /
`full`) get it transitively. To depend on it directly:

```bash
composer require waaseyaa/workflows
```

`WorkflowServiceProvider` is auto-discovered via `extra.waaseyaa.providers`; it registers the
`workflow` config entity type, binds the engine services (`WorkflowBindingResolver`,
`TransitionService`, `WorkflowStateGuard`), wires the save-path guard, and seeds the default
`editorial` workflow. Requires PHP >= 8.5.

## Key API

```php
// Workflow.php — config entity (states + transitions)
public function addState(WorkflowState $state): static
public function getState(string $id): ?WorkflowState
public function addTransition(WorkflowTransition $transition): static
public function getValidTransitions(string $fromStateId): array        // keyed by transition ID
public function isTransitionAllowed(string $fromStateId, string $toStateId): bool
public function getInitialState(): string
public function permissionFor(WorkflowTransition $transition): string

// WorkflowState.php — readonly value object
public function __construct(string $id, string $label, int $weight = 0, array $metadata = [], bool $published = false, bool $defaultRevision = false)

// WorkflowTransition.php — readonly value object (from: string[], to: string)
public function __construct(string $id, string $label, array $from, string $to, int $weight = 0, string $permission = '')

// Validation\WorkflowValidator.php
public function validate(Workflow $workflow): array   // list<string> violations; [] = valid

// Binding\WorkflowBindingResolver.php
public function resolve(string $entityTypeId, string $bundle): ?Workflow   // null = unbound

// Transition\TransitionService.php — the one enforcement door
public function transition(EntityInterface $entity, string $transitionId, AccountInterface $account): TransitionResult
public function getAvailableTransitions(EntityInterface $entity, AccountInterface $account): array   // WorkflowTransition[]

// Transition\TransitionDeniedException.php — reason is one of unbound/unknown_transition/illegal_edge/permission/group_constraint
public readonly string $reason;

// Listener\WorkflowStateGuard.php — PRE_SAVE subscriber
public function onPreSave(EntityEvent $event): void
```

## Usage

```php
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;

// $service is container-resolved (Waaseyaa\Workflows\Transition\TransitionService::class).
try {
    $result = $service->transition($node, 'publish', $account);
    // $result->toState === 'published'
} catch (TransitionDeniedException $e) {
    // $e->reason: 'unbound' | 'unknown_transition' | 'illegal_edge' | 'permission' | 'group_constraint'
}

// UIs render buttons from the read side only:
foreach ($service->getAvailableTransitions($node, $account) as $transition) {
    // $transition->id, $transition->label
}
```

### Legacy usage (superseded — see above; do not build new code against this)

```php
use Waaseyaa\Workflows\ContentModerationState;
use Waaseyaa\Workflows\ContentModerator;
use Waaseyaa\Workflows\EditorialWorkflowPreset;

$moderator = new ContentModerator();
$moderator->addWorkflow(EditorialWorkflowPreset::create());   // id: 'editorial'

$state = new ContentModerationState(
    entityTypeId: 'node',
    entityId: 1,
    workflowId: 'editorial',
    stateId: 'draft',
);

$state = $moderator->transition($state, 'review');   // draft -> review
$state->stateId;                                     // 'review'

// Disallowed transitions throw \InvalidArgumentException.
$moderator->getAvailableTransitions($state);         // WorkflowTransition[] valid from 'review'
```
