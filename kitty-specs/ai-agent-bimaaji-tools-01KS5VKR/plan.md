# Implementation Plan — ai-agent-bimaaji-tools-01KS5VKR

**Mission:** `ai-agent-bimaaji-tools-01KS5VKR`
**Status:** Plan
**Spec:** [spec.md](spec.md)
**Design doc:** `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §M2
**Depends on:** M1 `bimaaji-wakeup-01KS5VEY` (merged)
**Blocks:** M3 `bimaaji-mcp-bridge-01KS5VS8` (reuses this mission's tool-API shape via SC-004)

## Branch contract

- Current branch at plan time: `main`
- Planning + base branch: `main`
- Merge target: `main`
- Branch matches target: ✅ (per `spec-kitty agent mission setup-plan --json`)

## Engineering alignment

This mission wires four `#[AsAgentTool]` adapters under `packages/ai-agent/src/Tool/Bimaaji/` that translate between the in-process AI agent loop and the bimaaji introspection + mutation surface M1 just shipped. The mission is intentionally **API-shape-defining** — it validates the tool argument schemas / return envelopes / error shapes against a real consumer (the embedded agent) before M3 bakes them into MCP transport (SC-004).

All four tools are pure adapters: they resolve bimaaji services from the container, call existing APIs, and translate results into JSON-serializable envelopes. No tool writes to disk. The mutation pipeline goes through `SovereigntyGuardrails` and cannot be bypassed at the tool layer (C-005, the headline safety invariant).

Capability gating is **not** new — it reuses the existing `#[AsAgentTool(capability: '...')]` mechanism. The mission introduces two capability strings (`bimaaji.read`, `bimaaji.mutate`) as documentation, not new code (C-003).

## Architecture decisions

### AD-01 — Tool location and namespace

All four tools live under `packages/ai-agent/src/Tool/Bimaaji/` in namespace `Waaseyaa\AI\Agent\Tool\Bimaaji\`. `ai-agent` is Layer 5; `bimaaji` is Layer 4. The tools `use Waaseyaa\Bimaaji\*` directly — no inline-FQCN escape hatch needed (downward cross-layer reference is allowed by `bin/check-package-layers`). (C-001)

**Alternative considered:** Hosting the tools under `packages/bimaaji/src/AgentTool/` instead. Rejected because (a) bimaaji shouldn't depend on `ai-agent`'s tool attribute (would force an L4 → L5 import — forbidden), and (b) the M1 spec is explicit that bimaaji's mutation surface is consumer-agnostic.

### AD-02 — Tool-attribute parameters

| Tool | `name` | `capability` | `destructive` |
|---|---|---|---|
| `IntrospectGraphTool` | `bimaaji_introspect_graph` | `bimaaji.read` | `false` |
| `IntrospectSectionTool` | `bimaaji_introspect_section` | `bimaaji.read` | `false` |
| `ProposeMutationTool` | `bimaaji_propose_mutation` | `bimaaji.mutate` | `false` |
| `GeneratePatchTool` | `bimaaji_generate_patch` | `bimaaji.mutate` | `false` |

All four are `destructive: false` because none writes to disk. `ProposeMutationTool` returns a validation envelope; `GeneratePatchTool` returns a `PatchSet`. The agent loop's HITL approval flow decides what to do with patches. (FR-001..FR-004, C-005)

### AD-03 — Result envelope shape

Every tool returns an associative array with a stable top-level shape:

```php
return [
    'ok'    => true,                           // false on failure path
    'data'  => $result,                        // tool-specific payload (omitted on failure)
    'meta'  => ['tool' => $toolName, ...],     // optional diagnostic info
    'error' => null,                           // {code, message, details} on failure
];
```

This wraps the existing bimaaji return shapes (`ApplicationGraph::toArray()`, `MutationResult::toArray()`, `PatchSet::toArray()`) without rewriting them — bimaaji shapes are stable (C-002) and the wrapper is the tool-layer concern. (FR-001..FR-004, NFR-003)

**Why a wrapper at all?** The agent loop expects every tool result to declare success/failure explicitly so it can branch without parsing exceptions. Capability errors (FR-010, NFR-004) use the same envelope, so the agent can reason over `error.code === 'capability_required'` identically to domain errors.

### AD-04 — Container-resolution of bimaaji services

`ApplicationGraphGenerator`, `MutationValidator`, `PatchGenerator` are container-resolvable after M1. Each tool constructor takes the relevant dependency. If `MutationValidator` or `PatchGenerator` is missing a `BimaajiServiceProvider` binding (Assumption in spec), WP01 adds it before WP03 lands.

**Verification:** WP01's smoke test does `$container->get(...)` for all three and reports which (if any) are missing. If a binding is missing, WP01 also lands the fix in `packages/bimaaji/` — this is the one explicit exception to C-002 and is gated to that single fixup.

### AD-05 — Capability-test reuse

`AgentRunAccessPolicy` already enforces tool-capability matching against the agent definition's `requires_capability`. The mission reuses existing test fixtures (`tests/Integration/PhaseN/AgentRuntime/Fixture/`) rather than building new capability infrastructure. (C-003, FR-010)

### AD-06 — Reference agent fixture

`BimaajiDemoAgent` lives at `packages/ai-agent/tests/Fixture/BimaajiDemoAgent.php`. It uses `#[AsAgentDefinition]` with `requires_capability: ['bimaaji.read', 'bimaaji.mutate']`. The integration test runs it against a fixture entity type registered in a minimal kernel; the assertion is "executor reports `status: completed` and `audit_log` contains a non-empty PatchSet" (FR-011, SC-002).

