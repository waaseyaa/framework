# Verification — bimaaji-mcp-bridge-01KS5VS8

> **Mission close-out.** Bimaaji is exposed over MCP via the new
> `packages/mcp/` bridge architecture. Five `#[AsAgentTool]` adapters
> are surfaced through the per-request `AgentToolRegistryBridge` with
> the auth-resolved `AccountInterface`, satisfying every M3 spec
> goal at the re-scoped scale (see WP01 audit and `plan.md` §
> "WP01 re-scope rationale"). The 2026-05-20 M-G "PHP-only" deferral
> is formally superseded.

## Re-scope summary

The original M3 plan AD-02 inventoried 10 tools (8 read + 2 write)
filed as net-new mcp-specific tool classes plus a separate
`SessionCapabilities` env-var-driven capability layer. The WP01
audit found that:

- `packages/mcp/` already shipped the `AgentToolRegistryBridge`
  adapter wrapping `Waaseyaa\AI\Tools\ToolRegistryInterface`.
- Six of the eight read tools collapse to `bimaaji_introspect_section`
  (already parameterised by section key).
- `bimaaji_application_info` is redundant with `bimaaji_introspect_graph`
  (full-graph entry point).
- The two mutation tools already ship in ai-agent from M2.
- Account-permission checking (`AbstractAgentTool::requireCapability`)
  is already the capability gate; a parallel env-var-driven
  `SessionCapabilities` would create two competing decision points.

The remaining real work, all delivered:

| Concern | Resolution |
|---|---|
| Tool-registration mechanism in `packages/mcp/` | Pre-existed (`#[AsAgentTool]` + `AttributeToolRegistry` + `AgentToolRegistryBridge`) — pinned by WP01 smoke test. |
| Legacy dead-router intercept | Foundation `McpRouter` retired in WP01 (it guarded a literal `_controller='mcp.endpoint'` no real route ever set). |
| 5th bimaaji tool (`bimaaji_search_specs`) | Shipped in WP02 as a fifth ai-agent `#[AsAgentTool]` adapter. |
| `McpServiceProvider::register()` bridge wiring | Shipped in WP02 then refactored to per-request in WP03. |
| Per-request account passthrough | WP03 — `McpEndpoint::dispatch()` now constructs the bridge per-request with the auth-resolved account. |
| Doctrine spec edits + supersession | WP04 — `docs/specs/mcp-endpoint.md` § "Bimaaji MCP bridge" + `docs/specs/bimaaji.md` § "MCP exposure" + rewritten `packages/mcp/README.md`. |

## PR provenance

