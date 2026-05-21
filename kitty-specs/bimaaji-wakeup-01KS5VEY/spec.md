# Bimaaji Wakeup

**Mission:** `bimaaji-wakeup-01KS5VEY`
**Status:** Spec
**Target branch:** `main`
**Cross-references:** Design doc `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md` (M1 of 5). Blocks: M2 `ai-agent-bimaaji-tools`, M3 `bimaaji-mcp-bridge`, M5 `bimaaji-install-command`. Reverses the no-wiring posture of the 2026-05-20 M-G (`bimaaji-mcp-strategic-direction-01KS3SZB`) decision insofar as that decision deferred *external* exposure; this mission wires *internal* surfaces. Independent of M4 `agent-output-package`.

## Why this mission exists

`packages/bimaaji/` ships 25 PHP classes spanning graph introspection (6 `GraphSectionProvider`s — Entity, Routing, JsonApi, Admin, Sovereignty, PublicSurface), a validated mutation protocol (`MutationRequest` → `MutationValidator` → `PatchSet` with `SovereigntyGuardrails`), a Task DSL, and a Spec index provider. The 2026-05-21 investigation found:

- **README is stale** — claims "This repository currently contains scaffolding only; behavior will land in follow-up issues." The repo has functional implementations, not scaffolding.
- **No `ServiceProvider`** — `packages/bimaaji/composer.json` declares no `extra.waaseyaa.providers` entry. The `PackageManifestCompiler` cannot discover bimaaji.
- **Zero in-tree consumers** — `grep -r 'Waaseyaa\\Bimaaji' packages/` outside `packages/bimaaji/` returns nothing. The package is unreachable from any other part of the framework.
- **No CLI commands** — there is no `graph:dump` or equivalent. A human (or in-process agent) cannot enumerate the application graph without writing wiring code first.
- **MCP scaffolding was removed** in #1387/#1464 (alpha vintage prior to alpha.179). The 2026-05-20 M-G mission concluded bimaaji ships PHP-only and deferred external transport to a follow-up. That decision is consistent with this mission (which wires internal surfaces only) and is partially superseded by M3 (which adds the MCP transport that M-G deferred).

Comparison research (Laravel Boost, 2026-05-21) confirms the canonical agent-facing introspection package pattern: a Composer dev dependency that exposes a small, discoverable surface (Boost has ~12 MCP tools across application info, routes, schema, logs, etc.) and ships first-class `ServiceProvider` wiring. Bimaaji already has the deeper providers but lacks the wiring — making it strictly less useful than a less-capable competitor.

**The fix.** Promote bimaaji from "library with no consumers" to a wired Layer 5 package: add `BimaajiServiceProvider`, register the 6 default providers as a tagged service collection, ship `bin/waaseyaa graph:dump`, write integration tests over the booted pipeline, and refresh the README. This unblocks M2 (in-process tool sources for `ai-agent`), M3 (MCP transport), and M5 (per-client install command).

## User scenarios

### Primary flow: a developer dumps the application graph

1. A developer (or in-process agent) runs `bin/waaseyaa graph:dump`.
2. The CLI command resolves `ApplicationGraphGenerator` from the container, which has been wired with the 6 default `GraphSectionProviderInterface` implementations.
3. The generator iterates the providers, calls `provide()` on each, and aggregates the resulting `GraphSection`s into an `ApplicationGraph`.
4. The CLI command prints the graph as JSON (default) or YAML to stdout.
5. The developer can scope the dump: `bin/waaseyaa graph:dump --section=routing` returns only the routing section.

### Primary flow: a third-party package contributes a graph section

1. A package author writes `packages/foo/src/Bimaaji/FooGraphSectionProvider.php` implementing `GraphSectionProviderInterface`.
2. They register it in their `FooServiceProvider::register()` against the tagged service collection.
3. They run `bin/waaseyaa optimize:manifest` (or restart the dev server).
4. `bin/waaseyaa graph:dump` now includes the `foo` section alongside the 6 built-ins.

### Primary flow: an external consumer reads the spec to wire bimaaji

1. A consumer reads `packages/bimaaji/README.md` and learns the package is shipped (not scaffolding), enumerates the 6 default providers, and sees the contract a third-party provider must follow.
2. They follow the `docs/specs/bimaaji.md` Implementation Status section, which links to the `BimaajiServiceProvider`, the CLI command, and the integration tests as proof points.

