# Bimaaji — Application Graph & Agent Mutation Layer

<!-- Spec reviewed 2026-09-02 - #2660 Part A: bimaaji:install's seven client transformers now read a typed Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry instead of hardcoding per-client behaviour, and BimaajiInstallCommand enumerates the canonical skill set through a new SkillInventory instead of a raw list<ParsedSkill>. Behaviour-preserving (proven by a golden-snapshot regression test); the mapping still mirrors the currently shipped convention. Detail in docs/specs/bimaaji-install.md "Client capability model and skill inventory (#2660 Part A)"; the three open conventions the registry deliberately does not decide are in docs/adr/025-client-guidance-and-skill-conventions.md. -->
<!-- Spec reviewed 2026-08-29 - #2656 containment review: the ownership manifest write bypassed the sandbox boundary, and the boundary resolved only the nearest existing ancestor, never the target path — so a symlinked .waaseyaa or a target-file symlink redirected writes and the pruner rewrite outside the project root. One boundary now covers every path; a manifest that cannot be persisted is a non-zero exit. Detail in docs/specs/bimaaji-install.md "Containment (NFR-002)". -->
<!-- Spec reviewed 2026-08-29 - #2656 follow-up review: the Claude transformer emitted a flat .claude/skills/waaseyaa-<id>.md that Claude Code does not discover, and the Codex transformer wrote .codex/AGENTS.md which is not a discovery location at all; both corrected. Added retired-target pruning via InstalledManifest (recorded ownership, never inferred). Detail in docs/specs/bimaaji-install.md. -->
<!-- Spec reviewed 2026-08-29 - #2656 packaged skill resources: Implementation Status listed M5 (bimaaji-install-command-01KS5W0S) as Deferred while packages/bimaaji/src/Install/ was fully implemented. Flipped it to Shipped and recorded the #2656 change of source-of-truth: the canonical Agent Skills moved from the monorepo root (skills/waaseyaa/) into packages/bimaaji/resources/skills/, default resolution begins at the installed package, and installation is marker-bounded. Detail lives in docs/specs/bimaaji-install.md. -->
<!-- Spec reviewed 2026-08-04 - #2177 MCP enterprise hardening: Bimaaji's graph/spec provider bindings and introspection tools remain read-only discovery surfaces. Privileged graph and spec introspection is now explicitly curated and withheld from remotely reachable MCP registries unless an application grants the corresponding capability; the graph-generation contract itself is unchanged. -->
<!-- Spec reviewed 2026-06-22 - WP15 (alpha245 security, audit): MutationValidator runs sovereignty guardrails before structural validation; BimaajiServiceProvider wires SovereigntyGuardrails into the live validator binding. Acceptance: MutationValidatorTest::it_gates_sovereignty_sensitive_operations_through_the_guardrails. -->
<!-- Spec reviewed 2026-05-23 - M3 WP04 (bimaaji-mcp-bridge-01KS5VS8): added "MCP exposure" subsection enumerating the five bimaaji #[AsAgentTool] adapters surfaced over MCP through AgentToolRegistryBridge. Updated Implementation Status to flip M2 + M3 from "Deferred" to "Shipped". Bound SpecIndexProvider as a container singleton in BimaajiServiceProvider (WP02). -->
<!-- Spec reviewed 2026-05-21 - M1 (bimaaji-wakeup-01KS5VEY) flipped Implementation Status from "scaffolding only" to "shipped". -->

## Implementation Status

**Shipped (as of M1 `bimaaji-wakeup-01KS5VEY`):**

- `BimaajiServiceProvider` is auto-discovered via `extra.waaseyaa.providers` in `packages/bimaaji/composer.json`.
- `ApplicationGraphGenerator` is bound as a container singleton, wired with the six default `GraphSectionProvider` implementations (admin, entities, jsonapi, public_surface, routing, sovereignty).
- Each default provider's constructor dependencies (`EntityTypeManagerInterface`, `RouteCollection`, `SovereigntyProfile`) resolve from the kernel-services bus. `RouteCollection` falls back to `WaaseyaaRouter::getRouteCollection()`; `SovereigntyProfile` falls back to `SovereigntyProfile::Local`.
- `BimaajiServiceProvider` implements `HasNativeCommandsInterface` (empty stub; the `graph:dump` command lands in WP02 of M1).
- Unit test coverage at `packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php` (7 tests, 38 assertions: binding identity, tagged collection, six-section output, lazy resolution, capability interface, RouteCollection fallback).