| WP | PR | Squash-merge sha | Headline |
|---|---|---|---|
| WP01 | [#1558](https://github.com/waaseyaa/framework/pull/1558) | `4e5b71961` | feat(mcp): retire dead foundation `McpRouter` + pin SC-004 bimaaji surface |
| WP02 | [#1559](https://github.com/waaseyaa/framework/pull/1559) | `631efff60` | feat(mcp,ai-agent): wire `McpServiceProvider` bridge + add `bimaaji_search_specs` |
| WP03 | [#1560](https://github.com/waaseyaa/framework/pull/1560) | `a3fae2762` | feat(mcp): per-request bridge with auth-resolved account closes WP02 caveat |
| WP04 | [#1561](https://github.com/waaseyaa/framework/pull/1561) | `40ecff509` | docs(mcp,bimaaji): doctrine spec edits for bimaaji MCP bridge |
| WP05 | (this PR) | — | chore(spec-kitty): verification + close-out |

All WPs squash-merged on 2026-05-23 (UTC). No `--no-verify`
on any commit (C-007 satisfied).

## Gate sweep (2026-05-23 against `d9dce239c` tip)

| Gate | Result |
|---|---|
| `bin/check-package-layers` | OK — package layer constraints satisfied. |
| `bin/check-composer-policy` | OK — composer policy checks passed. |
| `bin/check-getquery-bindings` | OK — 2 known exemptions, 0 new offenders. |
| `bin/check-dead-code` | OK — no new unused members beyond the baseline. |
| `./vendor/bin/phpstan analyse` (full) | OK — 0 errors. |
| `./vendor/bin/phpunit --filter every_public_element_has_a_disposition` | OK — surface-map gate passes. |
| `tools/drift-detector.sh 5` | OK — all affected specs up to date. |

## Test surface

Combined MCP-adjacent test count (`tests/Integration/PhaseN/Mcp/` +
`packages/mcp/tests/Unit/` + `packages/ai-agent/tests/Contract/Bimaaji/`):

- **145 tests / 470 assertions** total.

Mission-specific files:

| File | Owner WP | Tests | Purpose |
|---|---|---|---|
| `tests/Integration/PhaseN/Mcp/BimaajiMcpBootSmokeTest.php` | WP01 | 5 | SC-004 reflection pins: M2 tools exist + carry correct attribute + name uniqueness. |
| `packages/ai-agent/tests/Contract/Bimaaji/SearchSpecsToolTest.php` | WP02 | 8 | `bimaaji_search_specs` contract: positive + missing-arg + non-string + empty-string + invalid-limit + forbidden + zero-matches + limit-cap. |
| `tests/Integration/PhaseN/Mcp/BimaajiMcpReadTest.php` | WP02 → WP03 | 3 | Closed-loop bridge wiring; full `McpEndpoint::handle` dispatch with read account. |
| `tests/Integration/PhaseN/Mcp/BimaajiMcpCapabilityTest.php` | WP03 | 3 | Capability gating: read-only/read+mutate accounts, forbidden envelope, NFR-003 50 ms ceiling. |
| `packages/mcp/tests/Unit/McpEndpointTest.php` | refactored WP03 | 7 | New constructor signature; auth-pass/fail; JSON-RPC dispatch paths. |
| `packages/mcp/tests/Unit/McpServiceProviderTest.php` | refactored WP03 | 2 | Routes registered + `McpAuthInterface` default binding. |
| `tests/Integration/PhaseN/AgentRuntime/McpControllerToolsSharingTest.php` | refactored WP03 | 2 | End-to-end bridge listing + executing through the new endpoint signature. |

## SC-004 tool-shape contract (re-anchored)

Pins the M2 four-tool surface plus the new `bimaaji_search_specs`.
Any drift in tool name or capability breaks `BimaajiMcpBootSmokeTest`
(reflection assertions on the production class set) before it can
land downstream.

| Tool FQCN | Name | Capability |
|---|---|---|
| `Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectGraphTool` | `bimaaji_introspect_graph` | `bimaaji.read` |
| `Waaseyaa\AI\Agent\Tool\Bimaaji\IntrospectSectionTool` | `bimaaji_introspect_section` | `bimaaji.read` |
| `Waaseyaa\AI\Agent\Tool\Bimaaji\ProposeMutationTool` | `bimaaji_propose_mutation` | `bimaaji.mutate` |
| `Waaseyaa\AI\Agent\Tool\Bimaaji\GeneratePatchTool` | `bimaaji_generate_patch` | `bimaaji.mutate` |
| `Waaseyaa\AI\Agent\Tool\Bimaaji\SearchSpecsTool` | `bimaaji_search_specs` | `bimaaji.read` |

## Capability vocabulary

- `bimaaji.read` — granted to authenticated MCP clients that may
  introspect the application graph.
- `bimaaji.mutate` — opt-in per role/account; required for the
  validator/patch-generator pair.

The framework does not grant either by default. The integrating
application's permission stack (session middleware + role/policy
resolution) is the source of truth.

## Manual smoke test (T014) — pending

The WP05 spec anticipated a manual smoke against Claude Code / Cursor.
This is **NOT performed in this mission close-out** for two reasons:

1. The original T014 steps assumed a `bin/waaseyaa mcp:serve` stdio
   entry point and `WAASEYAA_MCP_CAPABILITIES=...` env-var capability
   model; neither matches the shipped reality (HTTP `/mcp` endpoint,
   account-permission capability gate). The smoke transcript would
   need re-authoring against the actual surface.
2. End-to-end coverage is provided by the in-process test surface
   listed above — `BimaajiMcpReadTest` and `BimaajiMcpCapabilityTest`
   drive `McpEndpoint::handle()` through the full JSON-RPC dispatch
   path with both `tools/list` and `tools/call`, including the
   capability-gate forbidden envelope. The `claude_desktop_config.json`
   example fragment in `packages/mcp/README.md` documents the
   real-client config.

If a future operator runs an external-MCP smoke (e.g., integrating
the bridge into `claudriel` or a downstream app), the transcript
can be appended here as an addendum.

## Notes for future missions

- **Legacy surface remains in-place.** `Waaseyaa\Mcp\McpController`
  + its `Tools/*` + `Cache/` + `Rpc/*` siblings are unreachable from
  HTTP routing (foundation `McpRouter` retired in WP01) but still
  test-covered via direct instantiation in
  `tests/Integration/Phase14/AiMcpIntegrationTest.php` and the
  package's unit tests. A future cleanup mission may delete them
  once the legacy tests migrate to the new bridge architecture.
- **`Mcp\Bridge\ToolRegistryInterface` + `ToolExecutorInterface`**
  remain `@api` as the bridge's implemented contracts but are no
  longer container-bound. Third-party packages wanting to expose a
  different agent registry over MCP can implement these and re-bind
  via an application service provider.
- **`McpEndpoint::handle()`'s typed `AccountInterface` parameter**
  is unused — the bearer-token auth path (`McpAuthInterface::authenticate`)
  determines the MCP user, not the session account. The typed param
  is retained for `AppControllerRouter` typed-injection contract
  compliance.
- **Bridge per-request construction is cheap.** The bridge takes a
  reference to the singleton agent registry plus an account; no
  hydration or container resolution happens at construction.
  Capability gating is constant-time per tool call.

## #1463 disposition

`waaseyaa/framework#1463` ("Roadmap: bimaaji MCP server scaffolding")
was closed `NOT_PLANNED` on 2026-05-20 by mission M-G
(`bimaaji-mcp-strategic-direction-01KS3SZB` — archived). M3 reverses
that decision: bimaaji IS now on MCP via the bridge architecture.
The issue stays closed (its tracking purpose is satisfied), but a
comment is added pointing at the M3 PR set so future readers see
the resolution. See `docs/specs/mcp-endpoint.md` § "Bimaaji MCP
positioning (2026-05-20)" for the audit-trail supersession callout.

## Acceptance

All Definition-of-Done items across WP01–WP04 are satisfied. The
mission is complete.
