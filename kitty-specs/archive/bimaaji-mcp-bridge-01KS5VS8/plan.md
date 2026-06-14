# Implementation Plan — bimaaji-mcp-bridge-01KS5VS8

**Mission:** `bimaaji-mcp-bridge-01KS5VS8`
**Status:** Plan (re-scoped after WP01 audit, 2026-05-22)
**Spec:** [spec.md](spec.md)
**Design doc:** `docs/history/plans/2026-05-21-ai-ecosystem-beta-tightening.md` §M3
**Depends on:** M1 `bimaaji-wakeup-01KS5VEY` (merged). M2 `ai-agent-bimaaji-tools-01KS5VKR` (merged, SC-004 anchor).
**Supersedes:** 2026-05-20 M-G `bimaaji-mcp-strategic-direction-01KS3SZB` "PHP-only" deferral.
**Closes:** GitHub #1463 via merge-commit footer.

## WP01 re-scope rationale (2026-05-22)

The original plan assumed `packages/mcp/` lacked a tool-registration mechanism
and that all ten bimaaji MCP tools (`bimaaji_application_info`,
`bimaaji_list_*`, etc.) needed to be filed under
`packages/mcp/src/Tool/Bimaaji/`. The WP01 audit found a more mature
infrastructure than the spec anticipated:

1. **Registration is already attribute-driven.** `#[AsAgentTool]` plus
   `PackageManifestCompiler` populates the `agent_tools` manifest section;
   `AttributeToolRegistry` hydrates the catalogue lazily. The mechanism is
   shipped, bound by `AiToolsServiceProvider`, and proven by ai-agent's contract
   test surface.
2. **An MCP-side bridge already exists.** `Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge`
   implements `Mcp\Bridge\ToolRegistryInterface` and
   `Mcp\Bridge\ToolExecutorInterface`, wrapping `AgentToolRegistryInterface`
   and forwarding `AccountInterface` to every `execute()` call.
3. **The four M2 SC-004 tools (`bimaaji_introspect_graph`,
   `bimaaji_introspect_section`, `bimaaji_propose_mutation`,
   `bimaaji_generate_patch`) are already first-party ai-agent tools** and the
   bridge auto-exposes them under MCP once the McpEndpoint container
   dependencies are bound — no `packages/mcp/src/Tool/Bimaaji/` files needed
   for those four.
4. **Six of the eight "new" read tools collapse into `bimaaji_introspect_section`.**
   `IntrospectSectionTool`'s input schema already enumerates the six section
   keys (admin, entities, jsonapi, public_surface, routing, sovereignty). The
   genuinely net-new behaviour is `bimaaji_application_info` (full-graph
   convenience entry — the existing `bimaaji_introspect_graph` already covers
   this; may be merged) and `bimaaji_search_specs` (the spec-search backend
   from AD-04).
5. **Foundation `McpRouter` is dead in production.** It guards on a literal
   `_controller === 'mcp.endpoint'` string that no real route ever sets —
   only unit-test fixtures do. `McpRouteProvider` registers with the
   `'Waaseyaa\\Mcp\\McpEndpoint::handle'` controller string instead. WP01
   retires the foundation router so the new endpoint owns `/mcp` dispatch.

The resulting WP shape (replaces the table in "WP breakdown" below):

| WP | New scope |
|---|---|
| **WP01** | Retire foundation `McpRouter` + `BimaajiMcpBootSmokeTest` pinning SC-004 via reflection. (no new mcp tool classes) |
| **WP02** | Wire `McpServiceProvider::register()` for `Bridge\ToolRegistryInterface` + `Bridge\ToolExecutorInterface` + `Auth\McpAuthInterface`. Add the 1–2 genuinely net-new ai-agent tools (`bimaaji_search_specs`; optional `bimaaji_application_info` if not redundant with `bimaaji_introspect_graph`). End-to-end `BimaajiMcpReadTest` exercising tool listing through the production endpoint. |
| **WP03** | Capability gating story: per-request account passthrough into `AgentToolRegistryBridge` (currently per-construction); `BimaajiMcpCapabilityTest` proving anonymous → mutation is denied without explicit grant. No new mutation tool classes — M2's `propose_mutation` and `generate_patch` already satisfy AD-03. |
| **WP04** | Doctrine spec edits as planned (`docs/specs/mcp-endpoint.md` supersession + new bridge section, `docs/specs/bimaaji.md` MCP exposure, `packages/mcp/README.md`). |
| **WP05** | `kitty-specs/.../verification.md`, CHANGELOG, #1463 close. |

