# ai-agent ↔ Bimaaji In-Process Tools

**Mission:** `ai-agent-bimaaji-tools-01KS5VKR`
**Status:** Spec
**Target branch:** `main`
**Cross-references:** Design doc `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` (M2 of 5). Depends on: M1 `bimaaji-wakeup-01KS5VEY`. Blocks: M3 `bimaaji-mcp-bridge` (M3 reuses the tool-API shape validated here). Independent of M4 `agent-output-package` and M5 `bimaaji-install-command`.

## Why this mission exists

`packages/ai-agent/` runs in-process AI agents inside Waaseyaa applications (`AgentExecutor`, `AgentRun` entity, HTTP/SSE/CLI/Messenger surfaces, capability-gated tool execution). It already integrates with the Anthropic provider and exposes `#[AsAgentTool]` for tool registration. What it lacks today is any way for an embedded agent to **understand or change the host application** — there are no first-party introspection or mutation tools.

`packages/bimaaji/` ships an introspection + validated-mutation surface (`ApplicationGraphGenerator`, `MutationValidator` → `PatchSet` with `SovereigntyGuardrails`) that is precisely what an embedded agent needs. After M1, `ApplicationGraphGenerator` and the 6 default `GraphSectionProvider`s are container-resolvable. After this mission, an embedded agent can call them as tools.

This mission deliberately runs **before** M3 (the MCP bridge) so that the tool-API shape — argument schemas, return shapes, capability boundaries, error envelopes — is validated against a real consumer (the in-process agent) before being baked into an external transport. If M2 reveals shape problems, M3 inherits the fixes. If M2 is filed in parallel with M3, the MCP API risks getting locked in before any consumer has used it.

**The contract.** Four new tools under `packages/ai-agent/src/Tool/Bimaaji/`, each marked `#[AsAgentTool]`, each delegating to bimaaji and respecting bimaaji's existing access semantics. Two capability gates: `bimaaji.read` for introspection, `bimaaji.mutate` for proposing patches. No tool writes files — `GeneratePatchTool` returns a `PatchSet`; the consuming agent loop decides what to do with it. Mutation goes through `SovereigntyGuardrails` and cannot be bypassed by the tool layer.

## User scenarios

### Primary flow: an embedded agent introspects the host application

1. A developer registers an agent with `requires_capability: 'bimaaji.read'`. They invoke it via `bin/waaseyaa ai:run "List all entity types this app exposes" --inline`.
2. The agent loop sees the `IntrospectGraphTool` and `IntrospectSectionTool` are available, calls `IntrospectSectionTool(section: 'entities')`.
3. The tool resolves `ApplicationGraphGenerator` from the container, scopes to the entities section, returns a JSON-serializable result via the standard tool result envelope.
4. The agent reasons over the result and answers.

### Primary flow: an embedded agent proposes a patch

1. A developer registers an agent with `requires_capability: ['bimaaji.read', 'bimaaji.mutate']`. They invoke it: `bin/waaseyaa ai:run "Add a new 'archived_at' field to the node entity"`.
2. The agent uses `IntrospectSectionTool(section: 'entities')` to see the current shape.
3. The agent calls `ProposeMutationTool(operation: 'add_field', entity_type: 'node', field: 'archived_at', parameters: {...})`.
4. The tool delegates to `MutationValidator::validate()` — guardrails fire, validation runs, the result envelope reports `valid: true` (or, on rejection, the structured reason).
5. The agent calls `GeneratePatchTool(...)` (or the validator result feeds straight into a patch generator tool — planner picks the exact split).
6. The tool returns a `PatchSet` with content hashes. **Nothing is written to disk.** The agent's surrounding loop (HITL approval, broadcast, audit log) decides whether to surface the patch to the human reviewer.

### Primary flow: capability gating blocks an under-privileged agent

