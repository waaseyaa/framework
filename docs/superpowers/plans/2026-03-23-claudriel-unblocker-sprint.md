# Claudriel Unblocker Sprint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement 4 waaseyaa framework changes (#604, #605, #606, #607) to unblock Claudriel's workflow, AI agent, and MCP features.

**Architecture:** Refactor `EditorialWorkflowStateMachine` into a preset factory on top of the existing `Workflow` config entity. Add `ToolRegistryInterface` + `ToolRegistry` for custom tool registration. Build `AnthropicProvider` with cURL-based HTTP, multi-turn tool loops, and SSE streaming in `AgentExecutor`.

**Tech Stack:** PHP 8.4+, PHPUnit 10.5, cURL for HTTP, SSE for streaming

**Spec:** `docs/superpowers/specs/2026-03-23-claudriel-unblocker-sprint-design.md`

---

## File Map

### #604 — Workflow State Machine Refactor
| Action | File | Responsibility |
|--------|------|----------------|
| Create | `packages/workflows/src/EditorialWorkflowPreset.php` | Factory: creates pre-configured editorial `Workflow` entity |
| Modify | `packages/workflows/src/WorkflowState.php` | Add `metadata` array property |
| Modify | `packages/workflows/src/EditorialWorkflowService.php` | Replace `EditorialWorkflowStateMachine` dep with `Workflow` |
| Modify | `packages/workflows/src/EditorialTransitionAccessResolver.php` | Replace `EditorialWorkflowStateMachine` dep with `Workflow` |
| Delete | `packages/workflows/src/EditorialWorkflowStateMachine.php` | Replaced by preset + Workflow |
| Create | `packages/workflows/tests/Unit/EditorialWorkflowPresetTest.php` | Test factory output |
| Modify | `packages/workflows/tests/Unit/EditorialWorkflowServiceTest.php` | Update to use Workflow |
| Modify | `packages/workflows/tests/Unit/EditorialTransitionAccessResolverTest.php` | Update to use Workflow |
| Delete | `packages/workflows/tests/Unit/EditorialWorkflowStateMachineTest.php` | Replaced by PresetTest |

### #607 — Tool Registry
| Action | File | Responsibility |
|--------|------|----------------|
| Create | `packages/ai-agent/src/ToolRegistryInterface.php` | Contract for tool registration + execution |
| Create | `packages/ai-agent/src/ToolRegistry.php` | Implementation: maps tools to callables |
| Modify | `packages/ai-schema/src/SchemaRegistry.php` | Add `registerEntityTools()` method |
| Modify | `packages/ai-agent/src/McpServer.php` | Switch to `ToolRegistryInterface` |
| Modify | `packages/ai-agent/src/AgentExecutor.php` | Switch to `ToolRegistryInterface` |
| Create | `packages/ai-agent/tests/Unit/ToolRegistryTest.php` | Unit tests for registry |
| Modify | `packages/ai-agent/tests/Unit/McpServerTest.php` | Update constructor |
| Modify | `packages/ai-agent/tests/Unit/AgentExecutorTest.php` | Update constructor |

### #606 — Anthropic Provider
| Action | File | Responsibility |
|--------|------|----------------|
| Create | `packages/ai-agent/src/Provider/ProviderInterface.php` | LLM provider contract |
| Create | `packages/ai-agent/src/Provider/MessageRequest.php` | Request value object |
| Create | `packages/ai-agent/src/Provider/MessageResponse.php` | Response value object |
| Create | `packages/ai-agent/src/Provider/ToolUseBlock.php` | Tool use content block |
| Create | `packages/ai-agent/src/Provider/ToolResultBlock.php` | Tool result content block |
| Create | `packages/ai-agent/src/Provider/AnthropicProvider.php` | cURL-based Anthropic client |
| Create | `packages/ai-agent/src/Provider/RateLimitException.php` | Rate limit error |
| Create | `packages/ai-agent/src/Provider/MaxIterationsException.php` | Tool loop guard |
| Modify | `packages/ai-agent/src/AgentContext.php` | Add `$maxIterations` property |
| Modify | `packages/ai-agent/src/AgentExecutor.php` | Add `executeWithProvider()` |
| Create | `packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php` | Provider unit tests |
| Modify | `packages/ai-agent/tests/Unit/AgentExecutorTest.php` | Test `executeWithProvider()` |

### #605 — Streaming Support
| Action | File | Responsibility |
|--------|------|----------------|
| Create | `packages/ai-agent/src/Provider/StreamingProviderInterface.php` | Streaming contract |
| Create | `packages/ai-agent/src/Provider/StreamChunk.php` | Stream chunk value object |
| Modify | `packages/ai-agent/src/Provider/AnthropicProvider.php` | Implement streaming |
| Modify | `packages/ai-agent/src/AgentExecutor.php` | Add `streamWithProvider()` |
| Modify | `packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php` | Streaming tests |
| Modify | `packages/ai-agent/tests/Unit/AgentExecutorTest.php` | Streaming executor tests |

---

## Task 1: WorkflowState metadata (#604)

**Issue branch:** `feat/604-configurable-workflow-state-machine`

**Files:**
- Modify: `packages/workflows/src/WorkflowState.php`
- Modify: `packages/workflows/src/Workflow.php:46-59` (hydration)
- Modify: `packages/workflows/src/Workflow.php:244-253` (syncStatesToValues)
- Modify: `packages/workflows/src/Workflow.php:276-288` (toConfig)

- [ ] **Step 1: Write the failing test for WorkflowState metadata**

Add to `packages/workflows/tests/Unit/WorkflowStateTest.php`:

```php
public function testMetadataDefaults(): void
{
    $state = new WorkflowState(id: 'draft', label: 'Draft');
    $this->assertSame([], $state->metadata);
}

public function testMetadataIsPreserved(): void
{
    $state = new WorkflowState(
        id: 'published',
        label: 'Published',
        metadata: ['legacy_status' => 1],
    );
    $this->assertSame(['legacy_status' => 1], $state->metadata);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter testMetadataDefaults`
Expected: FAIL — `metadata` property does not exist on `WorkflowState`

- [ ] **Step 3: Add metadata property to WorkflowState**

In `packages/workflows/src/WorkflowState.php`, change the constructor to:

```php
public function __construct(
    public string $id,
    public string $label,
    public int $weight = 0,
    public array $metadata = [],
) {}
```

- [ ] **Step 4: Update Workflow hydration for backward-compatible metadata**

In `packages/workflows/src/Workflow.php`, update the state hydration block (lines 53-58) to include metadata:

```php
$this->states[$stateId] = new WorkflowState(
    id: (string) $stateId,
    label: (string) ($stateData['label'] ?? $stateId),
    weight: (int) ($stateData['weight'] ?? 0),
    metadata: (array) ($stateData['metadata'] ?? []),
);
```

- [ ] **Step 5: Update syncStatesToValues to include metadata**

In `packages/workflows/src/Workflow.php`, update `syncStatesToValues()`:

```php
private function syncStatesToValues(): void
{
    $states = [];
    foreach ($this->states as $state) {
        $entry = [
            'label' => $state->label,
            'weight' => $state->weight,
        ];
        if ($state->metadata !== []) {
            $entry['metadata'] = $state->metadata;
        }
        $states[$state->id] = $entry;
    }
    $this->values['states'] = $states;
}
```

- [ ] **Step 6: Update toConfig to include metadata**

In `packages/workflows/src/Workflow.php`, update the states serialization in `toConfig()`:

```php
$states = [];
foreach ($this->states as $state) {
    $entry = [
        'label' => $state->label,
        'weight' => $state->weight,
    ];
    if ($state->metadata !== []) {
        $entry['metadata'] = $state->metadata;
    }
    $states[$state->id] = $entry;
}
$config['states'] = $states;
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter WorkflowState`
Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add packages/workflows/src/WorkflowState.php packages/workflows/src/Workflow.php packages/workflows/tests/Unit/WorkflowStateTest.php
git commit -m "feat(#604): add metadata property to WorkflowState"
```

---

## Task 2: EditorialWorkflowPreset factory (#604)

**Files:**
- Create: `packages/workflows/src/EditorialWorkflowPreset.php`
- Create: `packages/workflows/tests/Unit/EditorialWorkflowPresetTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/workflows/tests/Unit/EditorialWorkflowPresetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;