### Edge cases

- **A provider throws.** `ApplicationGraphGenerator` is already strict-or-lenient configurable. The CLI uses lenient mode by default (logs the warning, continues) and adds `--strict` for fail-fast. Existing behavior preserved.
- **Empty graph.** If a developer disables all providers (theoretical), `graph:dump` still emits a valid `ApplicationGraph` with `sections: {}`. Exit code is still 0.
- **Section not found.** `bin/waaseyaa graph:dump --section=nonexistent` exits non-zero with a clear error and lists the available section keys.
- **JSON vs. YAML.** Default is JSON (machine-friendly). YAML is opt-in via `--format=yaml` for human reading.

## Requirements

### Functional

| ID | Status | Requirement |
|---|---|---|
| FR-001 | Mandatory | `Waaseyaa\Bimaaji\BimaajiServiceProvider` exists in `packages/bimaaji/src/`. It extends the framework's `ServiceProvider` base and is registered via `extra.waaseyaa.providers` in `packages/bimaaji/composer.json`. |
| FR-002 | Mandatory | The provider's `register()` binds `ApplicationGraphGenerator` as a singleton in the container, constructed with the tagged collection of `GraphSectionProviderInterface` services. |
| FR-003 | Mandatory | The provider registers each of the 6 default `GraphSectionProvider` implementations (Entity, Routing, JsonApi, Admin, Sovereignty, PublicSurface) into the tagged collection. Each provider's constructor dependencies (e.g. `EntityTypeManagerInterface`, `RouteCollection`, `SovereigntyProfile`) are resolved from the container. |
| FR-004 | Mandatory | Third-party packages can add providers by binding their own `GraphSectionProviderInterface` implementations to the same tagged collection. The mission documents the tag identity and lookup pattern. |
| FR-005 | Mandatory | `bin/waaseyaa graph:dump` exists as a first-party CLI command. It accepts `--section=<key>` (optional, scopes output to one section by key), `--format=json|yaml` (default `json`), and `--strict` (fail-fast on provider errors). |
| FR-006 | Mandatory | `bin/waaseyaa graph:dump` exits 0 on success, prints the (possibly scoped) `ApplicationGraph::toArray()` output to stdout in the chosen format. It exits non-zero on unknown `--section` value or on any provider error in `--strict` mode. |
| FR-007 | Mandatory | `packages/bimaaji/README.md` is rewritten: removes "scaffolding only" claim, enumerates the 6 default providers, documents the `GraphSectionProviderInterface` extension point, shows a `graph:dump` invocation, and links to `docs/specs/bimaaji.md`. |
| FR-008 | Mandatory | `docs/specs/bimaaji.md` Implementation Status section is updated: status flips from any "scaffolding" or "deferred" framing to "shipped" with the M1 mission ID. The provider inventory + service-provider contract + CLI command are documented. |
| FR-009 | Mandatory | Unit test: `BimaajiServiceProviderTest` covers `register()` — binding count, tagged-collection wiring, lazy resolution of provider dependencies. |
| FR-010 | Mandatory | Integration test under `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` boots the kernel, resolves `ApplicationGraphGenerator`, calls `generate()`, and asserts: all 6 default sections appear with non-empty `version` strings; sections' shapes match each provider's contract (smoke-level — keys present, types correct). |
| FR-011 | Mandatory | Integration test `GraphDumpCommandTest::dumpsFullGraph` invokes `bin/waaseyaa graph:dump` via `CommandTester`, parses JSON, asserts the 6 sections appear with the documented shape. |
| FR-012 | Mandatory | Integration test `GraphDumpCommandTest::scopesToSection` invokes `--section=routing`, asserts only the `routing` section is present. |
| FR-013 | Mandatory | Integration test `GraphDumpCommandTest::failsOnUnknownSection` invokes `--section=nonexistent`, asserts non-zero exit and an error message listing available section keys. |

### Non-functional