## Test strategy

### Contract tests (`packages/ai-agent/tests/Contract/Bimaaji/`)

| Test | Coverage | Key assertions |
|---|---|---|
| `IntrospectGraphToolTest` | FR-001, FR-008, NFR-001, NFR-003 | All 6 default sections present; envelope shape; ≤200 ms median timing |
| `IntrospectSectionToolTest` | FR-002, FR-009, NFR-003 | Single section returned; unknown-section error envelope lists available keys |
| `ProposeMutationToolTest` | FR-003, FR-009, NFR-002, NFR-003 | Delegation correctness; ≤50 ms overhead vs direct `validate()`; sovereignty deny path |
| `GeneratePatchToolTest` | FR-004, FR-009, NFR-003, SC-005 | `PatchSet::toArray()` shape; filesystem unchanged before/after |
| `BimaajiToolCapabilityTest` | FR-006, FR-010, NFR-004 | Missing capability fires within ≤5 ms; error envelope is `{ok: false, error.code: 'capability_required'}` |

### Integration test (`tests/Integration/PhaseN/AgentRuntime/BimaajiAgentRunTest.php`)

Boots a minimal kernel with `BimaajiDemoAgent` registered, runs it via `AgentExecutor`, asserts:

- Run `status: completed` (no exceptions, no HITL deadlock)
- `audit_log` contains tool invocations for at least `bimaaji_introspect_section` + `bimaaji_propose_mutation` + `bimaaji_generate_patch`
- The final `PatchSet` payload is non-empty
- Run-level capability checks did not fire (positive path)

A companion `BimaajiAgentRunCapabilityTest` exercises the negative path (`requires_capability: ['bimaaji.read']` only) — the agent attempts a mutation tool and the run completes with a structured capability error in the audit log.

## WP breakdown

| WP | Title | Depends on | Authoritative surface | LOC est. |
|---|---|---|---|---|
| **WP01** | Cross-mission gate + container audit | — | `tests/Integration/PhaseN/AgentRuntime/BimaajiBindingsAuditTest.php` (+ minimal bimaaji bindings if missing) | ~80 |
| **WP02** | Read tools (Introspect{Graph,Section}) | WP01 | `packages/ai-agent/src/Tool/Bimaaji/Introspect*.php` + 2 contract tests | ~250 |
| **WP03** | Mutation tools (ProposeMutation, GeneratePatch) | WP01 | `packages/ai-agent/src/Tool/Bimaaji/{ProposeMutation,GeneratePatch}Tool.php` + 2 contract tests | ~280 |
| **WP04** | Reference agent + end-to-end integration | WP02 + WP03 | `packages/ai-agent/tests/Fixture/BimaajiDemoAgent.php` + `tests/Integration/PhaseN/AgentRuntime/BimaajiAgentRun*Test.php` | ~220 |
| **WP05** | Docs + cross-mission surface map (SC-004) + verify | WP04 | `packages/ai-agent/README.md`, `CHANGELOG.md`, `kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md` + tool-shape contract pinning for M3 | ~120 |

## File-change summary