#[CoversClass(EditorialWorkflowPreset::class)]
final class EditorialWorkflowPresetTest extends TestCase
{
    public function testCreateReturnsWorkflowWithEditorialStates(): void
    {
        $workflow = EditorialWorkflowPreset::create();

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertSame('editorial', $workflow->id());

        $states = $workflow->getStates();
        $this->assertCount(4, $states);
        $this->assertArrayHasKey('draft', $states);
        $this->assertArrayHasKey('review', $states);
        $this->assertArrayHasKey('published', $states);
        $this->assertArrayHasKey('archived', $states);
    }

    public function testCreateReturnsWorkflowWithEditorialTransitions(): void
    {
        $workflow = EditorialWorkflowPreset::create();

        $transitions = $workflow->getTransitions();
        $this->assertCount(6, $transitions);
        $this->assertArrayHasKey('submit_for_review', $transitions);
        $this->assertArrayHasKey('send_back', $transitions);
        $this->assertArrayHasKey('publish', $transitions);
        $this->assertArrayHasKey('unpublish', $transitions);
        $this->assertArrayHasKey('archive', $transitions);
        $this->assertArrayHasKey('restore', $transitions);
    }

    public function testPublishTransitionGoesFromReviewToPublished(): void
    {
        $workflow = EditorialWorkflowPreset::create();
        $publish = $workflow->getTransition('publish');

        $this->assertNotNull($publish);
        $this->assertSame(['review'], $publish->from);
        $this->assertSame('published', $publish->to);
    }

    public function testPublishedStateCarriesLegacyStatusMetadata(): void
    {
        $workflow = EditorialWorkflowPreset::create();
        $published = $workflow->getState('published');

        $this->assertNotNull($published);
        $this->assertSame(1, $published->metadata['legacy_status']);
    }

    public function testNormalizeStateFallsBackToStatus(): void
    {
        $this->assertSame('published', EditorialWorkflowPreset::normalizeState(null, 1));
        $this->assertSame('draft', EditorialWorkflowPreset::normalizeState(null, 0));
        $this->assertSame('published', EditorialWorkflowPreset::normalizeState('', true));
        $this->assertSame('draft', EditorialWorkflowPreset::normalizeState('', false));
    }

    public function testStatusForState(): void
    {
        $this->assertSame(1, EditorialWorkflowPreset::statusForState('published'));
        $this->assertSame(0, EditorialWorkflowPreset::statusForState('draft'));
        $this->assertSame(0, EditorialWorkflowPreset::statusForState('review'));
        $this->assertSame(0, EditorialWorkflowPreset::statusForState('archived'));
    }

    public function testWorkflowIsTransitionAllowed(): void
    {
        $workflow = EditorialWorkflowPreset::create();

        $this->assertTrue($workflow->isTransitionAllowed('draft', 'review'));
        $this->assertTrue($workflow->isTransitionAllowed('review', 'published'));
        $this->assertFalse($workflow->isTransitionAllowed('draft', 'archived'));
    }

    public function testCustomNonEditorialWorkflow(): void
    {
        // Verify Workflow works with custom states (Claudriel commitment lifecycle)
        $workflow = new Workflow([
            'id' => 'commitment',
            'label' => 'Commitment Lifecycle',
            'states' => [
                'pending' => ['label' => 'Pending'],
                'active' => ['label' => 'Active'],
                'completed' => ['label' => 'Completed'],
                'archived' => ['label' => 'Archived'],
            ],
            'transitions' => [
                'activate' => ['label' => 'Activate', 'from' => ['pending'], 'to' => 'active'],
                'complete' => ['label' => 'Complete', 'from' => ['active'], 'to' => 'completed'],
                'archive' => ['label' => 'Archive', 'from' => ['completed'], 'to' => 'archived'],
                'reopen' => ['label' => 'Re-open', 'from' => ['archived'], 'to' => 'pending'],
            ],
        ]);

        $this->assertTrue($workflow->isTransitionAllowed('pending', 'active'));
        $this->assertFalse($workflow->isTransitionAllowed('pending', 'completed'));
        $this->assertCount(4, $workflow->getStates());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter EditorialWorkflowPresetTest`
Expected: FAIL — class `EditorialWorkflowPreset` does not exist

- [ ] **Step 3: Implement EditorialWorkflowPreset**

Create `packages/workflows/src/EditorialWorkflowPreset.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Factory for the standard editorial workflow preset.
 *
 * Creates a Workflow config entity pre-populated with the 4 editorial
 * states (draft, review, published, archived) and 6 transitions.
 * Also provides editorial-specific utility methods for legacy status
 * mapping and state normalization.
 */
final class EditorialWorkflowPreset
{
    /**
     * Permission patterns keyed by transition ID.
     * Used by EditorialTransitionAccessResolver.
     */
    public const array TRANSITION_PERMISSIONS = [
        'submit_for_review' => 'submit {bundle} for review',
        'send_back' => 'return {bundle} to draft',
        'publish' => 'publish {bundle} content',
        'unpublish' => 'revert {bundle} to draft',
        'archive' => 'archive {bundle} content',
        'restore' => 'restore {bundle} content',
    ];

    public static function create(): Workflow
    {
        return new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
            'states' => [
                'draft' => ['label' => 'Draft', 'weight' => 0, 'metadata' => ['legacy_status' => 0]],
                'review' => ['label' => 'Review', 'weight' => 1, 'metadata' => ['legacy_status' => 0]],
                'published' => ['label' => 'Published', 'weight' => 2, 'metadata' => ['legacy_status' => 1]],
                'archived' => ['label' => 'Archived', 'weight' => 3, 'metadata' => ['legacy_status' => 0]],
            ],
            'transitions' => [
                'submit_for_review' => [
                    'label' => 'Submit for Review',
                    'from' => ['draft'],
                    'to' => 'review',
                ],
                'send_back' => [
                    'label' => 'Send Back to Draft',
                    'from' => ['review'],
                    'to' => 'draft',
                ],
                'publish' => [
                    'label' => 'Publish',
                    'from' => ['review'],
                    'to' => 'published',
                ],
                'unpublish' => [
                    'label' => 'Revert to Draft',
                    'from' => ['published'],
                    'to' => 'draft',
                ],
                'archive' => [
                    'label' => 'Archive',
                    'from' => ['published'],
                    'to' => 'archived',
                ],
                'restore' => [
                    'label' => 'Restore to Draft',
                    'from' => ['archived'],
                    'to' => 'draft',
                ],
            ],
        ]);
    }

    /**
     * Normalize a workflow state from mixed input (legacy status field support).
     */
    public static function normalizeState(mixed $workflowState, mixed $status): string
    {
        if (\is_string($workflowState) && trim($workflowState) !== '') {
            return strtolower(trim($workflowState));
        }

        if (\is_bool($status)) {
            return $status ? 'published' : 'draft';
        }
        if (\is_numeric($status)) {
            return ((int) $status) === 1 ? 'published' : 'draft';
        }
        if (\is_string($status)) {
            $normalized = strtolower(trim($status));
            if (\in_array($normalized, ['1', 'true'], true)) {
                return 'published';
            }
        }

        return 'draft';
    }

