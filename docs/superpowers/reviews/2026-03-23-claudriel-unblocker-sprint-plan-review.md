# Plan Review: Claudriel Unblocker Sprint

**Reviewer:** Senior Code Reviewer (Claude Opus 4.6)
**Date:** 2026-03-23
**Plan:** `docs/superpowers/plans/2026-03-23-claudriel-unblocker-sprint.md`
**Spec:** `docs/superpowers/specs/2026-03-23-claudriel-unblocker-sprint-design.md`

---

## Verdict: APPROVE with 2 critical fixes, 3 important items

The plan is thorough, well-structured, and closely aligned with the design spec. Code examples are syntactically correct PHP 8.4, follow project conventions (`declare(strict_types=1)`, `final class`, PHPUnit 10.5 attributes), and JSON encode/decode symmetry is maintained. Task ordering respects dependency flow. The plan successfully addressed several issues raised in the spec review (C2 tool registry placement, C3 ContentModerator delegation gap, I1 EditorialTransitionAccessResolver, S5 `has()` method, S6 Provider sub-namespace).

---

## Critical (must fix before implementation)

### C1. `AgentContext` is `final readonly` -- step must preserve that declaration

**Location:** Task 8, Step 9 (line 1423)

`AgentContext` is currently `final readonly class`. The plan shows only the constructor snippet when adding `$maxIterations`:

```php
public function __construct(
    public AccountInterface $account,
    public array $parameters = [],
    public bool $dryRun = false,
    public int $maxIterations = 25,
) {}
```

This is technically correct (trailing default parameter, backward-compatible), but the step says "add the property" without showing the class declaration. An implementing agent could accidentally drop the `readonly` modifier.

**Fix:** Add explicit instruction: "Preserve the `final readonly class` declaration. Only add the new parameter to the existing constructor."

### C2. Streaming `httpPostStreaming` silently swallows tool-use streaming events

**Location:** Task 12, Step 3 (lines 2306-2342)

The `CURLOPT_WRITEFUNCTION` callback emits `text_delta` chunks inline for low latency (line 2322). Then `parseSseEvents()` is called with a no-op callback (line 2340) to extract tool-use blocks for the final `MessageResponse`. The comment says "Already handled text deltas inline; this pass catches tool blocks."

The problem: `parseSseEvents()` also emits `tool_use_start`, `tool_use_delta`, `tool_use_end`, and `message_stop` chunks via its `$onChunk` callback. Since the second call passes a no-op, these events are never forwarded to the caller's `$onChunk`. Applications expecting real-time tool-use streaming events will receive nothing.

**Fix:** Forward non-text-delta chunks to the real `$onChunk`:

```php
$parsed = $this->parseSseEvents($allLines, function (StreamChunk $chunk) use ($onChunk) {
    if ($chunk->type !== 'text_delta') {
        $onChunk($chunk);
    }
});
```

---

## Important (should fix)

### I1. Tasks 3, 4, 7 modify implementation before updating tests

Tasks 1, 2, 5, 6, 8, 9, 10, 11, 12, 13 correctly follow TDD (write failing test, then implement). Tasks 3, 4, and 7 are "rewire" tasks that modify source files first, then update tests afterward. While pragmatic for refactoring, this breaks the TDD discipline the plan otherwise follows consistently.

For Tasks 3 and 7, consider reordering: update the test to expect the new dependency first (it will fail because the source still uses the old one), then modify the source, then run tests green.

### I2. Namespace discrepancy between spec and plan (justified, needs spec update)

The spec places provider classes flat in `packages/ai-agent/src/` (e.g., `AnthropicProvider.php`). The plan introduces `packages/ai-agent/src/Provider/` with namespace `Waaseyaa\AI\Agent\Provider\`. This is a good organizational improvement that was actually suggested in the spec review (S6). The spec's file list should be updated to match.

### I3. `AnthropicProvider` reads `retry-after` from JSON body, not HTTP header

**Location:** Task 9, Step 3 (line 1701-1703)

The spec says "reads `retry-after` header." The implementation reads `$data['error']['retry_after']` from the decoded JSON response body. The Anthropic API returns `retry-after` as an HTTP response header. For correctness, the cURL request should capture response headers via `CURLOPT_HEADERFUNCTION` and parse the header value. The current JSON-body approach serves as a reasonable fallback but may not match actual API behavior during rate limiting.

---

## Suggestions (nice to have)

### S1. Task 4 should show `EditorialWorkflowPreset::statusForState()` and `normalizeState()` call sites

Task 4 rewires `EditorialWorkflowService` from `EditorialWorkflowStateMachine` to `Workflow`, but the current source calls `$this->stateMachine->statusForState($to)` and `$this->stateMachine->normalizeState(...)` on the old class. The plan should explicitly show these becoming `EditorialWorkflowPreset::statusForState($to)` and `EditorialWorkflowPreset::normalizeState(...)` (static calls). Without this, the implementing agent must infer the mapping.

### S2. `parseSseEvents` uses immediately-invoked closures in `match` arms

The `match` expression at lines 2223-2274 uses `(function() { ... })()` for each arm. While valid PHP 8.4, this is an unusual pattern. A `switch` statement or extracted private methods would be more readable and easier to debug.

### S3. `McpServer::callTool()` pre-check duplicates `ToolRegistry::execute()` guard

The plan's `McpServer::callTool()` checks `$this->registry->has($name)` before calling `execute()`. But the spec says `ToolRegistry::execute()` throws `\InvalidArgumentException` when the tool is not found. The `has()` check is intentionally redundant (for a nicer MCP error format vs exception). This is fine but should be documented with a comment explaining why.

### S4. Plan adds files not in spec (good additions, update spec)

Extra files in the plan not listed in the spec's file map:
- `packages/workflows/tests/Unit/WorkflowStateTest.php` -- tests for metadata
- `packages/ai-agent/tests/Unit/Provider/MessageRequestTest.php` -- value object tests
- `packages/workflows/src/Workflow.php` -- modified for metadata hydration

These are beneficial additions. Update the spec to include them.

---

## Checklist Summary

| Check | Result |
|-------|--------|
| Plan covers every spec file | PASS (with justified Provider/ subdirectory deviation) |
| PHP 8.4 syntax correct | PASS |
| `declare(strict_types=1)` in all code blocks with namespaces | PASS |
| `final class` / `final readonly class` convention | PASS |
| PHPUnit 10.5 attributes (`#[CoversClass]`) | PASS |
| No `-v` flag with PHPUnit | PASS |
| No `psr/log` usage | PASS |
| No Laravel/Illuminate imports | PASS |
| JSON encode/decode symmetry (`JSON_THROW_ON_ERROR`) | PASS |
| Task dependency ordering correct (#607 -> #606 -> #605, #604 independent) | PASS |
| TDD (test first) | PARTIAL -- Tasks 3, 4, 7 are impl-first |
| Test assertions match implementation code | PASS |
| Commit messages format (conventional commits with issue refs) | PASS |
| No missing imports in code blocks | PASS |
| Streaming double-emission / swallowed events bug | FAIL (C2) |
| AgentContext readonly preservation | NEEDS NOTE (C1) |
