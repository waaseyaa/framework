# waaseyaa/workflows

**Layer 3 — Services**

Content moderation and editorial workflow states for Waaseyaa applications.

This package provides two complementary surfaces. The **generic workflow primitives** model any
state machine as a `Workflow` config entity composed of `WorkflowState` nodes and
`WorkflowTransition` edges; `ContentModerator` drives entities between states while enforcing the
allowed transitions. The **editorial layer** ships an opinionated preset (draft → review →
published → archived, with 6 transitions) plus services that bind transitions to permissions/roles
and decide whether an entity may be rendered. `EditorialVisibilityResolver` returns `allowed` /
`neutral` / `forbidden` `AccessResult`s consumed by SSR rendering based on publish state, the
requesting account's permissions, and preview mode.

## Install

Ships as part of `waaseyaa/framework` — consumers who require the metapackage (or `core` / `cms` /
`full`) get it transitively. To depend on it directly:

```bash
composer require waaseyaa/workflows
```

`WorkflowServiceProvider` is auto-discovered via `extra.waaseyaa.providers`; it registers the
`workflow` config entity type and binds the default `AuthoringRoleMatrix`. Requires PHP >= 8.5.

## Key API

```php
// Workflow.php — config entity (states + transitions)
public function addState(WorkflowState $state): static
public function getState(string $id): ?WorkflowState
public function addTransition(WorkflowTransition $transition): static
public function getValidTransitions(string $fromStateId): array        // keyed by transition ID
public function isTransitionAllowed(string $fromStateId, string $toStateId): bool

// WorkflowState.php — readonly value object
public function __construct(string $id, string $label, int $weight = 0, array $metadata = [])

// WorkflowTransition.php — readonly value object (from: string[], to: string)
public function __construct(string $id, string $label, array $from, string $to, int $weight = 0)

// ContentModerator.php — drives entities between states
public function addWorkflow(Workflow $workflow): void
public function transition(ContentModerationState $currentState, string $toStateId): ContentModerationState
public function getAvailableTransitions(ContentModerationState $currentState): array  // WorkflowTransition[]

// ContentModerationState.php — readonly: (string $entityTypeId, int|string $entityId, string $workflowId, string $stateId)

// EditorialWorkflowPreset.php — factory + legacy-status helpers
public static function create(): Workflow                                       // the 4-state editorial preset
public static function normalizeState(mixed $workflowState, mixed $status): string
public static function statusForState(string $state): int

// EditorialWorkflowService.php — mutate a node through the editorial preset
public function transitionNode(FieldableInterface $node, string $toState, AccountInterface $account): void
public function getAvailableTransitionMetadata(FieldableInterface $node): array
public function currentState(FieldableInterface $node): string

// EditorialTransitionAccessResolver.php — permission/role gating
public function canTransition(string $bundle, string $fromState, string $toState, AccountInterface $account): AccessResult
public function requiredPermission(string $bundle, string $fromState, string $toState): string

// EditorialVisibilityResolver.php — SSR render gate
public function canRender(EntityInterface $entity, AccountInterface $account, bool $previewRequested = false): AccessResult
public function stateForEntity(EntityInterface $entity): string
```

## Usage

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