| ID | Status | Threshold |
|---|---|---|
| NFR-001 | Mandatory | `ApplicationGraphGenerator::generate()` over the 6 default providers completes in ≤ 100 ms median on a clean test kernel (no real entities, mocked or minimal routes). Measured by the integration test or a benchmark added in WP01. |
| NFR-002 | Mandatory | `BimaajiServiceProvider::register()` adds ≤ 10 ms to kernel boot time. Container binding only — providers are lazy. Measured against an existing kernel-boot benchmark. |
| NFR-003 | Mandatory | The `graph:dump` JSON output is stable: section keys, version strings, and field ordering do not change across runs given the same input. This is necessary for downstream M3 (MCP) consumers that may diff graph snapshots. |
| NFR-004 | Mandatory | The fail-closed behavior in `--strict` mode (FR-006) produces an exception message naming the failing provider class FQCN and the underlying error. Same actionable pattern as M-B's policy-resolution failures. |

### Constraints

| ID | Status | Constraint |
|---|---|---|
| C-001 | Mandatory | `BimaajiServiceProvider` and all 6 default providers stay in Layer 5 (`packages/bimaaji/`). They import from L0–L4 only (foundation, entity, routing, etc.). No upward imports. `bin/check-package-layers` passes. |
| C-002 | Mandatory | The CLI command `graph:dump` lives in `packages/bimaaji/src/Command/` (preferred — keeps the package self-contained) and is auto-discovered by the CLI kernel via the existing command-discovery mechanism. Decision between this location and `packages/cli/src/Command/Bimaaji/` is finalized in the plan; either is acceptable as long as the command appears in `bin/waaseyaa list`. |
| C-003 | Mandatory | No changes to existing `GraphSectionProviderInterface` or `ApplicationGraphGenerator` contracts. The mission is additive — service wiring + CLI + tests + docs. No mutation pipeline changes (mutation surfaces are M2/M3). |
| C-004 | Mandatory | The mission does not introduce HTTP routes, MCP tools, or external transports. Those land in M3. |
| C-005 | Mandatory | `composer verify` is green on the merge commit. |
| C-006 | Mandatory | `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-composer-policy`, `bin/check-getquery-bindings` all green on the merge commit. |
| C-007 | Mandatory | No CI hooks bypassed during this mission's PRs. |

## Success criteria

| ID | Metric | How verified |
|---|---|---|
| SC-001 | A developer can run `bin/waaseyaa graph:dump` immediately after a clean `composer install`, with no extra wiring. | `GraphDumpCommandTest::dumpsFullGraph` passes (FR-011). |
| SC-002 | All 6 default `GraphSectionProvider`s are reachable through the container. | `ApplicationGraphIntegrationTest` passes (FR-010). |
| SC-003 | A third-party package can contribute a `GraphSectionProvider` by binding to the documented tagged collection. | Documented in `README.md` + `docs/specs/bimaaji.md`; smoke-tested manually in WP04. |
| SC-004 | `packages/bimaaji/README.md` no longer claims "scaffolding only". | Code review at PR. |
| SC-005 | M2, M3, M5 can begin implementation without further bimaaji wiring. | Cross-mission gate: M2's first WP imports `ApplicationGraphGenerator` from the container and asserts no `composer` or service-provider changes were needed in `packages/bimaaji/`. |
| SC-006 | `composer verify` green on merge commit. | CI status check. |

## Key entities

| Entity | Role | Net change |
|---|---|---|
| `Waaseyaa\Bimaaji\BimaajiServiceProvider` (new) | Wires `ApplicationGraphGenerator` + 6 default providers. | +1 file. |
| `packages/bimaaji/composer.json` | Manifest. | Edit: add `extra.waaseyaa.providers`. |
| `Waaseyaa\Bimaaji\Command\GraphDumpCommand` (new) | First-party CLI command. | +1 file (or +1 file under `packages/cli/`). |
| `bin/waaseyaa graph:dump` | CLI entry point. | New — auto-discovered. |
| `packages/bimaaji/README.md` | Public README. | Rewrite. |
| `docs/specs/bimaaji.md` | Doctrine spec. | Edit Implementation Status section. |
| `packages/bimaaji/tests/Unit/BimaajiServiceProviderTest.php` (new) | Unit test for the provider. | +1 file. |
| `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` (new) | Booted-kernel integration test. | +1 file. |
| `tests/Integration/PhaseN/Bimaaji/GraphDumpCommandTest.php` (new) | CLI integration tests. | +1 file. |
| `CHANGELOG.md` | `[Unreleased]` entry. | Edit. |
| `CLAUDE.md` (root) | "Operation Checklists" section gains a "Adding a Bimaaji graph section provider" sibling. | Edit. |

