# Claudriel Unblocker Sprint Design

**Date:** 2026-03-23
**Issues:** waaseyaa#604, #605, #606, #607
**Unblocks:** claudriel#483 (workflows), #487 (ai-agent), #489 (MCP server)

## Overview

Four waaseyaa changes to unblock Claudriel's workflow state machine, AI agent streaming, Anthropic client, and custom tool registration. Designed as a cohesive unit: the three ai-agent issues (#605/#606/#607) share interfaces and the workflow issue (#604) is independent.

## Section 1: Workflow State Machine Refactor (#604)

**Milestone:** Validation & Lifecycle Hooks (P1)

### Problem

`EditorialWorkflowStateMachine` hardcodes 4 editorial states and 6 transitions. The generic `Workflow` config entity + `ContentModerator` already support arbitrary states/transitions, but `EditorialWorkflowService` bypasses them and uses the hardcoded class directly. Applications needing custom state sets (e.g., Claudriel's commitment lifecycle: pending/active/completed/archived) cannot reuse the workflow infrastructure.

### Design

**`EditorialWorkflowStateMachine` → `EditorialWorkflowPreset`** (factory class):
- Single static method: `create(): Workflow` — returns a `Workflow` config entity pre-populated with the 4 editorial states and 6 transitions
- `normalizeState()` and `statusForState()` move here as static utility methods (editorial-specific, not generic)

**`EditorialWorkflowService` rewired to use `Workflow` entity:**
- Constructor changes: replace `EditorialWorkflowStateMachine` dependency with `Workflow` (the preset's output)
- `transitionNode()` uses `Workflow::isTransitionAllowed()` for validation, then continues to handle field mutation (`workflow_state`, `status`, `workflow_last_transition`), audit trail (`workflow_audit`), and access checks (`EditorialTransitionAccessResolver`) itself — these are editorial-specific concerns that `ContentModerator` does not own
- The `Workflow` instance is created via `EditorialWorkflowPreset::create()` and injected by the service provider

**`EditorialTransitionAccessResolver` updated:**
- Replace `EditorialWorkflowStateMachine` dependency with `Workflow` — uses `Workflow::getTransitions()` for permission lookups instead of the deleted class

**Permission strings in transitions:**
- `WorkflowTransition` already has `id`, `label`, `from`, `to`, `weight`. Permission patterns (e.g., "publish {bundle} content") are editorial-specific metadata — they stay in `EditorialTransitionAccessResolver` as a mapping from transition ID to permission pattern, not in the generic `WorkflowTransition` class

**`WorkflowState` gains optional metadata:**
- Add optional `metadata` array to `WorkflowState` (e.g., `['legacy_status' => 1]`) so the editorial preset can carry its status mapping without hardcoding it in the generic class
- Backward-compatible deserialization: `$data['metadata'] ?? []` when hydrating from cached/stored configs

**Tests:**
- `EditorialWorkflowStateMachineTest` → `EditorialWorkflowPresetTest`, verify factory creates correct `Workflow`
- New test: custom `Workflow` with non-editorial states (commitment lifecycle)
- `EditorialWorkflowServiceTest` updated to use `Workflow` entity

**Unchanged:** `Workflow`, `ContentModerator`, `WorkflowTransition`, `ContentModerationState`.

**Additional files:**
- `packages/workflows/src/EditorialTransitionAccessResolver.php` (modify — replace StateMachine dependency with Workflow)
- `packages/workflows/tests/Unit/EditorialTransitionAccessResolverTest.php` (modify)

## Section 2: Tool Registry (#607)

**Milestone:** Alpha Release Stabilization

### Problem

`McpToolExecutor` only auto-generates entity CRUD tools. Applications need custom tools (Gmail, Calendar, GitHub) alongside auto-generated ones. Currently no registration mechanism exists.

### Design

**`ToolRegistryInterface`** (new, in ai-agent — consumers are McpServer and AgentExecutor, both in ai-agent):
```php
interface ToolRegistryInterface {
    public function register(McpToolDefinition $tool, callable $executor): void;
    public function has(string $name): bool;
    public function getTools(): array;        // McpToolDefinition[]
    public function getTool(string $name): ?McpToolDefinition;
    public function execute(string $name, array $arguments): array;
}
```

Each tool carries its own executor callable — no routing by naming convention.

**`ToolRegistry`** (new, in ai-agent — execution lives here, not in the schema package):
- Implements `ToolRegistryInterface`
- Simple map: `$tools[name] => McpToolDefinition`, `$executors[name] => callable`
- `execute()` looks up the callable and invokes it, wraps exceptions in MCP error format
- Throws `\InvalidArgumentException` when tool name not found

**`SchemaRegistry` populates `ToolRegistry`:**
- `SchemaRegistry` gains a `registerEntityTools(ToolRegistryInterface $registry): void` method
- Iterates `McpToolGenerator::generateAll()` and registers each with a callable delegating to `McpToolExecutor::execute()`
- `SchemaRegistry` retains schema responsibilities but is no longer the tool lookup

**`McpServer` and `AgentExecutor` switch to `ToolRegistryInterface`:**
- `McpServer::__construct(ToolRegistryInterface $registry)`
- `AgentExecutor::executeTool()` delegates to `ToolRegistryInterface::execute()`

**Custom tool registration** (application side):
```php
$registry->register(
    new McpToolDefinition(name: 'gmail_send', description: '...', inputSchema: [...]),
    fn (array $args) => $gmailService->send($args),
);
```

**Unchanged:** `McpToolExecutor`, `McpToolGenerator`, `McpToolDefinition`.

## Section 3: Anthropic Provider (#606)

**Milestone:** Alpha Release Stabilization

### Problem

The ai-agent package has no LLM client. Claudriel's chat agent communicates with Claude via a Docker Python subprocess. A native PHP Anthropic client is needed for the agent execution loop.

### Design

**`ProviderInterface`** (new, in ai-agent):
```php
interface ProviderInterface {
    public function sendMessage(MessageRequest $request): MessageResponse;
}
```

**Value objects** (new, in ai-agent):
- `MessageRequest`: `model`, `system`, `messages[]`, `tools[]`, `maxTokens`, `metadata[]`
- `MessageResponse`: `content[]` (text + tool_use blocks), `stopReason`, `usage` (input/output/cache tokens)
- `ToolUseBlock`: `id`, `name`, `input`
- `ToolResultBlock`: `toolUseId`, `content`, `isError`

**`AnthropicProvider`** (new, in ai-agent) implements `ProviderInterface`:
- Internal cURL client — `POST https://api.anthropic.com/v1/messages`
- Constructor: `(string $apiKey, string $model = 'claude-sonnet-4-20250514')`
- Prompt caching: `cache_control: {"type": "ephemeral"}` on system prompt and last tool definition
- Rate limit awareness: reads `retry-after` header, throws `RateLimitException`
- JSON encode/decode with `JSON_THROW_ON_ERROR`

**`AgentExecutor` gains multi-turn tool loop:**
- New method: `executeWithProvider(AgentInterface $agent, AgentContext $context, ProviderInterface $provider): AgentResult`
- Loop: send message → if `stop_reason === 'tool_use'` → execute tools via `ToolRegistryInterface` → append tool results → send again → repeat until `end_turn` or max iterations (default 25, configurable via `AgentContext::$maxIterations`). Throws `MaxIterationsException` if exceeded
- Each tool call logged to audit log
- Final text content assembled into `AgentResult`

**Existing `execute()` path unchanged** — works for agents that don't need an LLM provider.

## Section 4: Streaming Support (#605)

**Milestone:** Alpha Release Stabilization

### Problem

`AgentExecutor::execute()` is synchronous — returns a complete `AgentResult`. Applications streaming responses via SSE need agents that yield partial results.

### Design

**`StreamingProviderInterface`** (new, in ai-agent):
```php
interface StreamingProviderInterface extends ProviderInterface {
    public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse;
}
```
- `$onChunk` receives `StreamChunk` objects as they arrive
- Returns complete `MessageResponse` after stream ends (for audit logging)

**`StreamChunk`** (new value object):
```php
final readonly class StreamChunk {
    public function __construct(
        public string $type,    // 'text_delta', 'tool_use_start', 'tool_use_delta', 'tool_use_end', 'message_stop'
        public string $text = '',
        public ?ToolUseBlock $toolUse = null,
    ) {}
}
```

**`AnthropicProvider` implements `StreamingProviderInterface`:**
- `streamMessage()` sends request with `"stream": true`
- Parses SSE lines via `CURLOPT_WRITEFUNCTION` callback
- Accumulates full response internally while yielding chunks to `$onChunk`
- Tool-use blocks assembled from `content_block_start` + `content_block_delta` + `content_block_stop` events

**`AgentExecutor::streamWithProvider()`** (new method):
```php
public function streamWithProvider(
    AgentInterface $agent,
    AgentContext $context,
    StreamingProviderInterface $provider,
    callable $onChunk,
): AgentResult
```
- Same multi-turn tool loop as `executeWithProvider()`, uses `streamMessage()` for each LLM call
- Text deltas forwarded to `$onChunk` in real time
- Tool execution synchronous between streaming rounds
- Final `AgentResult` contains complete assembled response

**Claudriel integration point** (not built here — the contract):
```php
return new StreamedResponse(function () use ($executor, $agent, $context, $provider) {
    $executor->streamWithProvider($agent, $context, $provider, function (StreamChunk $chunk) {
        echo "data: " . json_encode(['text' => $chunk->text]) . "\n\n";
        ob_flush(); flush();
    });
});
```

**Existing `execute()` and `executeWithProvider()` unchanged** — streaming is additive.

## Implementation Order

1. **#607 Tool Registry** — no dependencies, foundational for #606
2. **#604 Workflow Refactor** — independent, can parallel with #607
3. **#606 Anthropic Provider** — depends on #607 (uses `ToolRegistryInterface`)
4. **#605 Streaming** — depends on #606 (extends `AnthropicProvider`)

## Files Created/Modified

### #604
- `packages/workflows/src/EditorialWorkflowPreset.php` (new, replaces EditorialWorkflowStateMachine)
- `packages/workflows/src/EditorialWorkflowStateMachine.php` (delete)
- `packages/workflows/src/EditorialWorkflowService.php` (modify — rewire to Workflow entity)
- `packages/workflows/src/EditorialTransitionAccessResolver.php` (modify — replace StateMachine dep with Workflow)
- `packages/workflows/src/WorkflowState.php` (modify — add metadata)
- `packages/workflows/tests/Unit/EditorialWorkflowPresetTest.php` (new, replaces StateMachineTest)
- `packages/workflows/tests/Unit/EditorialWorkflowServiceTest.php` (modify)
- `packages/workflows/tests/Unit/EditorialTransitionAccessResolverTest.php` (modify)

### #607
- `packages/ai-agent/src/ToolRegistryInterface.php` (new)
- `packages/ai-agent/src/ToolRegistry.php` (new — implementation with execute(), in agent layer)
- `packages/ai-schema/src/SchemaRegistry.php` (modify — add registerEntityTools method)
- `packages/ai-agent/src/McpServer.php` (modify — use ToolRegistryInterface)
- `packages/ai-agent/src/AgentExecutor.php` (modify — use ToolRegistryInterface)
- `packages/ai-agent/tests/Unit/McpServerTest.php` (modify)
- `packages/ai-agent/tests/Unit/AgentExecutorTest.php` (modify)
- `packages/ai-agent/tests/Unit/ToolRegistryTest.php` (new)

### #606
- `packages/ai-agent/src/Provider/ProviderInterface.php` (new)
- `packages/ai-agent/src/Provider/AnthropicProvider.php` (new)
- `packages/ai-agent/src/Provider/MessageRequest.php` (new)
- `packages/ai-agent/src/Provider/MessageResponse.php` (new)
- `packages/ai-agent/src/Provider/ToolUseBlock.php` (new)
- `packages/ai-agent/src/Provider/ToolResultBlock.php` (new)
- `packages/ai-agent/src/Provider/RateLimitException.php` (new)
- `packages/ai-agent/src/Provider/MaxIterationsException.php` (new)
- `packages/ai-agent/src/AgentContext.php` (modify — add $maxIterations property, default 25)
- `packages/ai-agent/src/AgentExecutor.php` (modify — add executeWithProvider)
- `packages/ai-agent/tests/Unit/AnthropicProviderTest.php` (new)
- `packages/ai-agent/tests/Unit/AgentExecutorTest.php` (modify)

### #605
- `packages/ai-agent/src/Provider/StreamingProviderInterface.php` (new)
- `packages/ai-agent/src/Provider/StreamChunk.php` (new)
- `packages/ai-agent/src/Provider/AnthropicProvider.php` (modify — implement StreamingProviderInterface)
- `packages/ai-agent/src/AgentExecutor.php` (modify — add streamWithProvider)
- `packages/ai-agent/tests/Unit/AnthropicProviderTest.php` (modify — streaming tests)
- `packages/ai-agent/tests/Unit/AgentExecutorTest.php` (modify — streaming tests)
