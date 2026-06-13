# Bimaaji MCP Bridge

**Mission:** `bimaaji-mcp-bridge-01KS5VS8`
**Status:** Spec
**Target branch:** `main`
**Cross-references:** Design doc `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` (M3 of 5). Depends on: M1 `bimaaji-wakeup-01KS5VEY`, soft-depends on M2 `ai-agent-bimaaji-tools-01KS5VKR` (this mission reuses the tool surface validated by M2). Supersedes the 2026-05-20 M-G mission (`bimaaji-mcp-strategic-direction-01KS3SZB`) decision that bimaaji ships PHP-only. Closes / supersedes [#1463](https://github.com/waaseyaa/framework/issues/1463).

## Why this mission exists

The 2026-05-21 investigation found bimaaji is functionally rich (graph introspection, validated mutation pipeline, sovereignty guardrails) but **unreachable by external AI agents**. The 2026-05-20 M-G mission deliberately deferred external transport to a follow-up because the MCP scaffolding it inherited (Node-based `server.js`) was broken in consumer installs. That decision was correct given the broken scaffolding; it is wrong as a permanent posture.

Comparison research (2026-05-21) confirmed the canonical pattern with Laravel Boost: a Composer dev dependency that registers a stdio MCP server exposing ~12 read-mostly tools plus one mutation surface (`tinker`, which is raw PHP eval — Boost's key safety weakness). Boost's success demonstrates that an external MCP surface is precisely what makes an introspection package useful to agents working on the app — Claude Code, Cursor, Codex, Copilot, Gemini CLI, Windsurf, Junie. Without it, bimaaji is a tree falling in an empty forest.

This mission reverses M-G's deferral and ships the external transport via `packages/mcp/` (which is M-G's "Option 2" path). It does **not** reintroduce Node scaffolding — the MCP server is PHP, hosted by the existing `packages/mcp/` infrastructure. The mutation surface is exposed but **gated by a per-session capability token** that defaults off, providing a strictly safer story than Boost's `tinker`.

**The contract.** Eight read tools and two write tools registered with `packages/mcp/`, each delegating to bimaaji via the surface validated in M2. Capability gating uses the existing `ai-agent` capability mechanism (M2's `bimaaji.read` / `bimaaji.mutate` strings). The mutation tools return `PatchSet`s — they do not write to disk; the consuming MCP client (Claude Code, Cursor, etc.) is responsible for reviewing and applying patches.

## User scenarios

### Primary flow: an external agent introspects a Waaseyaa app over MCP

1. A developer installs `waaseyaa/framework` in their app and the MCP server is auto-registered via `packages/mcp/`'s discovery.
2. They configure Claude Code: `claude mcp add -s local -t stdio waaseyaa php bin/waaseyaa mcp:serve` (or equivalent — exact command finalized by the plan based on `packages/mcp/`'s current entry point).
3. Inside Claude Code, the agent sees ten tools prefixed `bimaaji_`: 8 read tools, 2 write tools. By default only the 8 read tools are callable.
4. The agent calls `bimaaji_list_entities`. The MCP server resolves `EntityIntrospectionProvider`, returns the section payload.
5. The agent reasons over the result and continues.

### Primary flow: a developer opts in to mutation tools

1. The developer wants Claude Code to be able to propose patches for the app. They configure the MCP session with `--capability=bimaaji.mutate` (or set an env var per the plan's chosen mechanism).
2. The MCP server's session capability token now includes `bimaaji.mutate`. The 2 write tools become callable.
3. The agent calls `bimaaji_propose_mutation`, gets a `MutationResult`, calls `bimaaji_generate_patch`, gets a `PatchSet`.
4. The patches are returned to Claude Code. Claude Code's UI displays them as diff suggestions for the user to review and apply.
5. The MCP server never writes files. The disk-write responsibility is entirely on the client.

### Primary flow: a capability-locked client cannot mutate

1. Developer does not configure `bimaaji.mutate`. Default session has only `bimaaji.read`.
2. The agent (perhaps prompted by a user message) attempts to call `bimaaji_propose_mutation`.
3. The MCP server returns a structured error: `error: { code: 'capability_required', capability: 'bimaaji.mutate' }`.
4. The agent reports the missing capability back to the user. The user can either grant the capability (restart MCP session with `--capability=bimaaji.mutate`) or instruct the agent to use a read-only approach.

### Edge cases

- **MCP transport disconnection mid-tool.** The bimaaji tool itself is synchronous and idempotent. If the client disconnects, no state leaks — there's nothing to roll back.
- **Tool name collision with another MCP server.** Bimaaji tool names are prefixed `bimaaji_` (e.g. `bimaaji_application_info`). The plan documents the prefix as the namespacing strategy.
- **Sovereignty profile denies a mutation.** Same as M2: the deny reason surfaces in the result envelope.
- **Large graph payload.** `bimaaji_application_info` may return tens of KB on a real app. The MCP transport must handle large stdio frames; if `packages/mcp/` has a default cap, the plan exposes it and documents the limit.
- **External client tries a destructive tool that bimaaji classifies as `destructive: false`.** Bimaaji's write tools all return `PatchSet`s — they don't write. From bimaaji's perspective they are not destructive. The MCP client may classify them as destructive if it wants to require user approval; that's a client decision.

## Requirements

### Functional

| ID | Status | Requirement |
|---|---|---|
| FR-001 | Mandatory | `packages/mcp/` registers a `bimaaji` tool family. Each tool is implemented as a class delegating to bimaaji via the surface validated in M2 (no direct provider invocation — the tools call M2's adapter classes if M2 lands first, or call bimaaji directly using M2's chosen argument shapes if filed in parallel). |
| FR-002 | Mandatory | Read tools registered (8 total, all `capability: 'bimaaji.read'`): `bimaaji_application_info` (full graph), `bimaaji_list_routes` (Routing section), `bimaaji_list_jsonapi` (JsonApi section), `bimaaji_list_entities` (Entities section), `bimaaji_list_admin` (Admin section), `bimaaji_sovereignty_profile` (Sovereignty section), `bimaaji_public_surface` (PublicSurface section), `bimaaji_search_specs` (searches `docs/specs/` — see FR-005). |
| FR-003 | Mandatory | Write tools registered (2 total, `capability: 'bimaaji.mutate'`): `bimaaji_propose_mutation` (delegates to `MutationValidator`), `bimaaji_generate_patch` (delegates to `PatchGenerator`). Both return JSON-serializable result envelopes. **Neither writes to disk.** |
| FR-004 | Mandatory | The MCP server enforces capability gating per session. Default session capability set: `['bimaaji.read']`. Operators opt in to mutation via a documented session-config mechanism (env var, CLI flag, or config file — planner picks; the chosen mechanism is documented in `docs/specs/mcp-endpoint.md`). |
| FR-005 | Mandatory | `bimaaji_search_specs` searches `docs/specs/*.md` (mirroring `packages/bimaaji/src/Spec/SpecIndexProvider.php`). Takes a `query: string` argument. Returns a list of matching spec files with file path, section title, and a snippet around the match. Mirrors Boost's `search-docs` for Laravel package docs but scoped to our doctrine specs. |
| FR-006 | Mandatory | Tool naming uses the `bimaaji_` prefix consistently. No tool name collides with other MCP servers a consumer might also register. |
| FR-007 | Mandatory | `docs/specs/mcp-endpoint.md` is updated: the 2026-05-20 "Bimaaji MCP positioning (PHP-only)" section is superseded by a new "Bimaaji MCP bridge" section documenting the 10 tools, capability gating, and the M-G → M3 transition rationale. The old section is preserved (with a clearly marked supersession note) to keep the audit trail. |
| FR-008 | Mandatory | `docs/specs/bimaaji.md` adds an MCP exposure section enumerating the same 10 tools and pointing to `docs/specs/mcp-endpoint.md` for transport details. |
| FR-009 | Mandatory | Integration test `tests/Integration/PhaseN/Mcp/BimaajiMcpReadTest.php` boots the MCP server, lists tools, invokes each of the 8 read tools, asserts each returns a non-error result envelope. |
| FR-010 | Mandatory | Integration test `BimaajiMcpMutateTest.php` boots the MCP server with `bimaaji.mutate` granted, invokes `bimaaji_propose_mutation` + `bimaaji_generate_patch`, asserts a non-empty `PatchSet` is returned and no disk writes occurred. |
| FR-011 | Mandatory | Integration test `BimaajiMcpCapabilityTest.php` boots the MCP server with default capabilities only, attempts `bimaaji_propose_mutation`, asserts a structured `capability_required` error with no execution side effects. |
| FR-012 | Mandatory | GitHub issue #1463 is closed (or superseded by a new tracking issue) referencing this mission and the M-G decision being reversed. The PR's `Closes` footer handles the auto-close. |

### Non-functional

| ID | Status | Threshold |
|---|---|---|
| NFR-001 | Mandatory | Tool registration in `packages/mcp/` adds ≤ 10 ms to MCP server boot time (median, on a clean test kernel). |
| NFR-002 | Mandatory | Each read tool's response time is ≤ M2's read-tool budget plus ≤ 20 ms MCP serialization overhead. (If M2 lands first, the budget is concrete; otherwise the plan picks a placeholder and revises after M2's measurements.) |
| NFR-003 | Mandatory | Capability check (FR-004) fires before any tool work and returns within 5 ms on rejection. No information leakage beyond the required capability name. |
| NFR-004 | Mandatory | The MCP server logs each tool invocation with the tool name, session capability set, and outcome (success / capability_denied / sovereignty_denied / error) via the framework's existing logger interface. No PII or patch content in logs by default. |
| NFR-005 | Mandatory | Tool name prefix discipline: no two `bimaaji_*` tools share a name; no `bimaaji_*` tool name conflicts with the framework's other registered MCP tools. CI-checkable assertion if `packages/mcp/` exposes a registry-level uniqueness check. |

### Constraints

| ID | Status | Constraint |
|---|---|---|
| C-001 | Mandatory | The MCP bridge lives in `packages/mcp/` (Layer 6, Interfaces). Tools delegate to `packages/bimaaji/` (Layer 5) — that's a downward import, allowed. No upward imports. `bin/check-package-layers` passes. |
| C-002 | Mandatory | No Node / JavaScript MCP scaffolding. The MCP server is PHP, hosted by `packages/mcp/`. Reintroducing Node scaffolding is explicitly out of scope. |
| C-003 | Mandatory | Mutation tools never write to disk from inside the MCP server. They return `PatchSet`s. This invariant matches M2 C-005 and is the key safety advantage over Boost's `tinker`. |
| C-004 | Mandatory | No changes to `packages/bimaaji/` are required by this mission. If a contract change is needed, it returns to M2 (or to bimaaji directly) — this mission is pure transport. |
| C-005 | Mandatory | `docs/specs/mcp-endpoint.md`'s existing "Bimaaji MCP positioning (2026-05-20)" section is **not deleted** — it is annotated with a supersession note pointing to this mission. Preserves the audit trail. |
| C-006 | Mandatory | `composer verify` is green on the merge commit. All CI gates (`bin/check-package-layers`, `bin/check-dead-code`, `bin/check-composer-policy`, `bin/check-getquery-bindings`) pass. |
| C-007 | Mandatory | No CI hooks bypassed. |

## Success criteria

| ID | Metric | How verified |
|---|---|---|
| SC-001 | An external MCP client (e.g. Claude Code) can connect to a Waaseyaa app and call the 8 read tools. | `BimaajiMcpReadTest` passes; manual smoke test in WP05 against a local Claude Code MCP session. |
| SC-002 | Mutation tools are callable only when `bimaaji.mutate` capability is granted; default sessions cannot mutate. | `BimaajiMcpCapabilityTest` passes; `BimaajiMcpMutateTest` passes only with the capability granted. |
| SC-003 | The MCP server never writes files. | `BimaajiMcpMutateTest` asserts no filesystem mutations occurred. |
| SC-004 | The 2026-05-20 M-G "PHP-only" deferral is documented as superseded. | `docs/specs/mcp-endpoint.md` shows both the original section and the supersession (FR-007). |
| SC-005 | Issue #1463 closes on merge. | GitHub auto-close via `Closes #1463` footer in the merge commit. |
| SC-006 | `composer verify` green on merge commit. | CI status check. |

## Key entities

| Entity | Role | Net change |
|---|---|---|
| `Waaseyaa\Mcp\Tool\Bimaaji\*` (10 tool classes, new) | MCP tool implementations. | +10 files. |
| `packages/mcp/src/ServiceProvider.php` (or equivalent) | Registers the bimaaji tool family. | Edit. |
| Session-capability config mechanism | Operator opt-in for mutation. | +1 file or edit (plan picks). |
| `docs/specs/mcp-endpoint.md` | Doctrine spec. | Edit: supersession + tool inventory. |
| `docs/specs/bimaaji.md` | Doctrine spec. | Edit: MCP exposure section. |
| Integration tests (3 files) | Read + mutate + capability tests. | +3 files. |
| `packages/mcp/README.md` | Public README. | Edit: bimaaji tool family section. |
| `CHANGELOG.md` | `[Unreleased]` entry. | Edit. |
| GitHub #1463 | Tracking issue. | Closed by merge commit. |

## Assumptions

- M1 is merged (or, if filed in parallel, M3's WP01 is gated on M1's `BimaajiServiceProvider` shipping).
- M2 is merged or in advanced progress (the tool-API shape M3 wraps is M2's). If M2 has not yet merged when M3 starts, WP01 explicitly imports M2's tool argument schemas as the canonical API contract.
- `packages/mcp/` already supports stdio transport and tool registration via attribute or service-tag discovery. If not, the plan adds the minimal registration mechanism; if `packages/mcp/` is too immature to host the bimaaji bridge cleanly, the mission re-scopes to "build the registration mechanism + bimaaji as the first consumer."
- The capability mechanism (M2's `bimaaji.read` / `bimaaji.mutate`) is string-based and reusable in the MCP session-config context.

## Out of scope

- Reintroducing Node-based MCP scaffolding (explicitly forbidden by C-002).
- HTTP transport for MCP. (stdio only; HTTP MCP can be a future mission.)
- HITL approval UX inside the MCP server — the MCP client is responsible for surfacing patches for user approval.
- A multi-tenant capability-token grant mechanism — single-session capability config is enough for beta.
- Adding new bimaaji introspection providers, mutation operations, or guardrails.
- Per-client install commands (`bimaaji:install <client>` is M5).

## WP outline (for /spec-kitty.plan)

The planner is free to revise. Indicative shape:

- **WP01 — Cross-mission gate + MCP infrastructure.** Verify M1 merged. Confirm `packages/mcp/` exposes a tool-registration mechanism; if not, add the minimum needed (one-class registration + stdio dispatch). Import M2's tool argument schemas (or define the canonical shape if M2 not yet merged — coordinate via cross-mission contract).
- **WP02 — Read tools.** All 8 read tools registered + `BimaajiMcpReadTest`. Includes `bimaaji_search_specs` which is more than a thin adapter — it needs a spec-search backend (use bimaaji's `SpecIndexProvider` or grep-based fallback).
- **WP03 — Capability gating + mutation tools.** Implement the per-session capability mechanism (FR-004). Register the 2 mutation tools. `BimaajiMcpMutateTest` + `BimaajiMcpCapabilityTest`. SC-003 disk-write assertion.
- **WP04 — Spec edits + supersession.** `docs/specs/mcp-endpoint.md` supersession (FR-007). `docs/specs/bimaaji.md` MCP section (FR-008). `packages/mcp/README.md` edit.
- **WP05 — Cross-mission gate + verify.** Manual smoke test against Claude Code (or another MCP client). Close #1463. `composer verify`. CHANGELOG.

## References

- 2026-05-20 M-G mission: `archive/bimaaji-mcp-strategic-direction-01KS3SZB/` (if archived) — the deferral this mission supersedes.
- `docs/specs/mcp-endpoint.md` §"Bimaaji MCP positioning (2026-05-20)".
- Laravel Boost research summary: `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §"Context" — comparison source.
- `packages/bimaaji/src/Spec/SpecIndexProvider.php` — basis for `bimaaji_search_specs`.
- M1 `bimaaji-wakeup-01KS5VEY` — provides container-resolvable bimaaji surface.
- M2 `ai-agent-bimaaji-tools-01KS5VKR` — validates the tool API shape M3 wraps.
- GitHub #1463 — bimaaji MCP exposure tracker (to be closed).
- Memory: `feedback_pr_traceability_signals` — close issues via `Closes #N` footer.
