# waaseyaa/workflows

**Layer 3 — Services**

Content moderation and editorial workflow states for Waaseyaa applications.

## CW-v1 engine (WP-1, live)

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
- `WorkflowServiceProvider::boot()` wires the guard onto the real dispatcher (Symfony-contracts
  FQCN) and seeds the default `editorial` workflow if absent (log-and-skip on validation failure,
  never boot-crash).

Revision promotion (`default_revision` forward-draft mechanics) is WP-2; group/department
transition constraints are WP-3; API transition endpoints + admin SPA are WP-4.

## Legacy machinery (superseded, removal tracked as WP-5 / #1920)

The classes below predate the CW-v1 engine and are **not wired to any enforcement path** —
`EditorialWorkflowService::transitionNode()` mutates fields in memory only; the caller must save
separately, with no guard proving the save is legitimate. They are kept only until WP-5 deletes them
(never before the engine landed, which it now has):

- `ContentModerator` / `ContentModerationState` — the original state-machine driver, superseded by
  `TransitionService`.
- `EditorialWorkflowPreset` — the preset-in-code editorial definition, superseded by
  `DefaultWorkflows` (data, not code).
- `EditorialWorkflowService` / `EditorialTransitionAccessResolver` / `AuthoringRoleMatrix` — the
  ungated mutate-and-hope-you-saved path and its permission/role lookups, superseded by
  `TransitionService` + `WorkflowStateGuard`.
- `DomainValidationListener` — never subscribed to any dispatcher; dead code kept alive in the
  dead-code gate only by its own unit test.

**Live, unaffected by CW-v1:** `WorkflowVisibility` / `WorkflowVisibilityFilter` /
`EditorialVisibilityResolver` remain the read-side gates (fail-closed per R16 #1915). For
CW-v1-bound entity types they will derive published-semantics from the bound workflow's state
flags instead of assuming `status === 1` — tracked for WP-2.

## Install

Ships as part of `waaseyaa/framework` — consumers who require the metapackage (or `core` / `cms` /
`full`) get it transitively. To depend on it directly:

```bash
composer require waaseyaa/workflows
```

`WorkflowServiceProvider` is auto-discovered via `extra.waaseyaa.providers`; it registers the
`workflow` config entity type, binds the engine services (`WorkflowBindingResolver`,
`TransitionService`, `WorkflowStateGuard`), the default `AuthoringRoleMatrix`, wires the save-path
guard, and seeds the default `editorial` workflow. Requires PHP >= 8.5.

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

// Transition\TransitionDeniedException.php — reason is one of unbound/unknown_transition/illegal_edge/permission
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
    // $e->reason: 'unbound' | 'unknown_transition' | 'illegal_edge' | 'permission'
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
