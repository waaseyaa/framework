# Design Review: Claudriel Unblocker Sprint

**Spec:** `docs/superpowers/specs/2026-03-23-claudriel-unblocker-sprint-design.md`
**Reviewer:** Code Review Agent
**Date:** 2026-03-23

---

## Overall Assessment

The spec is well-structured, with clear problem statements and focused scope. The implementation order is correct. Below are findings by severity.

---

## Critical Issues (Must Fix)

### C1. SchemaRegistry is NOT in `packages/ai-schema/src/Mcp/` -- it is at `packages/ai-schema/src/SchemaRegistry.php`

The spec says to modify `packages/ai-schema/src/Mcp/SchemaRegistry.php`. That file does not exist. The actual `SchemaRegistry` lives at `packages/ai-schema/src/SchemaRegistry.php` (namespace `Waaseyaa\AI\Schema`, not `Waaseyaa\AI\Schema\Mcp`). The file list must be corrected or the implementation will target a non-existent file.

### C2. `ToolRegistryInterface` placement in ai-schema creates a circular concern

The spec places `ToolRegistryInterface` and `ToolRegistry` in `packages/ai-schema/src/Mcp/`. However, `ToolRegistry::execute()` invokes callables -- it is an execution mechanism, not a schema definition. `ai-schema` currently has zero runtime execution logic; all execution lives in `ai-agent` (`McpToolExecutor` is the only exception, and it lives in ai-schema only because it is stateless entity-CRUD dispatch).

Adding `execute()` to ai-schema blurs the package boundary. Two options:
- **(Recommended)** Put `ToolRegistryInterface` in ai-schema (it is a contract) but put `ToolRegistry` (the implementation with `execute()`) in ai-agent. This preserves ai-schema as a definition-only package.
- Or accept the pragmatic choice and document why execution logic now lives in ai-schema.

### C3. `EditorialWorkflowService` rewire gap: `ContentModerator::transition()` returns `ContentModerationState`, not mutating the node

The spec says `EditorialWorkflowService::transitionNode()` should delegate to `$moderator->transition()`. But `ContentModerator::transition()` takes a `ContentModerationState` value object and returns a new `ContentModerationState` -- it does not mutate entity fields. The current `transitionNode()` does significant work beyond state validation:
- Sets `workflow_state`, `status`, `workflow_last_transition`, and `workflow_audit` on the node
- Calls `EditorialTransitionAccessResolver` for permission checks
- Uses `statusForState()` to map state to legacy status integer

The spec must clarify that `ContentModerator::transition()` replaces only the transition-validity check (`assertTransitionAllowed`), NOT the full `transitionNode()` body. The field mutation, audit trail, and access resolution logic must remain in `EditorialWorkflowService`.

---

## Important Issues (Should Fix)

### I1. `EditorialTransitionAccessResolver` depends on `EditorialWorkflowStateMachine` -- not mentioned in spec

`EditorialTransitionAccessResolver` takes `EditorialWorkflowStateMachine` in its constructor and calls `assertTransitionAllowed()` and `availableTransitions()` on it. When `EditorialWorkflowStateMachine` is deleted, this class breaks. The spec must either:
- Rewire `EditorialTransitionAccessResolver` to use `ContentModerator` or the `Workflow` config entity directly
- Or keep a slimmed-down `EditorialWorkflowStateMachine` that delegates to the preset's `Workflow`

This is a missing file in the change list.

### I2. Missing `max_iterations` guard in `executeWithProvider()` tool loop

The spec mentions "max iterations" but does not specify the default or how it is configured. An unbounded tool loop against a paid API is a cost and safety risk. The spec should define:
- Default max iterations (e.g., 25)
- Constructor parameter or method parameter
- Behavior on exceeding the limit (return partial result? throw?)

### I3. `AnthropicProvider` default model is pinned to a dated snapshot