| Layer | Path | Action |
|---|---|---|
| L5 ai-agent src | `packages/ai-agent/src/Tool/Bimaaji/IntrospectGraphTool.php` | create (WP02) |
| L5 ai-agent src | `packages/ai-agent/src/Tool/Bimaaji/IntrospectSectionTool.php` | create (WP02) |
| L5 ai-agent src | `packages/ai-agent/src/Tool/Bimaaji/ProposeMutationTool.php` | create (WP03) |
| L5 ai-agent src | `packages/ai-agent/src/Tool/Bimaaji/GeneratePatchTool.php` | create (WP03) |
| L4 bimaaji src | `packages/bimaaji/src/BimaajiServiceProvider.php` | edit only if WP01 audit finds missing binding (exception to C-002) |
| L5 ai-agent test | `packages/ai-agent/tests/Contract/Bimaaji/*Test.php` | create x5 (WP02 ×2, WP03 ×2, WP02 ×1 capability) |
| L5 ai-agent test | `packages/ai-agent/tests/Fixture/BimaajiDemoAgent.php` | create (WP04) |
| Integration | `tests/Integration/PhaseN/AgentRuntime/BimaajiBindingsAuditTest.php` | create (WP01) |
| Integration | `tests/Integration/PhaseN/AgentRuntime/BimaajiAgentRun{,Capability}Test.php` | create x2 (WP04) |
| L5 ai-agent docs | `packages/ai-agent/README.md` | edit (WP05) |
| Repo root docs | `CHANGELOG.md` | edit `[Unreleased]` (WP05) |
| Mission spec | `kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md` | create (WP05) |

## Risk analysis

### R1 — `MutationValidator` / `PatchGenerator` not container-bound after M1 (MEDIUM)

**Likelihood:** Medium. M1's `BimaajiServiceProvider` explicitly registers `ApplicationGraphGenerator` and the six providers. M1's plan does not enumerate `MutationValidator` / `PatchGenerator` bindings.
**Mitigation:** WP01's first action is the bindings audit. If either is missing, WP01 adds it under a one-line C-002 exception documented in the WP01 commit + verification.md.

### R2 — Capability mechanism doesn't accept new capability strings without registration (LOW)

**Likelihood:** Low. CLAUDE.md and ai-agent README both describe the capability mechanism as string-key driven. `requires_capability` is a list of strings; no central registry.
**Mitigation:** If a registry surface turns out to exist, add `bimaaji.read` / `bimaaji.mutate` to it in WP04 under the documented C-003 exception. WP01's smoke test exercises capability gating against an existing capability string before declaring the mechanism stable.

### R3 — `PatchSet::toArray()` includes non-serializable values (LOW)

**Likelihood:** Low. M1's WP02 stability test (NFR-003) already proves `ApplicationGraph::toArray()` is byte-stable JSON. `MutationResult` and `PatchSet` follow the same convention.
**Mitigation:** Contract tests' NFR-003 assertion round-trips through `json_encode/json_decode(JSON_THROW_ON_ERROR)` and asserts structural equality. Failure surfaces at unit-test time, not at MCP-wire time in M3.

### R4 — NFR-002 50 ms overhead budget is tight (MEDIUM)

**Likelihood:** Medium. Envelope wrapping + capability check + audit-log write could total 50 ms on slow CI.
**Mitigation:** Microbenchmark in `ProposeMutationToolTest` logs the overhead via stderr (soft 50 ms warning) and hard-caps at 250 ms. If structurally over budget, file a follow-up rather than gating the mission — the envelope shape is the real deliverable.

### R5 — SC-004 cross-mission gate enforcement (LOW)

**Likelihood:** Low. M3's first WP must import this mission's tool argument schemas and verify no breaking changes.
**Mitigation:** WP05 records the surface contract in `verification.md` (tool FQCN + argument schema + return envelope shape per tool). M3 planning references this section explicitly.

## Dependencies on downstream missions

- **M3 `bimaaji-mcp-bridge-01KS5VS8`** reuses the four tool classes' argument schemas and return envelopes as the MCP tool contract (SC-004).
- **M4 `agent-output-package-01KS5VX1`** is independent.
- **M5 `bimaaji-install-command-01KS5W0S`** is independent at the spec level.

## Charter / governance check

`.kittify/charter/charter.md` is not present in this repo. Skipped per the skill's instructions.

## Out of scope (explicit)

Per spec §Out of scope: no new bimaaji providers/operations, no MCP transport, no HTTP routes, no HITL UX changes, no cross-package safety extension.