**In progress (M1 follow-up WPs):**

- WP02 — `bin/waaseyaa graph:dump [--section=…] [--format=json|yaml] [--strict]` CLI command (FR-005, FR-006, FR-011..FR-013).
- WP03 — booted-kernel integration test under `tests/Integration/PhaseN/Bimaaji/` (FR-010, NFR-001, SC-005).
- WP05 — cross-mission gate proving M2 needs no further bimaaji wiring (verification artifact at `kitty-specs/bimaaji-wakeup-01KS5VEY/verification.md`).

**Shipped (M2 `ai-agent-bimaaji-tools-01KS5VKR`, 2026-05-22):**

- Four `#[AsAgentTool]` adapters under `packages/ai-agent/src/Tool/Bimaaji/`: `IntrospectGraphTool`, `IntrospectSectionTool`, `ProposeMutationTool`, `GeneratePatchTool`. Contract tests under `packages/ai-agent/tests/Contract/Bimaaji/`. Reference `BimaajiDemoAgent` + end-to-end agent-runtime tests prove the introspect → propose → generate flow against an in-memory SQLite kernel.
- `BimaajiServiceProvider` now binds `MutationValidator`, `PatchGenerator`, and `SovereigntyGuardrails` as container singletons (M2 WP01 follow-up).
- SC-004 surface contract pinned at `kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md`.

**Shipped (M3 `bimaaji-mcp-bridge-01KS5VS8`, 2026-05-23):**