1. A developer registers an agent with only `requires_capability: 'bimaaji.read'`. The agent attempts to call `ProposeMutationTool`.
2. `AgentRunAccessPolicy` (or the existing tool-capability check) rejects the call with a structured error: `capability_required: 'bimaaji.mutate'`.
3. The agent loop receives the error, reasons about it, and either escalates (HITL approval prompt for capability grant) or returns a failure to the caller. No mutation can occur.

### Edge cases

- **Sovereignty profile denies the mutation.** `SovereigntyGuardrails` returns `denied` from inside `MutationValidator`. The tool surfaces the deny reason in the result envelope — including the `deniedProfile` identity — without leaking unrelated internal state.
- **Unknown section key in `IntrospectSectionTool`.** Tool returns a structured error listing available section keys (matches FR-013 in M1).
- **The mutation pipeline succeeds but the resulting `PatchSet` is empty.** Tool returns the empty patch set (valid, just unactionable). The agent loop decides what to do.
- **Two mutation tools called in the same iteration.** Each call is independent — the tool is stateless apart from its container-resolved dependencies. The agent loop is responsible for sequencing.
- **Capability token revoked mid-run.** Existing `AgentRunAccessPolicy` semantics apply — the next tool invocation that needs the revoked capability fails. No partial-execution rollback at the tool layer.

## Requirements

### Functional

| ID | Status | Requirement |
|---|---|---|
| FR-001 | Mandatory | `Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool` exists, extends `AbstractAgentTool`, carries `#[AsAgentTool(name: 'bimaaji_introspect_graph', capability: 'bimaaji.read', destructive: false)]`. It calls `ApplicationGraphGenerator::generate()` and returns the result as a JSON-serializable array via the standard tool result envelope. |
| FR-002 | Mandatory | `IntrospectSectionTool` (same package + attribute pattern, `capability: 'bimaaji.read'`). Takes a `section: string` argument. Returns the `GraphSection::toArray()` payload for that section. Returns a structured error if the section key is unknown — error payload lists available section keys. |
| FR-003 | Mandatory | `ProposeMutationTool` carries `#[AsAgentTool(name: 'bimaaji_propose_mutation', capability: 'bimaaji.mutate', destructive: false)]` (destructive=false because it does not write — it returns a validation result). It takes `operation`, `entity_type`, `field?`, `parameters?` arguments mirroring `TaskDefinition`. It delegates to `MutationValidator::validate()` and returns the `MutationResult::toArray()` payload. |
| FR-004 | Mandatory | `GeneratePatchTool` carries `#[AsAgentTool(name: 'bimaaji_generate_patch', capability: 'bimaaji.mutate', destructive: false)]`. It takes the same arguments as `ProposeMutationTool` plus optionally the validated `MutationResult` (so the tool can be chained). It delegates to `PatchGenerator::generate()` and returns `PatchSet::toArray()`. **It does not write to disk.** |
| FR-005 | Mandatory | All four tools are auto-discovered by the existing `PackageManifestCompiler` attribute scan (no manual `ServiceProvider` registration needed). Discovery runs as part of `bin/waaseyaa optimize:manifest`. |
| FR-006 | Mandatory | Capability gating is enforced via the existing tool-capability mechanism in `ai-agent`. An agent definition that does not declare `requires_capability: 'bimaaji.read'` cannot invoke read tools; one without `bimaaji.mutate` cannot invoke mutation tools. The mission does not introduce a parallel capability system. |
| FR-007 | Mandatory | A reference agent definition lives at `packages/ai-agent/tests/Fixture/BimaajiDemoAgent.php` (or similar). It demonstrates introspect → propose → generate-patch over a fixture entity. The definition's `requires_capability` includes both `bimaaji.read` and `bimaaji.mutate`. |
| FR-008 | Mandatory | Contract test `IntrospectGraphToolTest::executesAgainstBootedKernel` boots a minimal kernel, resolves the tool from the container, calls `execute()`, asserts the result envelope contains all 6 default sections. |
| FR-009 | Mandatory | Contract test for each of the other three tools verifying delegation correctness (`IntrospectSectionToolTest`, `ProposeMutationToolTest`, `GeneratePatchToolTest`). |
| FR-010 | Mandatory | Contract test `*ToolCapabilityTest` verifying that the capability check fires for an agent lacking the required capability. Reuses existing capability-test fixtures from `ai-agent`. |
| FR-011 | Mandatory | Integration test `tests/Integration/PhaseN/AgentRuntime/BimaajiAgentRunTest.php` runs the reference agent end-to-end via `AgentExecutor`, asserts the run completes with `status: completed` and produces a non-empty `PatchSet` in the run audit log. |
| FR-012 | Mandatory | `packages/ai-agent/README.md` adds a "Bimaaji-backed tools" section enumerating the four tools, their capabilities, and a `bin/waaseyaa ai:run` example. |