`model = 'claude-sonnet-4-20250514'` -- hardcoded model snapshots become stale. Consider using `claude-sonnet-4-latest` as default, or making the model a required parameter so callers are explicit. At minimum, document the rationale for pinning.

### I4. `WorkflowState` metadata addition needs backward compatibility

Adding an optional `metadata` array to the `readonly` `WorkflowState` value object is fine for new code, but existing serialized `Workflow` config entities (stored as JSON in `_data`) may not contain this field. The deserialization path in `Workflow` must use `$data['metadata'] ?? []` (per the "backward-compatible cache evolution" gotcha in CLAUDE.md).

### I5. `McpServer` constructor change is a breaking change

Changing `McpServer::__construct(SchemaRegistry, McpToolExecutor)` to `__construct(ToolRegistryInterface)` removes two dependencies. Any code instantiating `McpServer` directly (tests, kernels, service providers) will break. The spec should list all call sites that need updating. Check `AbstractKernel` or `HttpKernel` boot methods.

---

## Suggestions (Nice to Have)

### S1. `ToolRegistry::execute()` should validate tool exists before invoking callable

The spec says `execute()` "looks up the callable and invokes it, wraps exceptions in MCP error format." It should also handle the case where the tool name is not registered (return MCP error, do not throw). The current `McpServer::callTool()` has this guard -- it should migrate to `ToolRegistry`.

### S2. Consider `ProviderInterface` in ai-schema instead of ai-agent

If other packages ever need to type-hint `ProviderInterface` without depending on ai-agent (e.g., for DI configuration in foundation), placing the interface in ai-schema would give more flexibility. However, this is only worth doing if there is a concrete need.

### S3. `StreamChunk` should include `index` for content block tracking

The Anthropic API sends `content_block_start` with an `index` field to track which content block a delta belongs to. The `StreamChunk` value object lacks this. For multi-block responses (text + tool_use in one response), the consumer needs to know which block the chunk belongs to.

### S4. SSE parsing should handle incomplete lines (buffering)

`CURLOPT_WRITEFUNCTION` callbacks receive arbitrary byte chunks, not line-aligned data. The SSE parser must buffer partial lines between callback invocations. The spec should mention this requirement explicitly to avoid a subtle streaming bug.

### S5. Add `ToolRegistry::has(string $name): bool` to the interface

The interface has `getTool()` returning nullable, but a simple existence check is a common need (e.g., before attempting execution). Minor convenience.

### S6. Consider naming the provider value objects under a sub-namespace

Adding 6 new files directly to `packages/ai-agent/src/` (MessageRequest, MessageResponse, ToolUseBlock, ToolResultBlock, StreamChunk, RateLimitException) will crowd the flat namespace. Consider `Waaseyaa\AI\Agent\Provider\` or `Waaseyaa\AI\Agent\Message\` for the value objects.

---

## Implementation Order Verification

The proposed order is correct:
1. **#607 Tool Registry** -- no deps, foundational
2. **#604 Workflow Refactor** -- independent, parallelizable with #607
3. **#606 Anthropic Provider** -- depends on #607 (ToolRegistryInterface)
4. **#605 Streaming** -- depends on #606 (extends AnthropicProvider)

No circular dependencies detected. Layer discipline is maintained (ai-schema = layer 5, ai-agent = layer 5, workflows = layer 3).

---

## File List Completeness

### Missing from the spec:
| File | Reason |
|---|---|
| `packages/workflows/src/EditorialTransitionAccessResolver.php` | Depends on `EditorialWorkflowStateMachine` being deleted (see I1) |
| `packages/ai-schema/src/SchemaRegistry.php` | Correct path (not `Mcp/SchemaRegistry.php`) for modification |
| Kernel or service provider wiring `McpServer` | `McpServer` constructor changes (see I5) |
| `packages/workflows/tests/Unit/EditorialTransitionAccessResolverTest.php` | Must update if resolver is rewired |

### Correctly listed:
All other files in the spec match the actual codebase structure.