- Bimaaji surfaced over MCP via `packages/mcp/`'s new bridge architecture (`McpEndpoint` + per-request `AgentToolRegistryBridge`). See "MCP exposure" below for the tool inventory and `docs/specs/mcp-endpoint.md` § "Bimaaji MCP bridge" for the transport contract.
- New fifth bimaaji tool: `bimaaji_search_specs` (substring search over `docs/specs/*.md` via `SpecIndexProvider`).
- `SpecIndexProvider` is now container-bound singleton in `BimaajiServiceProvider`.
- M3 supersedes the 2026-05-20 PHP-only deferral that closed [#1463](https://github.com/waaseyaa/framework/issues/1463).

**Shipped (M5 `bimaaji-install-command-01KS5W0S`, 2026-05-23):**

- `bin/waaseyaa bimaaji:install` — per-client guidelines/skills install command. Seven `ClientTransformerInterface` implementations under `packages/bimaaji/src/Install/Client/`; contract, flags, and trust guarantees in [docs/specs/bimaaji-install.md](bimaaji-install.md).
- The canonical Agent Skills ship as resources of this package (`packages/bimaaji/resources/skills/<id>/SKILL.md`) since #2656. They used to live at the framework monorepo root, a path no consumer has, so the command only worked in the repository that did not need it. Default resolution now begins at the installed package; `bimaaji.skills_directory` overrides it.
- Installation is marker-bounded: a re-run refreshes only the text between the `<!-- waaseyaa:bimaaji:install BEGIN -->` / `END` markers and preserves hand-authored content outside them.
- Installation is also ownership-tracked. `Waaseyaa\Bimaaji\Install\InstalledManifest` records every generated path per client in `.waaseyaa/bimaaji-install.json`, and a later run prunes targets the current skill set no longer produces. Ownership is recorded, never inferred from a filename. See [docs/specs/bimaaji-install.md](bimaaji-install.md) "Ownership and pruning retired targets".
- Every path `bimaaji:install` touches — reads, writes, deletes, neutralisations, and the ownership manifest — passes one containment boundary that rejects absolute paths and `..`, requires the nearest existing ancestor to resolve inside the project root, and rejects a target that is a link or that resolves outside it. See [docs/specs/bimaaji-install.md](bimaaji-install.md) "Containment (NFR-002)".
- Per-client target paths were re-verified against first-party vendor documentation on 2026-08-29. `claude` now writes the documented `.claude/skills/<skill-name>/SKILL.md` directory layout and `codex` the repository-root `AGENTS.md`; both previously wrote paths their client does not read. See [docs/specs/bimaaji-install.md](bimaaji-install.md) "Convention drift".
- `Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry` and `Waaseyaa\Bimaaji\Install\SkillInventory` (#2660 Part A, 2026-09-02) replace the per-transformer hardcoded target paths and the Claude-only `DIRECTORY_PREFIX` constant with one data-driven client capability model and one typed skill enumeration, without changing any generated output. See [docs/specs/bimaaji-install.md](bimaaji-install.md) "Client capability model and skill inventory (#2660 Part A)" and [docs/adr/025-client-guidance-and-skill-conventions.md](../adr/025-client-guidance-and-skill-conventions.md) for the three conventions this deliberately leaves as open maintainer decisions.

## Purpose

Bimaaji provides machine-readable introspection of a booted Waaseyaa application and a safe mutation protocol for AI agents. It answers: "What does this application contain, and how can an agent safely change it?"

The name comes from Anishinaabemowin *bimaaji* — "to give life to" — reflecting its role in making the application's structure visible and actionable.

## Architecture

Bimaaji sits at **Layer 5 (AI)** alongside `ai-schema`, `ai-agent`, `ai-pipeline`, and `ai-vector`. It reads from lower layers (entity system, routing, access control, admin surface) but never writes to them directly — mutations go through a validated protocol.

```
┌─────────────────────────────────────────────┐
│                 ApplicationGraph             │
│  ┌──────────┬──────────┬──────────┬───────┐  │
│  │ entities │ routing  │  jsonapi │ admin │  │
│  │ section  │ section  │  section │ sect. │  │
│  ├──────────┼──────────┼──────────┼───────┤  │
│  │sovereignty│ public  │ spec    │  ...  │  │
│  │ section  │ surface  │ index   │       │  │
│  └──────────┴──────────┴──────────┴───────┘  │
├─────────────────────────────────────────────┤
│         GraphSectionProviderInterface        │
├─────────────────────────────────────────────┤
│  MutationRequest → Validator → MutationResult│
└─────────────────────────────────────────────┘
```

## Core Concepts

### Application Graph

A versioned, deterministic JSON document describing the full application structure. Built by `ApplicationGraphGenerator` from registered `GraphSectionProviderInterface` implementations.

**Key types:**
- `GraphSection` — immutable DTO: `key`, `version`, `data`
- `GraphSectionProviderInterface` — `getKey(): string`, `provide(): GraphSection`
- `ApplicationGraph` — versioned container of sections, `toArray()` serializes to JSON-safe structure
- `ApplicationGraphGenerator` — composes providers, handles failures (log+skip unless strict mode)

### Graph Sections

Each section maps a subsystem:

| Section key | Source | Provider |
|-------------|--------|----------|
| `entities` | `EntityTypeManager::getDefinitions()` | `EntityIntrospectionProvider` |
| `jsonapi` | Route collection + `ResourceSerializer` | `JsonApiIntrospectionProvider` |
| `admin` | `AbstractAdminSurfaceHost::buildCatalog()` | `AdminIntrospectionProvider` |
| `routing` | `RouteCollection` options/defaults | `RoutingIntrospectionProvider` |
| `sovereignty` | `SovereigntyProfile` enum | `SovereigntyIntrospectionProvider` |
| `public_surface` | SSR paths + auth classification | `PublicSurfaceProvider` |
| `spec_index` | `docs/specs/*` file index | `SpecIndexProvider` |

### Mutation Protocol

Request/result types for agent-safe changes. No filesystem writes — the protocol validates intent against the application graph.

- `MutationRequest` — what the agent wants to change (entity type, field, route, etc.)
- `MutationResult` — success/failure with error codes, validated against graph
- Sovereignty violations delegated to guardrail rules
- `entityType` and non-null `field` identifiers are restricted to `^[a-z][a-z0-9_]*$`; invalid names fail validation before they can become suggested paths.

### Patch Generator

Converts accepted `MutationResult` into reviewable patches:
- PHP files: AST-safe via `nikic/php-parser`, round-trip tested
- Non-PHP: constrained operations with risk flags
- Output: file path, content hashes, diff text
- Defense in depth: `PatchGenerator` independently evaluates the same identifier allowlist and derives `PatchEntry::$unsafe` from that result. A forged successful `MutationResult` with path-breaking identifiers is therefore visibly unsafe even if it bypasses `MutationValidator`. Patch generation still never writes to disk.

### Sovereignty Guardrails

Declarative rules that disallow mutations violating the deployment posture per `SovereigntyProfile`. Integrated as mutation validators.

## File Layout

```
packages/bimaaji/
├── src/
│   ├── Graph/
│   │   ├── ApplicationGraph.php
│   │   ├── ApplicationGraphGenerator.php
│   │   ├── GraphSection.php
│   │   └── GraphSectionProviderInterface.php
│   ├── Introspection/
│   │   ├── Entity/
│   │   ├── JsonApi/
│   │   ├── Admin/
│   │   ├── Routing/
│   │   ├── Sovereignty/
│   │   └── PublicSurface/
│   ├── Mutation/
│   │   ├── MutationRequest.php
│   │   └── MutationResult.php
│   ├── Patch/
│   ├── Policy/
│   └── Spec/
├── tests/
│   ├── Unit/
│   └── Integration/
└── resources/
    └── schema/
```

## Dependencies

- `waaseyaa/foundation` — `SovereigntyProfile`, `LoggerInterface`
- `waaseyaa/entity` — `EntityTypeManagerInterface`, `EntityTypeInterface`
- `waaseyaa/routing` — route collection access
- `waaseyaa/api` — `ResourceSerializer` mapping
- `waaseyaa/admin-surface` — catalog introspection
- `nikic/php-parser` — AST-safe patch generation

## Design Decisions

1. **Read-only introspection, write-only mutation** — Bimaaji never modifies application state during introspection. Mutations are separate, validated, and produce patches for human review.
2. **Non-fatal provider failures** — A broken introspection provider logs a warning and is omitted from the graph, unless strict mode is enabled.
3. **Versioned graph schema** — The top-level graph and each section carry version strings for backward compatibility.
4. **No spec bodies in graph** — `spec_index` contains file paths and metadata, not full spec content, to keep the graph compact.

## MCP exposure

Bimaaji's surface is exposed to external MCP clients (Claude Code,
Cursor, Claude Desktop, etc.) via five `#[AsAgentTool]` adapters living
in `packages/ai-agent/src/Tool/Bimaaji/`. `packages/mcp/`'s
`AgentToolRegistryBridge` adapts the framework-wide
`Waaseyaa\AI\Tools\ToolRegistryInterface` to MCP's
`tools/list` + `tools/call` envelope — no per-tool MCP code exists.

| Tool name | Capability | Delegates to |
|---|---|---|
| `bimaaji_introspect_graph` | `bimaaji.read` | `ApplicationGraphGenerator::generate()->toArray()` |
| `bimaaji_introspect_section` | `bimaaji.read` | `ApplicationGraphGenerator::generate()->getSection($key)` |
| `bimaaji_propose_mutation` | `bimaaji.mutate` | `MutationValidator::validate()` |
| `bimaaji_generate_patch` | `bimaaji.mutate` | `PatchGenerator::generate()` — never writes to disk |
| `bimaaji_search_specs` | `bimaaji.read` | `SpecIndexProvider` + substring search over `docs/specs/*.md` |

Capability gating runs at the tool layer:
`AbstractAgentTool::requireCapability($cap, $account)` checks
`$account->hasPermission($cap)` and returns a `forbidden` envelope on
miss. The integrating application's permission model (typically
session middleware + role/policy stack) owns capability grants.

For full transport, authentication, and per-request bridge construction
details — see `docs/specs/mcp-endpoint.md` § "Bimaaji MCP bridge".