    /**
     * Map a workflow state to a legacy integer status field.
     */
    public static function statusForState(string $state): int
    {
        return $state === 'published' ? 1 : 0;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter EditorialWorkflowPresetTest`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/workflows/src/EditorialWorkflowPreset.php packages/workflows/tests/Unit/EditorialWorkflowPresetTest.php
git commit -m "feat(#604): add EditorialWorkflowPreset factory"
```

---

## Task 3: Rewire EditorialTransitionAccessResolver (#604)

**Files:**
- Modify: `packages/workflows/src/EditorialTransitionAccessResolver.php`
- Modify: `packages/workflows/tests/Unit/EditorialTransitionAccessResolverTest.php`

- [ ] **Step 1: Update EditorialTransitionAccessResolver to use Workflow**

Replace the constructor and `transition()` method in `packages/workflows/src/EditorialTransitionAccessResolver.php`:

Old constructor:
```php
public function __construct(
    private readonly EditorialWorkflowStateMachine $stateMachine = new EditorialWorkflowStateMachine(),
) {}
```

New constructor:
```php
public function __construct(
    private readonly Workflow $workflow = new Workflow(),
) {}
```

Replace the `transition()` method:

Old:
```php
public function transition(string $fromState, string $toState): array
{
    return $this->stateMachine->assertTransitionAllowed($fromState, $toState);
}
```

New — returns a structured array matching the old format, built from `Workflow` + `TRANSITION_PERMISSIONS`:

```php
/**
 * @return array{id: string, label: string, from: list<string>, to: string, permission: string}
 */
public function transition(string $fromState, string $toState): array
{
    if (!$this->workflow->hasState($fromState)) {
        throw new \InvalidArgumentException(sprintf('Unknown workflow state: "%s".', $fromState));
    }
    if (!$this->workflow->hasState($toState)) {
        throw new \InvalidArgumentException(sprintf('Unknown workflow state: "%s".', $toState));
    }

    // Find the matching transition in the workflow
    foreach ($this->workflow->getTransitions() as $transition) {
        if ($transition->to === $toState && \in_array($fromState, $transition->from, true)) {
            return [
                'id' => $transition->id,
                'label' => $transition->label,
                'from' => $transition->from,
                'to' => $transition->to,
                'permission' => EditorialWorkflowPreset::TRANSITION_PERMISSIONS[$transition->id] ?? '',
            ];
        }
    }

    throw new \RuntimeException(sprintf(
        'Invalid workflow transition: %s -> %s.',
        $fromState,
        $toState,
    ));
}
```

Update the `canTransition()` method — replace `$this->stateMachine->assertTransitionAllowed(...)` call:

Old:
```php
try {
    $transition = $this->stateMachine->assertTransitionAllowed($fromState, $toState);
} catch (\InvalidArgumentException | \RuntimeException $exception) {
```

New:
```php
try {
    $transition = $this->transition($fromState, $toState);
} catch (\InvalidArgumentException | \RuntimeException $exception) {
```

Update the `use` import — remove `EditorialWorkflowStateMachine`, add `EditorialWorkflowPreset` and `Workflow`:
```php
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;
```

- [ ] **Step 2: Update EditorialTransitionAccessResolverTest**

In `packages/workflows/tests/Unit/EditorialTransitionAccessResolverTest.php`:

Update imports — replace:
```php
use Waaseyaa\Workflows\EditorialWorkflowStateMachine;
```
with:
```php
use Waaseyaa\Workflows\EditorialWorkflowPreset;
```

Update any test that creates a resolver. The default constructor `new EditorialTransitionAccessResolver()` will no longer work (it expects a `Workflow`). Change to:
```php
$resolver = new EditorialTransitionAccessResolver(EditorialWorkflowPreset::create());
```

Update the `#[CoversClass]` attribute if it references `EditorialWorkflowStateMachine`.

- [ ] **Step 3: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter EditorialTransitionAccessResolver`
Expected: All PASS

- [ ] **Step 4: Commit**

```bash
git add packages/workflows/src/EditorialTransitionAccessResolver.php packages/workflows/tests/Unit/EditorialTransitionAccessResolverTest.php
git commit -m "feat(#604): rewire EditorialTransitionAccessResolver to use Workflow"
```

---

## Task 4: Rewire EditorialWorkflowService (#604)

**Files:**
- Modify: `packages/workflows/src/EditorialWorkflowService.php`
- Modify: `packages/workflows/tests/Unit/EditorialWorkflowServiceTest.php`
- Delete: `packages/workflows/src/EditorialWorkflowStateMachine.php`
- Delete: `packages/workflows/tests/Unit/EditorialWorkflowStateMachineTest.php`

- [ ] **Step 1: Update EditorialWorkflowService to use Workflow**

In `packages/workflows/src/EditorialWorkflowService.php`:

Replace imports:
```php
// Remove:
// (no explicit import for EditorialWorkflowStateMachine — it's in same namespace)
// Add:
use Waaseyaa\Workflows\EditorialWorkflowPreset;
```

Replace constructor:

Old:
```php
public function __construct(
    private readonly array $coreBundles,
    private readonly EditorialWorkflowStateMachine $stateMachine = new EditorialWorkflowStateMachine(),
    ?EditorialTransitionAccessResolver $transitionAccessResolver = null,
    private readonly ?\Closure $clock = null,
) {
    $this->transitionAccessResolver = $transitionAccessResolver ?? new EditorialTransitionAccessResolver($this->stateMachine);
}
```

New:
```php
public function __construct(
    private readonly array $coreBundles,
    private readonly Workflow $workflow = new Workflow(),
    ?EditorialTransitionAccessResolver $transitionAccessResolver = null,
    private readonly ?\Closure $clock = null,
) {
    $this->transitionAccessResolver = $transitionAccessResolver ?? new EditorialTransitionAccessResolver($this->workflow);
}
```

In `transitionNode()`, replace `$this->stateMachine->statusForState($to)`:
```php
$node->set('status', EditorialWorkflowPreset::statusForState($to));
```

In `getAvailableTransitionMetadata()`, replace `$this->stateMachine->availableTransitions($from)`:
```php
$validTransitions = $this->workflow->getValidTransitions($from);
$metadata = [];
foreach ($validTransitions as $transition) {
    $permission = EditorialWorkflowPreset::TRANSITION_PERMISSIONS[$transition->id] ?? '';
    $metadata[] = [
        'id' => $transition->id,
        'label' => $transition->label,
        'from' => $transition->from,
        'to' => $transition->to,
        'required_permission' => $this->transitionAccessResolver->requiredPermission($bundle, $from, $transition->to),
    ];
}

return $metadata;
```

In `stateFromNode()`, replace `$this->stateMachine->normalizeState(...)`:
```php
private function stateFromNode(FieldableInterface $node): string
{
    return EditorialWorkflowPreset::normalizeState(
        workflowState: $node->get('workflow_state'),
        status: $node->get('status'),
    );
}
```

- [ ] **Step 2: Update EditorialWorkflowServiceTest**

In `packages/workflows/tests/Unit/EditorialWorkflowServiceTest.php`:

Update any construction of `EditorialWorkflowService` to pass the preset workflow:
```php
$workflow = EditorialWorkflowPreset::create();
$service = new EditorialWorkflowService(
    coreBundles: ['article'],
    workflow: $workflow,
    clock: fn () => 1700000000,
);
```

Update imports accordingly.

- [ ] **Step 3: Run all workflow tests**

Run: `./vendor/bin/phpunit packages/workflows/tests/`
Expected: All PASS (EditorialWorkflowStateMachineTest will still pass since the class exists)

- [ ] **Step 4: Delete the old EditorialWorkflowStateMachine and its test**

```bash
git rm packages/workflows/src/EditorialWorkflowStateMachine.php
git rm packages/workflows/tests/Unit/EditorialWorkflowStateMachineTest.php
```

- [ ] **Step 5: Run all workflow tests again to verify nothing depends on the deleted class**

Run: `./vendor/bin/phpunit packages/workflows/tests/`
Expected: All PASS

- [ ] **Step 6: Run full test suite to check for any other breakage**

Run: `./vendor/bin/phpunit`
Expected: All PASS. If anything imports `EditorialWorkflowStateMachine`, fix it.

- [ ] **Step 7: Commit**

```bash
git add -A packages/workflows/
git commit -m "feat(#604): replace EditorialWorkflowStateMachine with preset + Workflow

EditorialWorkflowService and EditorialTransitionAccessResolver now use
the Workflow config entity directly. EditorialWorkflowPreset::create()
provides the editorial state/transition configuration.

Closes #604"
```

---

## Task 5: ToolRegistryInterface and ToolRegistry (#607)

**Issue branch:** `feat/607-custom-tool-registry`

**Files:**
- Create: `packages/ai-agent/src/ToolRegistryInterface.php`
- Create: `packages/ai-agent/src/ToolRegistry.php`
- Create: `packages/ai-agent/tests/Unit/ToolRegistryTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/ai-agent/tests/Unit/ToolRegistryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\ToolRegistry;
use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;

#[CoversClass(ToolRegistry::class)]
final class ToolRegistryTest extends TestCase
{
    public function testRegisterAndRetrieveTool(): void
    {
        $registry = new ToolRegistry();
        $tool = new McpToolDefinition(
            name: 'gmail_send',
            description: 'Send an email',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $registry->register($tool, fn (array $args) => ['content' => [['type' => 'text', 'text' => 'sent']]]);

        $this->assertTrue($registry->has('gmail_send'));
        $this->assertSame($tool, $registry->getTool('gmail_send'));
    }

    public function testGetToolsReturnsAllRegistered(): void
    {
        $registry = new ToolRegistry();
        $tool1 = new McpToolDefinition(name: 'tool_a', description: 'A', inputSchema: []);
        $tool2 = new McpToolDefinition(name: 'tool_b', description: 'B', inputSchema: []);

        $registry->register($tool1, fn (array $args) => []);
        $registry->register($tool2, fn (array $args) => []);

        $tools = $registry->getTools();
        $this->assertCount(2, $tools);
    }

    public function testExecuteDelegatesToCallable(): void
    {
        $registry = new ToolRegistry();
        $tool = new McpToolDefinition(name: 'echo', description: 'Echo', inputSchema: []);

        $registry->register($tool, fn (array $args) => [
            'content' => [['type' => 'text', 'text' => \json_encode($args, \JSON_THROW_ON_ERROR)]],
        ]);

        $result = $registry->execute('echo', ['message' => 'hello']);
        $this->assertSame('{"message":"hello"}', $result['content'][0]['text']);
    }

    public function testExecuteThrowsForUnknownTool(): void
    {
        $registry = new ToolRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: nonexistent');

        $registry->execute('nonexistent', []);
    }

    public function testExecuteWrapsExceptionsInMcpErrorFormat(): void
    {
        $registry = new ToolRegistry();
        $tool = new McpToolDefinition(name: 'fail', description: 'Fails', inputSchema: []);

        $registry->register($tool, fn (array $args) => throw new \RuntimeException('boom'));

        $result = $registry->execute('fail', []);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('boom', $result['content'][0]['text']);
    }

    public function testHasReturnsFalseForUnregistered(): void
    {
        $registry = new ToolRegistry();
        $this->assertFalse($registry->has('unknown'));
    }

    public function testGetToolReturnsNullForUnregistered(): void
    {
        $registry = new ToolRegistry();
        $this->assertNull($registry->getTool('unknown'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter ToolRegistryTest`
Expected: FAIL — classes do not exist

- [ ] **Step 3: Implement ToolRegistryInterface**

Create `packages/ai-agent/src/ToolRegistryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;

/**
 * Registry for MCP tool definitions and their executors.
 *
 * Holds both auto-generated entity CRUD tools and custom
 * application tools in a unified registry.
 */
interface ToolRegistryInterface
{
    /**
     * Register a tool with its executor callable.
     *
     * @param callable(array<string, mixed>): array $executor
     */
    public function register(McpToolDefinition $tool, callable $executor): void;

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool;

    /**
     * Get all registered tool definitions.
     *
     * @return McpToolDefinition[]
     */
    public function getTools(): array;

    /**
     * Get a tool definition by name.
     */
    public function getTool(string $name): ?McpToolDefinition;

    /**
     * Execute a tool by name.
     *
     * @param array<string, mixed> $arguments
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function execute(string $name, array $arguments): array;
}
```

- [ ] **Step 4: Implement ToolRegistry**

Create `packages/ai-agent/src/ToolRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;

/**
 * In-memory tool registry mapping tool names to definitions and executors.
 */
final class ToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, McpToolDefinition> */
    private array $tools = [];

    /** @var array<string, callable> */
    private array $executors = [];

    public function register(McpToolDefinition $tool, callable $executor): void
    {
        $this->tools[$tool->name] = $tool;
        $this->executors[$tool->name] = $executor;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function getTools(): array
    {
        return array_values($this->tools);
    }

    public function getTool(string $name): ?McpToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    public function execute(string $name, array $arguments): array
    {
        if (!isset($this->executors[$name])) {
            throw new \InvalidArgumentException("Unknown tool: {$name}");
        }

        try {
            return ($this->executors[$name])($arguments);
        } catch (\Throwable $e) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => \json_encode(['error' => $e->getMessage()], \JSON_THROW_ON_ERROR),
                    ],
                ],
                'isError' => true,
            ];
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter ToolRegistryTest`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add packages/ai-agent/src/ToolRegistryInterface.php packages/ai-agent/src/ToolRegistry.php packages/ai-agent/tests/Unit/ToolRegistryTest.php
git commit -m "feat(#607): add ToolRegistryInterface and ToolRegistry"
```

---

## Task 6: SchemaRegistry.registerEntityTools() (#607)

**Files:**
- Modify: `packages/ai-schema/src/SchemaRegistry.php`

- [ ] **Step 1: Write the failing test**

Add a test to the SchemaRegistry test file (or create one). The test verifies `registerEntityTools()` populates a `ToolRegistry`:

```php
public function testRegisterEntityToolsPopulatesRegistry(): void
{
    // Create a SchemaRegistry with a mock generator that returns one tool
    $toolDef = new McpToolDefinition(name: 'create_node', description: 'Create node', inputSchema: []);
    $toolGenerator = $this->createMock(McpToolGenerator::class);
    $toolGenerator->method('generateAll')->willReturn([$toolDef]);

    $schemaGenerator = $this->createMock(EntityJsonSchemaGenerator::class);
    $schemaRegistry = new SchemaRegistry($schemaGenerator, $toolGenerator);

    $executor = $this->createMock(McpToolExecutor::class);
    $registry = new ToolRegistry();

    $schemaRegistry->registerEntityTools($registry, $executor);

    $this->assertTrue($registry->has('create_node'));
    $this->assertCount(1, $registry->getTools());
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — `registerEntityTools()` method does not exist

- [ ] **Step 3: Add registerEntityTools() to SchemaRegistry**

In `packages/ai-schema/src/SchemaRegistry.php`, add import and method:

```php
use Waaseyaa\AI\Agent\ToolRegistryInterface;
```

Add method:
```php
/**
 * Register all auto-generated entity CRUD tools into a tool registry.
 */
public function registerEntityTools(ToolRegistryInterface $registry, Mcp\McpToolExecutor $executor): void
{
    foreach ($this->getTools() as $tool) {
        $toolName = $tool->name;
        $registry->register(
            $tool,
            static fn (array $arguments) => $executor->execute($toolName, $arguments),
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter registerEntityTools`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/ai-schema/src/SchemaRegistry.php packages/ai-schema/tests/
git commit -m "feat(#607): add SchemaRegistry::registerEntityTools()"
```

---

## Task 7: Rewire McpServer and AgentExecutor to ToolRegistryInterface (#607)

**Files:**
- Modify: `packages/ai-agent/src/McpServer.php`
- Modify: `packages/ai-agent/src/AgentExecutor.php`
- Modify: `packages/ai-agent/tests/Unit/McpServerTest.php`
- Modify: `packages/ai-agent/tests/Unit/AgentExecutorTest.php`

- [ ] **Step 1: Update McpServer to use ToolRegistryInterface**

In `packages/ai-agent/src/McpServer.php`:

Replace imports:
```php
// Remove:
use Waaseyaa\AI\Schema\Mcp\McpToolExecutor;
use Waaseyaa\AI\Schema\SchemaRegistry;
```

Replace constructor and methods:

```php
final class McpServer
{
    public function __construct(
        private readonly ToolRegistryInterface $registry,
    ) {}

    /**
     * @return array{tools: array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>}
     */
    public function listTools(): array
    {
        $tools = [];
        foreach ($this->registry->getTools() as $tool) {
            $tools[] = $tool->toArray();
        }
        return ['tools' => $tools];
    }

    /**
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        if (!$this->registry->has($name)) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => \json_encode(
                            ['error' => "Unknown tool: {$name}"],
                            \JSON_THROW_ON_ERROR,
                        ),
                    ],
                ],
                'isError' => true,
            ];
        }

        return $this->registry->execute($name, $arguments);
    }
}
```

- [ ] **Step 2: Update AgentExecutor to use ToolRegistryInterface**

In `packages/ai-agent/src/AgentExecutor.php`:

Replace import:
```php
// Remove:
use Waaseyaa\AI\Schema\Mcp\McpToolExecutor;
```

Replace constructor:
```php
public function __construct(
    private readonly ToolRegistryInterface $toolRegistry,
) {}
```

In `executeTool()`, replace `$this->toolExecutor->execute(...)`:
```php
$result = $this->toolRegistry->execute($toolName, $arguments);
```

- [ ] **Step 3: Update McpServerTest**

In `packages/ai-agent/tests/Unit/McpServerTest.php`, update constructor calls to pass a `ToolRegistry` instead of `SchemaRegistry` + `McpToolExecutor`. Register tools on the `ToolRegistry` to match the test expectations.

- [ ] **Step 4: Update AgentExecutorTest**

In `packages/ai-agent/tests/Unit/AgentExecutorTest.php`, update constructor to pass a `ToolRegistry`. For tool execution tests, register the tools on the registry instead of using `McpToolExecutor` directly.

- [ ] **Step 5: Run all ai-agent tests**

Run: `./vendor/bin/phpunit packages/ai-agent/tests/`
Expected: All PASS

- [ ] **Step 6: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add packages/ai-agent/src/McpServer.php packages/ai-agent/src/AgentExecutor.php packages/ai-agent/tests/
git commit -m "feat(#607): switch McpServer and AgentExecutor to ToolRegistryInterface

Closes #607"
```

---

## Task 8: Provider value objects (#606)

**Issue branch:** `feat/606-anthropic-provider`

**Files:**
- Create: `packages/ai-agent/src/Provider/ProviderInterface.php`
- Create: `packages/ai-agent/src/Provider/MessageRequest.php`
- Create: `packages/ai-agent/src/Provider/MessageResponse.php`
- Create: `packages/ai-agent/src/Provider/ToolUseBlock.php`
- Create: `packages/ai-agent/src/Provider/ToolResultBlock.php`
- Create: `packages/ai-agent/src/Provider/RateLimitException.php`
- Create: `packages/ai-agent/src/Provider/MaxIterationsException.php`
- Modify: `packages/ai-agent/src/AgentContext.php`

- [ ] **Step 1: Write tests for value objects**

Create `packages/ai-agent/tests/Unit/Provider/MessageRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\MessageRequest;

#[CoversClass(MessageRequest::class)]
final class MessageRequestTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hello']],
        );

        $this->assertSame([['role' => 'user', 'content' => 'Hello']], $request->messages);
        $this->assertNull($request->system);
        $this->assertSame([], $request->tools);
        $this->assertSame(4096, $request->maxTokens);
    }

    public function testToArray(): void
    {
        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            system: 'You are helpful.',
            maxTokens: 1024,
        );

        $array = $request->toArray();
        $this->assertSame('You are helpful.', $array['system']);
        $this->assertSame(1024, $array['max_tokens']);
        $this->assertArrayNotHasKey('tools', $array);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter MessageRequestTest`
Expected: FAIL — class does not exist

- [ ] **Step 3: Create ProviderInterface**

Create `packages/ai-agent/src/Provider/ProviderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

interface ProviderInterface
{
    public function sendMessage(MessageRequest $request): MessageResponse;
}
```

- [ ] **Step 4: Create ToolUseBlock**

Create `packages/ai-agent/src/Provider/ToolUseBlock.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class ToolUseBlock
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $input,
    ) {}
}
```

- [ ] **Step 5: Create ToolResultBlock**

Create `packages/ai-agent/src/Provider/ToolResultBlock.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class ToolResultBlock
{
    public function __construct(
        public string $toolUseId,
        public string $content,
        public bool $isError = false,
    ) {}
}
```

- [ ] **Step 6: Create MessageRequest**

Create `packages/ai-agent/src/Provider/MessageRequest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class MessageRequest
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $messages,
        public ?string $system = null,
        public array $tools = [],
        public int $maxTokens = 4096,
        public array $metadata = [],
    ) {}

    /**
     * Serialize to Anthropic API request format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'messages' => $this->messages,
            'max_tokens' => $this->maxTokens,
        ];

        if ($this->system !== null) {
            $data['system'] = $this->system;
        }

        if ($this->tools !== []) {
            $data['tools'] = $this->tools;
        }

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }
}
```

- [ ] **Step 7: Create MessageResponse**

Create `packages/ai-agent/src/Provider/MessageResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class MessageResponse
{
    /**
     * @param array<int, array<string, mixed>> $content Content blocks (text, tool_use)
     * @param array{input_tokens: int, output_tokens: int, cache_creation_input_tokens?: int, cache_read_input_tokens?: int} $usage
     */
    public function __construct(
        public array $content,
        public string $stopReason,
        public array $usage = [],
    ) {}

    /**
     * Extract text content from the response.
     */
    public function getText(): string
    {
        $text = '';
        foreach ($this->content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        return $text;
    }

    /**
     * Extract tool use blocks from the response.
     *
     * @return ToolUseBlock[]
     */
    public function getToolUseBlocks(): array
    {
        $blocks = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $blocks[] = new ToolUseBlock(
                    id: $block['id'],
                    name: $block['name'],
                    input: $block['input'] ?? [],
                );
            }
        }
        return $blocks;
    }
}
```

- [ ] **Step 8: Create RateLimitException and MaxIterationsException**

Create `packages/ai-agent/src/Provider/RateLimitException.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final class RateLimitException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message = '',
    ) {
        parent::__construct($message ?: "Rate limited. Retry after {$retryAfterSeconds} seconds.");
    }
}
```

Create `packages/ai-agent/src/Provider/MaxIterationsException.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final class MaxIterationsException extends \RuntimeException
{
    public function __construct(int $maxIterations)
    {
        parent::__construct("Agent tool loop exceeded maximum iterations ({$maxIterations}).");
    }
}
```

- [ ] **Step 9: Add maxIterations to AgentContext**

In `packages/ai-agent/src/AgentContext.php`, add the property to the constructor. The class MUST remain `final readonly class`:

```php
final readonly class AgentContext
{
    public function __construct(
        public AccountInterface $account,
        public array $parameters = [],
        public bool $dryRun = false,
        public int $maxIterations = 25,
    ) {}
}
```

- [ ] **Step 10: Run tests**

Run: `./vendor/bin/phpunit --filter MessageRequestTest`
Expected: All PASS

- [ ] **Step 11: Commit**

```bash
git add packages/ai-agent/src/Provider/ packages/ai-agent/src/AgentContext.php packages/ai-agent/tests/Unit/Provider/
git commit -m "feat(#606): add provider value objects and interfaces"
```

---

## Task 9: AnthropicProvider (#606)

**Files:**
- Create: `packages/ai-agent/src/Provider/AnthropicProvider.php`
- Create: `packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php`

- [ ] **Step 1: Write test for request building (no HTTP call)**

Create `packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\AnthropicProvider;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;

#[CoversClass(AnthropicProvider::class)]
final class AnthropicProviderTest extends TestCase
{
    public function testBuildRequestBodyAppliesModel(): void
    {
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-20250514',
        );

        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hello']],
            maxTokens: 1024,
        );