AD-01, AD-02, AD-03 below are kept verbatim for spec-traceability but are
superseded by this rationale. Subsequent WPs will re-spec their owned files
against the new shape as their planning passes come up.

## Branch contract

- Current branch at plan time: `main`
- Planning + base branch: `main`
- Merge target: `main`

## Engineering alignment

Exposes bimaaji over MCP via `packages/mcp/` (Layer 6). Ten tools total: 8 read tools (default-on under `bimaaji.read`) and 2 write tools (opt-in via `bimaaji.mutate`). Write tools return `PatchSet`s — the MCP server never writes to disk (C-003). MCP transport is stdio only (no HTTP, C-002). Tool naming uses the `bimaaji_` prefix (FR-006, NFR-005) for namespacing against other registered MCP tools.

This mission reverses M-G's "PHP-only" deferral (C-005 / FR-007 / SC-004): the doctrine spec at `docs/specs/mcp-endpoint.md` keeps the original 2026-05-20 section with a supersession annotation so the audit trail survives, and adds a new "Bimaaji MCP bridge" section documenting the 10 tools.

## Architecture decisions

### AD-01 — Tool location and namespace

All ten tools live under `packages/mcp/src/Tool/Bimaaji/` in namespace `Waaseyaa\Mcp\Tool\Bimaaji\`. mcp is L6, bimaaji is L4 — downward import is allowed. Each tool class imports `Waaseyaa\Bimaaji\*` directly via `use` statements (no inline-FQCN trick needed). (C-001)

### AD-02 — Read-tool inventory (FR-002, all `capability: bimaaji.read`)

| Tool | Delegates to | Returns |
|---|---|---|
| `bimaaji_application_info` | `ApplicationGraphGenerator::generate()->toArray()` | Full graph (all 6 sections) |
| `bimaaji_list_routes` | `RoutingIntrospectionProvider->provide()->toArray()` | Routing section only |
| `bimaaji_list_jsonapi` | `JsonApiIntrospectionProvider->provide()->toArray()` | JsonApi section only |
| `bimaaji_list_entities` | `EntityIntrospectionProvider->provide()->toArray()` | Entities section only |
| `bimaaji_list_admin` | `AdminIntrospectionProvider->provide()->toArray()` | Admin section only |
| `bimaaji_sovereignty_profile` | `SovereigntyIntrospectionProvider->provide()->toArray()` | Sovereignty section only |
| `bimaaji_public_surface` | `PublicSurfaceProvider->provide()->toArray()` | PublicSurface section only |
| `bimaaji_search_specs` | (see AD-04 — spec-search backend) | `docs/specs/*.md` search results |

The seven section-scoped tools are convenience wrappers that save the agent from learning the section-key vocabulary. `bimaaji_application_info` is the full-graph entry point for agents that want everything.

### AD-03 — Write-tool inventory (FR-003, both `capability: bimaaji.mutate`)

| Tool | Delegates to | Returns |
|---|---|---|
| `bimaaji_propose_mutation` | `MutationValidator::validate()` | `MutationResult::toArray()` |
| `bimaaji_generate_patch` | `PatchGenerator::generate()` | `PatchSet::toArray()` |

Both inherit M2's tool-API shape (SC-004 from M2). If M2 is merged first, the M3 tools import M2's tool classes and wrap them as MCP tools without re-implementing argument validation or envelope construction. If M2 is not yet merged, M3 implements the shape itself and M2 inherits the M3 contract (less ideal — M2 is the designated shape-defining mission).

### AD-04 — `bimaaji_search_specs` backend

Not a thin adapter. Implementation options (planner picks at WP02 time):

1. **Use `Waaseyaa\Bimaaji\Spec\SpecIndexProvider`** if it ships with M1 — the spec-search backend bimaaji already has.
2. **Grep-based fallback:** simple text search over `docs/specs/*.md` using `glob()` + `file_get_contents()` + substring or PCRE match. Returns `{file, section_title, snippet, line_number}`.

Option 2 is the WP02 default unless inspection reveals `SpecIndexProvider` is already container-resolvable and well-tested. (FR-005)

### AD-05 — Capability gating mechanism (FR-004)

The MCP server holds a per-session capability set. Defaults: `['bimaaji.read']`. Opt-in to mutation via one of three documented mechanisms (planner picks one at WP03 time, documents the others as future extension):

1. **CLI flag:** `bin/waaseyaa mcp:serve --capability=bimaaji.mutate`
2. **Env var:** `WAASEYAA_MCP_CAPABILITIES=bimaaji.read,bimaaji.mutate`
3. **Config file:** `config/mcp.php` `capabilities` array

WP03 ships at least the env var path because env vars work uniformly across all MCP clients (Claude Code, Cursor, etc., all support env passthrough). CLI flag is a follow-up.

The capability check fires at tool-dispatch time, before any tool work. Rejection returns within 5 ms (NFR-003) and the envelope leaks no detail beyond `{ error: { code: 'capability_required', capability: 'bimaaji.mutate' } }`.

### AD-06 — Logging (NFR-004)

Each tool invocation logs `{tool, capability_set, outcome}` via `Waaseyaa\Foundation\Log\LoggerInterface`. Outcomes: `success`, `capability_denied`, `sovereignty_denied`, `error`. No PII (the spec is explicit — no patch content, no user identifiers). Logging is best-effort: a logger failure must not crash the tool dispatch (per CLAUDE.md gotcha).

### AD-07 — Tool-name uniqueness gate (NFR-005)

`packages/mcp/`'s registry probably enforces uniqueness at registration time. If it doesn't, WP01 adds a registration-time assertion (`array_key_exists` check) that throws on duplicate. The `bimaaji_` prefix discipline keeps M3's tools from colliding with other framework-registered MCP tools (e.g. `agent_*`, `migration_*`, etc.).

### AD-08 — Doctrine spec edits (FR-007, FR-008, C-005)

`docs/specs/mcp-endpoint.md`:
- Existing 2026-05-20 "Bimaaji MCP positioning (PHP-only)" section is **preserved verbatim** with a supersession callout at the top pointing to this mission and the new section.
- New "Bimaaji MCP bridge" section enumerates the 10 tools, capability gating, session-config mechanism, and the M-G → M3 transition rationale.

`docs/specs/bimaaji.md`:
- New "MCP exposure" subsection enumerating the 10 tools and pointing to `mcp-endpoint.md` for transport details.

## Test strategy

### Integration tests (`tests/Integration/PhaseN/Mcp/`)

| Test | Coverage | Key assertions |
|---|---|---|
| `BimaajiMcpReadTest` | FR-002, FR-009, SC-001 | All 8 read tools list, each returns a non-error envelope |
| `BimaajiMcpMutateTest` | FR-003, FR-010, SC-002, SC-003 | With `bimaaji.mutate` granted, both write tools execute; PatchSet non-empty; **no disk writes** before/after snapshot |
| `BimaajiMcpCapabilityTest` | FR-004, FR-011, NFR-003 | Default capability set; mutation attempt returns `capability_required` error within 5 ms; no side effects |

Boot pattern: the WP01 MCP-server smoke test establishes the minimum kernel + MCP-server harness; WP02/WP03 reuse it.

### Charter / governance

`.kittify/charter/charter.md` absent. Skipped.

## WP breakdown

| WP | Title | Depends on | Authoritative surface | LOC est. |
|---|---|---|---|---|
| **WP01** | Cross-mission gate + MCP infrastructure audit | — | `tests/Integration/PhaseN/Mcp/BimaajiMcpBootSmokeTest.php` (+ minimal mcp registration if missing) | ~150 |
| **WP02** | 8 read tools + spec-search backend | WP01 | `packages/mcp/src/Tool/Bimaaji/{ApplicationInfoTool,List*Tool,SovereigntyProfileTool,PublicSurfaceTool,SearchSpecsTool}.php` + `BimaajiMcpReadTest.php` | ~400 |
| **WP03** | Capability gating + 2 mutation tools | WP02 | `packages/mcp/src/{Capability/*,Tool/Bimaaji/{ProposeMutation,GeneratePatch}Tool}.php` + 2 integration tests | ~350 |
| **WP04** | Doctrine spec edits + supersession | WP03 | `docs/specs/mcp-endpoint.md`, `docs/specs/bimaaji.md`, `packages/mcp/README.md` | ~150 |
| **WP05** | Cross-mission gate + verify + #1463 close | WP04 | `kitty-specs/bimaaji-mcp-bridge-01KS5VS8/verification.md` + manual smoke test against Claude Code | ~80 |

## File-change summary

| Layer | Path | Action |
|---|---|---|
| L6 mcp src | `packages/mcp/src/Tool/Bimaaji/*.php` | create x10 (WP02 ×8, WP03 ×2) |
| L6 mcp src | `packages/mcp/src/Capability/SessionCapabilities.php` (or similar) | create (WP03) |
| L6 mcp src | `packages/mcp/src/ServiceProvider.php` | edit (WP02 — register the tool family) |
| L6 mcp tests | `tests/Integration/PhaseN/Mcp/BimaajiMcp{Boot,Read,Mutate,Capability}Test.php` | create x4 (WP01 + WP02 + WP03) |
| Spec | `docs/specs/mcp-endpoint.md` | edit (WP04 — supersession + new section) |
| Spec | `docs/specs/bimaaji.md` | edit (WP04 — MCP exposure section) |
| L6 mcp docs | `packages/mcp/README.md` | edit (WP04) |
| Root docs | `CHANGELOG.md` | edit `[Unreleased]` (WP05) |
| Mission | `kitty-specs/bimaaji-mcp-bridge-01KS5VS8/verification.md` | create (WP05) |

## Risk analysis

### R1 — `packages/mcp/` is too immature to host the bridge (MEDIUM)

**Likelihood:** Medium per spec Assumption: "If `packages/mcp/` is too immature... re-scope to build registration mechanism + bimaaji as first consumer."
**Mitigation:** WP01's first action is the audit. If a tool-registration mechanism doesn't exist, WP01 adds the minimum needed (attribute-based discovery OR service-tag, planner picks). Re-scope notice in `verification.md` if the bar moves materially.

### R2 — Coordination with M2 (MEDIUM)

**Likelihood:** Medium if M3 starts before M2 merges. M2 owns the tool-API shape M3 wraps.
**Mitigation:** WP01 imports M2's tool argument schemas explicitly. If M2 not yet on main, WP01 reads M2's `wps.yaml` + `tasks/WP02-read-tools.md` + `tasks/WP03-mutation-tools.md` for the canonical contract, then files the bimaaji MCP tools with the same shape. M2's WP05 verification.md is the SC-004 anchor.

### R3 — Large stdio payload (LOW)

**Likelihood:** Low. MCP stdio uses length-prefixed framing; large frames work in modern clients.
**Mitigation:** WP01 confirms `packages/mcp/`'s stdio handler doesn't cap frame size. If a cap exists, document it in `docs/specs/mcp-endpoint.md` and surface it to the agent in the tool description.

### R4 — Tool-name collision (LOW)

**Likelihood:** Low. `bimaaji_` prefix discipline + NFR-005 uniqueness gate.
**Mitigation:** WP02 registers all tools through a single ServiceProvider entry point; the registry-uniqueness check (or a custom assertion in WP01) catches collisions at boot time.

### R5 — Capability mechanism doesn't pass through MCP clients (LOW)

**Likelihood:** Low. All major MCP clients pass env vars to the server process. CLI args are less universally supported.
**Mitigation:** WP03 ships the env var path first (FR-004). CLI flag and config-file paths follow once the env var path is proven.

### R6 — `SpecIndexProvider` doesn't exist or isn't container-bound (LOW)

**Likelihood:** Low — the spec references it, but provider readiness depends on M1's actual scope.
**Mitigation:** WP02's AD-04 fallback (grep-based search) is the documented escape hatch. Plan tracks this as a hard call-out so the implementer doesn't try to wire something that isn't there.

## Dependencies on downstream missions

- **M5** (`bimaaji-install-command-01KS5W0S`) likely ships per-client config snippets for `bin/waaseyaa mcp:serve` that this mission's WP05 references. M3's spec doesn't gate on M5 but coordinates documentation phrasing.

## Charter / governance check

`.kittify/charter/charter.md` not present. Skipped.

## Out of scope (per spec)

Per spec §Out of scope: no Node scaffolding, no HTTP transport, no HITL inside the server, no multi-tenant capability tokens, no new providers/operations/guardrails, no per-client install commands (M5).