### Non-functional

| ID | Status | Threshold |
|---|---|---|
| NFR-001 | Mandatory | `IntrospectGraphTool::execute()` completes in ≤ 200 ms median over the 6 default providers on a clean test kernel (assumes M1's NFR-001 budget of ≤ 100 ms for the generator plus ≤ 100 ms tool overhead). |
| NFR-002 | Mandatory | `ProposeMutationTool::execute()` adds ≤ 50 ms over `MutationValidator::validate()` direct invocation. Measured by a microbenchmark in the contract test. |
| NFR-003 | Mandatory | All four tools' result envelopes are JSON-serializable. No closures, no resource handles, no non-stringifiable objects. The contract test asserts this. |
| NFR-004 | Mandatory | Capability-violation errors (FR-010) return within 5 ms — the check fires before any tool work. No information leakage in the error envelope beyond the required capability name. |

### Constraints

| ID | Status | Constraint |
|---|---|---|
| C-001 | Mandatory | All new code lives in `packages/ai-agent/` (Layer 5). It imports `Waaseyaa\Bimaaji\*` (same layer — sibling). No upward imports. `bin/check-package-layers` passes. |
| C-002 | Mandatory | No changes to `packages/bimaaji/`. The tools are pure adapters — bimaaji's contracts are stable per M1. Any contract change is deferred to a follow-up mission. |
| C-003 | Mandatory | No changes to the existing `#[AsAgentTool]` attribute, `AbstractAgentTool` base class, or capability-gating mechanism. The mission is additive — four tool classes + one fixture agent + tests + README edit. |
| C-004 | Mandatory | The mission does not expose bimaaji over MCP, HTTP, or any external surface. (M3.) |
| C-005 | Mandatory | `GeneratePatchTool` does not write files. Period. The tool returns the `PatchSet` and the agent loop is responsible for any disk persistence. This is the key safety invariant separating bimaaji's mutation surface from Boost's `tinker`. |
| C-006 | Mandatory | `composer verify` is green on the merge commit. `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-composer-policy`, `bin/check-getquery-bindings` all green. |
| C-007 | Mandatory | No CI hooks bypassed. |

## Success criteria

| ID | Metric | How verified |
|---|---|---|
| SC-001 | An embedded agent can introspect the host application via `IntrospectGraphTool`. | `IntrospectGraphToolTest` + `BimaajiAgentRunTest` pass. |
| SC-002 | An embedded agent can propose a patch via `ProposeMutationTool` + `GeneratePatchTool`, gated by `bimaaji.mutate`. | `BimaajiAgentRunTest` asserts a non-empty `PatchSet` is produced for the reference agent. |
| SC-003 | An agent without the required capability cannot invoke gated tools. | `*ToolCapabilityTest` passes. |
| SC-004 | The tool API surface validated here is the one M3 wraps as MCP tools — no shape changes between M2 and M3. | Cross-mission gate: M3's first WP imports the tool argument schemas / return shapes from this mission's classes and verifies no breaking changes. |
| SC-005 | `GeneratePatchTool` does not write files. | `GeneratePatchToolTest` asserts no filesystem mutations occurred (sandboxed temp dir, count files before/after). |
| SC-006 | `composer verify` green on merge commit. | CI status check. |

## Key entities

| Entity | Role | Net change |
|---|---|---|
| `Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool` (new) | Read tool. | +1 file. |
| `Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool` (new) | Read tool. | +1 file. |
| `Waaseyaa\AI\Agent\Tool\Bimaaji\ProposeMutationTool` (new) | Mutation validator wrapper. | +1 file. |
| `Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool` (new) | Patch generator wrapper. | +1 file. |
| `BimaajiDemoAgent` (test fixture, new) | Reference agent definition. | +1 fixture file. |
| Contract tests (4 files) | Per-tool delegation tests. | +4 files. |
| Capability tests (1 file or merged) | Capability gating regression. | +1 file. |
| Integration test (1 file) | End-to-end agent run. | +1 file. |
| `packages/ai-agent/README.md` | Bimaaji tools section. | Edit. |
| `CHANGELOG.md` | `[Unreleased]` entry. | Edit. |

## Assumptions

- M1 is merged. `ApplicationGraphGenerator`, `MutationValidator`, `PatchGenerator`, and `SovereigntyGuardrails` are container-resolvable.
- The existing `#[AsAgentTool]` attribute already supports `capability` and `destructive` parameters with the listed semantics. (Confirmed by CLAUDE.md and the `ai-agent` README.)
- The existing capability mechanism uses string keys (`bimaaji.read`, `bimaaji.mutate`) — not enums or class strings — so introducing new capabilities is a documentation change only, not a code change.
- `MutationValidator` and `PatchGenerator` are container-resolvable singletons after M1. If they are not (M1's plan omits them), this mission's WP01 adds the bindings to `BimaajiServiceProvider` before any tools land.

## Out of scope

- Adding new bimaaji introspection providers or mutation operations.
- MCP transport for these tools. (M3.)
- HTTP route exposure for these tools.
- HITL approval UX for mutation tools — the existing approval flow in `ai-agent` is reused as-is.
- Cross-package safety for an agent that proposes a patch outside `packages/bimaaji/` scope — bimaaji's existing guardrails are the policy boundary; this mission does not add new ones.

## WP outline (for /spec-kitty.plan)

The planner is free to revise. Indicative shape:

- **WP01 — Cross-mission gate + foundation.** Verify M1 is merged; resolve `ApplicationGraphGenerator`, `MutationValidator`, `PatchGenerator` from the container in a smoke test. If any is missing a binding, add it to `BimaajiServiceProvider` (delegated to M1's plan if M1 not yet merged) or note as a blocker.
- **WP02 — Read tools.** `IntrospectGraphTool` + `IntrospectSectionTool`. Contract tests (FR-008, FR-009). Capability gating (FR-006). NFR-001 timing check.
- **WP03 — Mutation tools.** `ProposeMutationTool` + `GeneratePatchTool`. Contract tests. Filesystem-no-write assertion (SC-005). NFR-002 overhead check.
- **WP04 — Reference agent + integration.** `BimaajiDemoAgent` fixture. `BimaajiAgentRunTest` end-to-end (FR-011). Capability-violation test (FR-010).
- **WP05 — Docs + verify.** README edit. CHANGELOG. Cross-mission gate (SC-004 — surface contract recorded for M3). Full `composer verify`.

## References

- `packages/ai-agent/src/AgentExecutor.php` — agent loop.
- `packages/ai-agent/src/Attribute/AsAgentDefinition.php` — agent registration attribute.
- `packages/ai-tools/src/Attribute/AsAgentTool.php` — tool registration attribute.
- `packages/ai-tools/src/AbstractAgentTool.php` — tool base class.
- `packages/ai-agent/src/Access/AgentRunAccessPolicy.php` — capability gating.
- M1 `bimaaji-wakeup-01KS5VEY` — provides the container-resolvable bimaaji surface.
- M3 `bimaaji-mcp-bridge` — consumes the tool-API shape this mission validates.
- Design doc: `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §M2.
- Memory: `feedback_modern_php_rules` — contract tests for every extension point.