        $body = $provider->buildRequestBody($request);

        $this->assertSame('claude-sonnet-4-20250514', $body['model']);
        $this->assertSame(1024, $body['max_tokens']);
        $this->assertCount(1, $body['messages']);
    }

    public function testBuildRequestBodyAppliesCacheControlToSystem(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            system: 'You are a helpful assistant.',
        );

        $body = $provider->buildRequestBody($request);

        // System prompt should have cache_control
        $this->assertIsArray($body['system']);
        $this->assertSame('ephemeral', $body['system'][0]['cache_control']['type']);
    }

    public function testBuildRequestBodyAppliesCacheControlToLastTool(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            tools: [
                ['name' => 'tool_a', 'description' => 'A', 'input_schema' => []],
                ['name' => 'tool_b', 'description' => 'B', 'input_schema' => []],
            ],
        );

        $body = $provider->buildRequestBody($request);

        // Only last tool gets cache_control
        $this->assertArrayNotHasKey('cache_control', $body['tools'][0]);
        $this->assertSame('ephemeral', $body['tools'][1]['cache_control']['type']);
    }

    public function testParseResponseCreatesMessageResponse(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $apiResponse = [
            'content' => [
                ['type' => 'text', 'text' => 'Hello!'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ];

        $response = $provider->parseResponse($apiResponse);

        $this->assertInstanceOf(MessageResponse::class, $response);
        $this->assertSame('Hello!', $response->getText());
        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame(10, $response->usage['input_tokens']);
    }

    public function testParseResponseExtractsToolUseBlocks(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $apiResponse = [
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'read_node', 'input' => ['id' => '5']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 15],
        ];

        $response = $provider->parseResponse($apiResponse);

        $this->assertSame('tool_use', $response->stopReason);
        $toolBlocks = $response->getToolUseBlocks();
        $this->assertCount(1, $toolBlocks);
        $this->assertSame('read_node', $toolBlocks[0]->name);
        $this->assertSame(['id' => '5'], $toolBlocks[0]->input);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter AnthropicProviderTest`
Expected: FAIL — class does not exist

- [ ] **Step 3: Implement AnthropicProvider**

Create `packages/ai-agent/src/Provider/AnthropicProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

/**
 * Anthropic Messages API provider using cURL.
 */
final class AnthropicProvider implements ProviderInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';
    private const string DEFAULT_MODEL = 'claude-sonnet-4-20250514';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {}

    public function sendMessage(MessageRequest $request): MessageResponse
    {
        $body = $this->buildRequestBody($request);
        $responseData = $this->httpPost(self::API_URL, $body);

        return $this->parseResponse($responseData);
    }

    /**
     * Build the API request body with prompt caching applied.
     *
     * @return array<string, mixed>
     */
    public function buildRequestBody(MessageRequest $request): array
    {
        $body = $request->toArray();
        $body['model'] = $this->model;

        // Apply cache_control to system prompt
        if (isset($body['system']) && \is_string($body['system'])) {
            $body['system'] = [
                [
                    'type' => 'text',
                    'text' => $body['system'],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        }

        // Apply cache_control to last tool definition
        if (isset($body['tools']) && \is_array($body['tools']) && $body['tools'] !== []) {
            $lastIndex = \count($body['tools']) - 1;
            $body['tools'][$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $body;
    }

    /**
     * Parse an API response array into a MessageResponse.
     *
     * @param array<string, mixed> $data
     */
    public function parseResponse(array $data): MessageResponse
    {
        return new MessageResponse(
            content: $data['content'] ?? [],
            stopReason: $data['stop_reason'] ?? 'end_turn',
            usage: $data['usage'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function httpPost(string $url, array $body): array
    {
        $jsonBody = \json_encode($body, \JSON_THROW_ON_ERROR);

        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL.');
        }

        \curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $jsonBody,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            \CURLOPT_TIMEOUT => 120,
        ]);

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $error = \curl_error($ch);
            \curl_close($ch);
            throw new \RuntimeException("cURL error: {$error}");
        }

        \curl_close($ch);

        if (!\is_string($responseBody)) {
            throw new \RuntimeException('Unexpected cURL response type.');
        }

        /** @var array<string, mixed> $data */
        $data = \json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);

        if ($httpCode === 429) {
            $retryAfter = (int) ($data['error']['retry_after'] ?? 60);
            throw new RateLimitException($retryAfter, $data['error']['message'] ?? 'Rate limited');
        }

        if ($httpCode >= 400) {
            $errorMessage = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Anthropic API error: {$errorMessage}");
        }

        return $data;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit --filter AnthropicProviderTest`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/ai-agent/src/Provider/AnthropicProvider.php packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php
git commit -m "feat(#606): add AnthropicProvider with cURL client"
```

---

## Task 10: AgentExecutor.executeWithProvider() (#606)

**Files:**
- Modify: `packages/ai-agent/src/AgentExecutor.php`
- Modify: `packages/ai-agent/tests/Unit/AgentExecutorTest.php`

- [ ] **Step 1: Write the failing test**

Add to `packages/ai-agent/tests/Unit/AgentExecutorTest.php`:

```php
public function testExecuteWithProviderSingleTurnNoTools(): void
{
    $registry = new ToolRegistry();
    $executor = new AgentExecutor($registry);

    // Mock provider that returns a simple text response
    $provider = new class implements ProviderInterface {
        public function sendMessage(MessageRequest $request): MessageResponse
        {
            return new MessageResponse(
                content: [['type' => 'text', 'text' => 'Hello from Claude']],
                stopReason: 'end_turn',
                usage: ['input_tokens' => 10, 'output_tokens' => 5],
            );
        }
    };

    $agent = new TestAgent(AgentResult::success('prepared'));
    $context = new AgentContext(account: new TestAccount());

    $result = $executor->executeWithProvider($agent, $context, $provider);

    $this->assertTrue($result->success);
    $this->assertStringContainsString('Hello from Claude', $result->message);
}

public function testExecuteWithProviderMultiTurnToolLoop(): void
{
    $registry = new ToolRegistry();
    $registry->register(
        new McpToolDefinition(name: 'read_node', description: 'Read', inputSchema: []),
        fn (array $args) => [
            'content' => [['type' => 'text', 'text' => '{"id": 1, "title": "Found"}']],
        ],
    );

    $executor = new AgentExecutor($registry);

    // Provider that first requests tool_use, then returns end_turn
    $callCount = 0;
    $provider = new class ($callCount) implements ProviderInterface {
        public function __construct(private int &$callCount) {}

        public function sendMessage(MessageRequest $request): MessageResponse
        {
            $this->callCount++;
            if ($this->callCount === 1) {
                return new MessageResponse(
                    content: [
                        ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'read_node', 'input' => ['id' => '1']],
                    ],
                    stopReason: 'tool_use',
                );
            }
            return new MessageResponse(
                content: [['type' => 'text', 'text' => 'I found the node.']],
                stopReason: 'end_turn',
            );
        }
    };

    $agent = new TestAgent(AgentResult::success('prepared'));
    $context = new AgentContext(account: new TestAccount());

    $result = $executor->executeWithProvider($agent, $context, $provider);

    $this->assertTrue($result->success);
    $this->assertSame(2, $callCount);
}

public function testExecuteWithProviderRespectsMaxIterations(): void
{
    $registry = new ToolRegistry();
    $registry->register(
        new McpToolDefinition(name: 'loop_tool', description: 'Loops', inputSchema: []),
        fn (array $args) => ['content' => [['type' => 'text', 'text' => 'ok']]],
    );

    $executor = new AgentExecutor($registry);

    // Provider that always returns tool_use
    $provider = new class implements ProviderInterface {
        public function sendMessage(MessageRequest $request): MessageResponse
        {
            return new MessageResponse(
                content: [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'loop_tool', 'input' => []]],
                stopReason: 'tool_use',
            );
        }
    };

    $agent = new TestAgent(AgentResult::success('prepared'));
    $context = new AgentContext(account: new TestAccount(), maxIterations: 3);

    $this->expectException(MaxIterationsException::class);

    $executor->executeWithProvider($agent, $context, $provider);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter testExecuteWithProvider`
Expected: FAIL — method does not exist

- [ ] **Step 3: Implement executeWithProvider()**

Add to `packages/ai-agent/src/AgentExecutor.php`:

```php
use Waaseyaa\AI\Agent\Provider\MaxIterationsException;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\ToolResultBlock;

/**
 * Execute an agent with an LLM provider, handling multi-turn tool loops.
 */
public function executeWithProvider(
    AgentInterface $agent,
    AgentContext $context,
    ProviderInterface $provider,
): AgentResult {
    $agentId = $this->getAgentId($agent);
    $accountId = (int) $context->account->id();

    // Let the agent prepare the initial request
    $agentResult = $agent->execute($context);

    $messages = $context->parameters['messages'] ?? [
        ['role' => 'user', 'content' => $agentResult->message],
    ];
    $system = $context->parameters['system'] ?? null;
    $tools = $this->buildToolDefinitions();

    $iteration = 0;

    while (true) {
        $iteration++;
        if ($iteration > $context->maxIterations) {
            throw new MaxIterationsException($context->maxIterations);
        }

        $request = new MessageRequest(
            messages: $messages,
            system: $system,
            tools: $tools,
            maxTokens: (int) ($context->parameters['max_tokens'] ?? 4096),
        );

        $response = $provider->sendMessage($request);

        // Append assistant response to conversation
        $messages[] = ['role' => 'assistant', 'content' => $response->content];

        if ($response->stopReason !== 'tool_use') {
            break;
        }

        // Execute tool calls
        $toolResults = [];
        foreach ($response->getToolUseBlocks() as $toolUseBlock) {
            $toolResult = $this->toolRegistry->execute($toolUseBlock->name, $toolUseBlock->input);
            $isError = $toolResult['isError'] ?? false;
            $resultText = $toolResult['content'][0]['text'] ?? '';

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'tool_call',
                success: !$isError,
                message: "Tool call: {$toolUseBlock->name}",
                data: ['tool' => $toolUseBlock->name, 'arguments' => $toolUseBlock->input],
                timestamp: \time(),
            );

            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolUseBlock->id,
                'content' => $resultText,
                'is_error' => $isError,
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }

    $finalText = $response->getText();

    $result = AgentResult::success(
        message: $finalText,
        data: ['usage' => $response->usage, 'iterations' => $iteration],
    );

    $this->auditLog[] = new AgentAuditLog(
        agentId: $agentId,
        accountId: $accountId,
        action: 'execute_with_provider',
        success: true,
        message: $finalText,
        data: $result->data,
        timestamp: \time(),
    );

    return $result;
}

/**
 * Build tool definitions array from registry for the LLM.
 *
 * @return array<int, array<string, mixed>>
 */
private function buildToolDefinitions(): array
{
    $tools = [];
    foreach ($this->toolRegistry->getTools() as $tool) {
        $tools[] = [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->inputSchema,
        ];
    }
    return $tools;
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit --filter testExecuteWithProvider`
Expected: All PASS

- [ ] **Step 5: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add packages/ai-agent/src/AgentExecutor.php packages/ai-agent/tests/Unit/AgentExecutorTest.php
git commit -m "feat(#606): add AgentExecutor::executeWithProvider() with multi-turn tool loop

Closes #606"
```

---

## Task 11: StreamingProviderInterface and StreamChunk (#605)

**Issue branch:** `feat/605-streaming-support`

**Files:**
- Create: `packages/ai-agent/src/Provider/StreamingProviderInterface.php`
- Create: `packages/ai-agent/src/Provider/StreamChunk.php`

- [ ] **Step 1: Write test for StreamChunk**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\StreamChunk;

#[CoversClass(StreamChunk::class)]
final class StreamChunkTest extends TestCase
{
    public function testTextDelta(): void
    {
        $chunk = new StreamChunk(type: 'text_delta', text: 'Hello');
        $this->assertSame('text_delta', $chunk->type);
        $this->assertSame('Hello', $chunk->text);
        $this->assertNull($chunk->toolUse);
    }

    public function testToolUseChunk(): void
    {
        $toolUse = new \Waaseyaa\AI\Agent\Provider\ToolUseBlock(id: 'tu_1', name: 'read', input: []);
        $chunk = new StreamChunk(type: 'tool_use_start', toolUse: $toolUse);
        $this->assertSame('tool_use_start', $chunk->type);
        $this->assertNotNull($chunk->toolUse);
        $this->assertSame('read', $chunk->toolUse->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter StreamChunkTest`
Expected: FAIL

- [ ] **Step 3: Create StreamChunk**

Create `packages/ai-agent/src/Provider/StreamChunk.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class StreamChunk
{
    public function __construct(
        public string $type,
        public string $text = '',
        public ?ToolUseBlock $toolUse = null,
    ) {}
}
```

- [ ] **Step 4: Create StreamingProviderInterface**

Create `packages/ai-agent/src/Provider/StreamingProviderInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

interface StreamingProviderInterface extends ProviderInterface
{
    /**
     * Stream a message, calling $onChunk for each partial result.
     *
     * Returns the complete MessageResponse after the stream ends.
     *
     * @param callable(StreamChunk): void $onChunk
     */
    public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse;
}
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --filter StreamChunkTest`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add packages/ai-agent/src/Provider/StreamingProviderInterface.php packages/ai-agent/src/Provider/StreamChunk.php packages/ai-agent/tests/Unit/Provider/StreamChunkTest.php
git commit -m "feat(#605): add StreamingProviderInterface and StreamChunk"
```

---

## Task 12: AnthropicProvider streaming implementation (#605)

**Files:**
- Modify: `packages/ai-agent/src/Provider/AnthropicProvider.php`
- Modify: `packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php`

- [ ] **Step 1: Write test for SSE line parsing**

Add to `packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php`:

```php
public function testParseSseLineExtractsTextDelta(): void
{
    $provider = new AnthropicProvider(apiKey: 'test-key');

    $chunks = [];
    $onChunk = function (StreamChunk $chunk) use (&$chunks): void {
        $chunks[] = $chunk;
    };

    // Simulate SSE events
    $events = [
        'event: content_block_delta',
        'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}',
        '',
        'event: content_block_delta',
        'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" world"}}',
        '',
        'event: message_stop',
        'data: {"type":"message_stop"}',
    ];

    $responseData = $provider->parseSseEvents($events, $onChunk);

    $this->assertCount(3, $chunks); // 2 text_delta + 1 message_stop
    $this->assertSame('text_delta', $chunks[0]->type);
    $this->assertSame('Hello', $chunks[0]->text);
    $this->assertSame(' world', $chunks[1]->text);
}

public function testParseSseLineExtractsToolUseBlocks(): void
{
    $provider = new AnthropicProvider(apiKey: 'test-key');

    $chunks = [];
    $onChunk = function (StreamChunk $chunk) use (&$chunks): void {
        $chunks[] = $chunk;
    };

    $events = [
        'event: content_block_start',
        'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"tu_1","name":"read_node","input":{}}}',
        '',
        'event: content_block_delta',
        'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"id\":"}}',
        '',
        'event: content_block_delta',
        'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"\"5\"}"}}',
        '',
        'event: content_block_stop',
        'data: {"type":"content_block_stop","index":0}',
        '',
        'event: message_stop',
        'data: {"type":"message_stop"}',
    ];

    $responseData = $provider->parseSseEvents($events, $onChunk);

    // Should have tool_use_start, 2 tool_use_delta, tool_use_end, message_stop
    $toolStartChunks = array_filter($chunks, fn ($c) => $c->type === 'tool_use_start');
    $this->assertCount(1, $toolStartChunks);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter parseSse`
Expected: FAIL — method does not exist

- [ ] **Step 3: Implement streaming in AnthropicProvider**

Update `packages/ai-agent/src/Provider/AnthropicProvider.php`:

Change class declaration to implement `StreamingProviderInterface`:
```php
final class AnthropicProvider implements StreamingProviderInterface
```

Add the `streamMessage()` method:

```php
public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse
{
    $body = $this->buildRequestBody($request);
    $body['stream'] = true;

    return $this->httpPostStreaming(self::API_URL, $body, $onChunk);
}

/**
 * Parse SSE event lines into StreamChunks (public for testing).
 *
 * @param string[] $lines
 * @param callable(StreamChunk): void $onChunk
 * @return array{content: array<int, array<string, mixed>>, stop_reason: string}
 */
public function parseSseEvents(array $lines, callable $onChunk): array
{
    $contentBlocks = [];
    $currentToolUse = null;
    $currentToolJson = '';
    $stopReason = 'end_turn';

    $currentEvent = '';
    foreach ($lines as $line) {
        if (\str_starts_with($line, 'event: ')) {
            $currentEvent = \substr($line, 7);
            continue;
        }

        if (!\str_starts_with($line, 'data: ')) {
            continue;
        }

        $data = \json_decode(\substr($line, 6), true, 512, \JSON_THROW_ON_ERROR);
        $type = $data['type'] ?? '';

        match ($type) {
            'content_block_start' => (function () use ($data, $onChunk, &$currentToolUse, &$currentToolJson, &$contentBlocks): void {
                $block = $data['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $currentToolUse = new ToolUseBlock(
                        id: $block['id'],
                        name: $block['name'],
                        input: [],
                    );
                    $currentToolJson = '';
                    $onChunk(new StreamChunk(type: 'tool_use_start', toolUse: $currentToolUse));
                }
            })(),
            'content_block_delta' => (function () use ($data, $onChunk, &$currentToolJson): void {
                $delta = $data['delta'] ?? [];
                $deltaType = $delta['type'] ?? '';
                if ($deltaType === 'text_delta') {
                    $onChunk(new StreamChunk(type: 'text_delta', text: $delta['text'] ?? ''));
                } elseif ($deltaType === 'input_json_delta') {
                    $currentToolJson .= $delta['partial_json'] ?? '';
                    $onChunk(new StreamChunk(type: 'tool_use_delta', text: $delta['partial_json'] ?? ''));
                }
            })(),
            'content_block_stop' => (function () use ($onChunk, &$currentToolUse, &$currentToolJson, &$contentBlocks): void {
                if ($currentToolUse !== null) {
                    $input = $currentToolJson !== '' ? \json_decode($currentToolJson, true, 512, \JSON_THROW_ON_ERROR) : [];
                    $contentBlocks[] = [
                        'type' => 'tool_use',
                        'id' => $currentToolUse->id,
                        'name' => $currentToolUse->name,
                        'input' => $input,
                    ];
                    $onChunk(new StreamChunk(
                        type: 'tool_use_end',
                        toolUse: new ToolUseBlock(
                            id: $currentToolUse->id,
                            name: $currentToolUse->name,
                            input: $input,
                        ),
                    ));
                    $currentToolUse = null;
                    $currentToolJson = '';
                }
            })(),
            'message_delta' => (function () use ($data, &$stopReason): void {
                $stopReason = $data['delta']['stop_reason'] ?? $stopReason;
            })(),
            'message_stop' => (function () use ($onChunk): void {
                $onChunk(new StreamChunk(type: 'message_stop'));
            })(),
            default => null,
        };
    }

    return ['content' => $contentBlocks, 'stop_reason' => $stopReason];
}

/**
 * @return MessageResponse
 */
private function httpPostStreaming(string $url, array $body, callable $onChunk): MessageResponse
{
    $jsonBody = \json_encode($body, \JSON_THROW_ON_ERROR);

    $ch = \curl_init($url);
    if ($ch === false) {
        throw new \RuntimeException('Failed to initialize cURL.');
    }

    $buffer = '';
    $allLines = [];
    $fullText = '';

    \curl_setopt_array($ch, [
        \CURLOPT_POST => true,
        \CURLOPT_POSTFIELDS => $jsonBody,
        \CURLOPT_RETURNTRANSFER => false,
        \CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ],
        \CURLOPT_TIMEOUT => 300,
        \CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, &$allLines, &$fullText, $onChunk): int {
            $buffer .= $data;

            while (($pos = \strpos($buffer, "\n")) !== false) {
                $line = \rtrim(\substr($buffer, 0, $pos));
                $buffer = \substr($buffer, $pos + 1);

                $allLines[] = $line;

                // Process inline for text deltas (low latency)
                if (\str_starts_with($line, 'data: ')) {
                    $decoded = \json_decode(\substr($line, 6), true);
                    if (($decoded['type'] ?? '') === 'content_block_delta'
                        && ($decoded['delta']['type'] ?? '') === 'text_delta') {
                        $text = $decoded['delta']['text'] ?? '';
                        $fullText .= $text;
                        $onChunk(new StreamChunk(type: 'text_delta', text: $text));
                    }
                }
            }

            return \strlen($data);
        },
    ]);

    \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode >= 400) {
        throw new \RuntimeException("Anthropic API streaming error: HTTP {$httpCode}");
    }

    // Parse all events for tool use blocks and final state.
    // Forward non-text-delta chunks (tool_use_start/delta/end, message_stop)
    // to the real $onChunk — text deltas were already forwarded inline above.
    $parsed = $this->parseSseEvents($allLines, function (StreamChunk $chunk) use ($onChunk): void {
        if ($chunk->type !== 'text_delta') {
            $onChunk($chunk);
        }
    });

    $content = $parsed['content'];
    if ($fullText !== '') {
        \array_unshift($content, ['type' => 'text', 'text' => $fullText]);
    }

    return new MessageResponse(
        content: $content,
        stopReason: $parsed['stop_reason'],
    );
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit --filter AnthropicProviderTest`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add packages/ai-agent/src/Provider/AnthropicProvider.php packages/ai-agent/tests/Unit/Provider/AnthropicProviderTest.php
git commit -m "feat(#605): add streaming support to AnthropicProvider"
```

---

## Task 13: AgentExecutor.streamWithProvider() (#605)

**Files:**
- Modify: `packages/ai-agent/src/AgentExecutor.php`
- Modify: `packages/ai-agent/tests/Unit/AgentExecutorTest.php`

- [ ] **Step 1: Write the failing test**

Add to `packages/ai-agent/tests/Unit/AgentExecutorTest.php`:

```php
public function testStreamWithProviderForwardsChunks(): void
{
    $registry = new ToolRegistry();
    $executor = new AgentExecutor($registry);

    $provider = new class implements StreamingProviderInterface {
        public function sendMessage(MessageRequest $request): MessageResponse
        {
            return new MessageResponse(
                content: [['type' => 'text', 'text' => 'Hello world']],
                stopReason: 'end_turn',
            );
        }

        public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse
        {
            $onChunk(new StreamChunk(type: 'text_delta', text: 'Hello'));
            $onChunk(new StreamChunk(type: 'text_delta', text: ' world'));
            $onChunk(new StreamChunk(type: 'message_stop'));

            return new MessageResponse(
                content: [['type' => 'text', 'text' => 'Hello world']],
                stopReason: 'end_turn',
            );
        }
    };

    $receivedChunks = [];
    $agent = new TestAgent(AgentResult::success('prepared'));
    $context = new AgentContext(account: new TestAccount());

    $result = $executor->streamWithProvider(
        $agent,
        $context,
        $provider,
        function (StreamChunk $chunk) use (&$receivedChunks): void {
            $receivedChunks[] = $chunk;
        },
    );

    $this->assertTrue($result->success);
    $this->assertCount(3, $receivedChunks);
    $this->assertSame('Hello', $receivedChunks[0]->text);
    $this->assertSame(' world', $receivedChunks[1]->text);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter testStreamWithProvider`
Expected: FAIL — method does not exist

- [ ] **Step 3: Implement streamWithProvider()**

Add to `packages/ai-agent/src/AgentExecutor.php`:

```php
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\StreamingProviderInterface;

/**
 * Execute an agent with a streaming provider, forwarding chunks in real time.
 *
 * @param callable(StreamChunk): void $onChunk
 */
public function streamWithProvider(
    AgentInterface $agent,
    AgentContext $context,
    StreamingProviderInterface $provider,
    callable $onChunk,
): AgentResult {
    $agentId = $this->getAgentId($agent);
    $accountId = (int) $context->account->id();

    $agentResult = $agent->execute($context);

    $messages = $context->parameters['messages'] ?? [
        ['role' => 'user', 'content' => $agentResult->message],
    ];
    $system = $context->parameters['system'] ?? null;
    $tools = $this->buildToolDefinitions();

    $iteration = 0;

    while (true) {
        $iteration++;
        if ($iteration > $context->maxIterations) {
            throw new MaxIterationsException($context->maxIterations);
        }

        $request = new MessageRequest(
            messages: $messages,
            system: $system,
            tools: $tools,
            maxTokens: (int) ($context->parameters['max_tokens'] ?? 4096),
        );

        $response = $provider->streamMessage($request, $onChunk);

        $messages[] = ['role' => 'assistant', 'content' => $response->content];

        if ($response->stopReason !== 'tool_use') {
            break;
        }

        // Execute tool calls synchronously between streaming rounds
        $toolResults = [];
        foreach ($response->getToolUseBlocks() as $toolUseBlock) {
            $toolResult = $this->toolRegistry->execute($toolUseBlock->name, $toolUseBlock->input);
            $isError = $toolResult['isError'] ?? false;
            $resultText = $toolResult['content'][0]['text'] ?? '';

            $this->auditLog[] = new AgentAuditLog(
                agentId: $agentId,
                accountId: $accountId,
                action: 'tool_call',
                success: !$isError,
                message: "Tool call: {$toolUseBlock->name}",
                data: ['tool' => $toolUseBlock->name, 'arguments' => $toolUseBlock->input],
                timestamp: \time(),
            );

            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolUseBlock->id,
                'content' => $resultText,
                'is_error' => $isError,
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }

    $finalText = $response->getText();

    $result = AgentResult::success(
        message: $finalText,
        data: ['usage' => $response->usage, 'iterations' => $iteration],
    );

    $this->auditLog[] = new AgentAuditLog(
        agentId: $agentId,
        accountId: $accountId,
        action: 'stream_with_provider',
        success: true,
        message: $finalText,
        data: $result->data,
        timestamp: \time(),
    );

    return $result;
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit --filter testStreamWithProvider`
Expected: All PASS

- [ ] **Step 5: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add packages/ai-agent/src/AgentExecutor.php packages/ai-agent/tests/Unit/AgentExecutorTest.php
git commit -m "feat(#605): add AgentExecutor::streamWithProvider()

Closes #605"
```

---

## Task 14: Final verification and autoloader

- [ ] **Step 1: Update composer autoload if needed**

Check that `packages/ai-agent/composer.json` has the `Provider` namespace mapped:

```json
{
    "autoload": {
        "psr-4": {
            "Waaseyaa\\AI\\Agent\\": "src/"
        }
    }
}
```

Since `Provider/` is under `src/`, PSR-4 handles it automatically. No change needed.

- [ ] **Step 2: Dump autoloader**

Run: `composer dump-autoload`

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 4: Verify no remaining references to EditorialWorkflowStateMachine**

Run: `grep -r 'EditorialWorkflowStateMachine' packages/ --include='*.php'`
Expected: No results

- [ ] **Step 5: Final commit if any cleanup was needed**

```bash
git add -A
git commit -m "chore: final verification and cleanup for Claudriel unblocker sprint"
```