## Assumptions

- The framework's `PackageManifestCompiler` already supports `extra.waaseyaa.providers` discovery for `ServiceProvider` classes. (Confirmed in CLAUDE.md "Adding a service provider".)
- The CLI kernel auto-discovers commands declared in `packages/*/src/Command/` (or via a documented attribute) without per-package wiring. The plan verifies this in WP01; if not, the plan adds the minimal wiring.
- The 6 existing `GraphSectionProvider`s' constructor dependencies are all bindable from the existing container (e.g. `EntityTypeManagerInterface`, `RouteCollection`, `SovereigntyProfile`). The plan re-verifies in WP01; if any is unbound, the plan adds the binding in the appropriate package.
- The `--format=yaml` option is small (Symfony YAML is already a dependency). If it turns out not to be, the plan demotes YAML to a follow-up and keeps JSON as the only beta format.

## Out of scope

- MCP transport, HTTP routes, or any external surface for bimaaji. (M3.)
- In-process `ai-agent` tool sources that wrap bimaaji. (M2.)
- `bin/waaseyaa bimaaji:install <client>` and per-client guidelines push. (M5.)
- Mutation pipeline changes — `MutationValidator`, `PatchGenerator`, `SovereigntyGuardrails`, and the Task DSL are unchanged.
- Adding new `GraphSectionProvider`s beyond the existing 6.
- Performance work on individual providers' `provide()` implementations.
- Renaming the `bimaaji` package or changing its layer.

## WP outline (for /spec-kitty.plan)

The planner is free to revise. Indicative shape:

- **WP01 — Audit + ServiceProvider scaffold.** Confirm `extra.waaseyaa.providers` discovery, CLI command auto-discovery, and that each provider's constructor deps are container-bindable. Create `BimaajiServiceProvider`, register `ApplicationGraphGenerator` + tagged collection. Wire the 6 default providers. Unit test (FR-009).
- **WP02 — CLI command.** Implement `GraphDumpCommand`. `--section`, `--format=json|yaml`, `--strict`. Integration tests (FR-011, FR-012, FR-013).
- **WP03 — Integration test for the booted pipeline.** `ApplicationGraphIntegrationTest` (FR-010). Includes NFR-001 timing assertion (lenient — soft budget; planner picks threshold during WP).
- **WP04 — README + spec refresh.** Rewrite `packages/bimaaji/README.md` (drop "scaffolding only"). Update `docs/specs/bimaaji.md` Implementation Status. Update CLAUDE.md "Operation Checklists" with the new "Adding a Bimaaji graph section provider" entry. CHANGELOG `[Unreleased]` line.
- **WP05 — Cross-mission gate + verify.** Confirm M2's first WP can resolve `ApplicationGraphGenerator` from the container without additional wiring (smoke-tested via a fixture in the integration test). Full `composer verify` green.

## References

- Source: `packages/bimaaji/src/*` — 25 PHP classes, see investigation in `docs/plans/2026-05-21-ai-ecosystem-beta-tightening.md`.
- Comparison: Laravel Boost research summary in the same design doc.
- Architectural orchestration: CLAUDE.md "Orchestration" section — bimaaji rows linking to `docs/specs/bimaaji.md`.
- Layer architecture: CLAUDE.md "Layer Architecture" — bimaaji at L4 (per the extension-compatibility-matrix) / L5 (per docs/specs/bimaaji.md and the AI ecosystem framing). Mission preserves whichever placement is canonical; plan reconciles.
- M-G decision: `docs/specs/mcp-endpoint.md` "Bimaaji MCP positioning (2026-05-20)" section. M3 supersedes the deferred-external posture.
- Memory: `feedback_modern_php_rules` — typed interfaces only, contract tests for every extension point.
- Memory: `feedback_regression_tests` — write regression tests, not just unit tests.
- Memory: `feedback_spec_kitty_mission_filing_pattern` — the 9-item filing checklist mirrored in this mission's setup.
