# M12 Milestone Framing — Post–M11 Steady-State Baseline

## Baseline Context

M10 execution across D1 through D5+ is fully merged on `main`, and both post-execution drift remediations are complete:

- `C17` admin SPA runtime and composable drift remediation is merged.
- `C18` specification drift remediation is merged.

The authoritative baseline on `main` is fully green across all governed executable surfaces:

- PHP suite
- Admin SPA Vitest suite
- Admin build
- `drift-detector`

At the close of M11, the drift ledger is empty and the platform is operating in steady-state equilibrium. The current implementation on `main` is the governing source of truth for behavior, ownership, and surface definitions.

## M12 Objectives

M12 exists to define and execute the next steady-state conformance step without destabilizing the post-M11 baseline. The high-level objectives are:

- Strengthen cross-surface invariants so changes remain correct across PHP runtime, admin SPA, API, MCP, and governance artifacts.
- Expand conformance coverage to surfaces that are new, adjacent, or not yet governed with the same rigor as the M10 and M11 slices.
- Introduce or refine governance boundaries where ownership, dependency direction, or verification expectations are still too implicit.
- Improve platform-level reliability, observability, and developer experience in ways that reinforce conformance rather than bypass it.
- Prepare the system for future execution milestones (`M13+`) by defining slices that are independently governable, verifiable, and mergeable.

## Invariants for M12

The following invariants must hold throughout M12:

- No protected upstream surfaces may be modified without a governed change.
- No new audit surfaces may be introduced without explicit approval.
- All new surfaces must include tests, specs, and `drift-detector` coverage.
- `main` must remain green across all executable surfaces at all times.
- All governed changes must map back to the canonical model and documented governance loop established in M11.
- Any newly discovered divergence must be logged as `C19+` drift under M11/M12 governance before remediation proceeds.

## Candidate Execution Slices

### E1: Infrastructure Conformance Expansion

- Scope: extend conformance and ownership clarity for foundational bootstrapping, discovery, provider registration, and shared kernel behaviors.
- Expected surfaces: `packages/foundation/*`, `packages/cache/*`, `packages/database-legacy/*`, shared bootstrap and discovery docs.
- Dependencies: requires preservation of M10 provider-ownership rules and M11 governance invariants.
- Verification requirements: PHPUnit coverage for boot/discovery paths, spec updates where discovery or bootstrap contracts change, `drift-detector` green.

### E2: API-Layer Normalization

- Scope: tighten API-layer conformance around route ownership, schema endpoints, resource serialization, and shared API contracts.
- Expected surfaces: `packages/api/*`, `packages/routing/*`, API specs, request/integration suites.
- Dependencies: depends on infrastructure ownership remaining stable and on access-control invariants remaining intact.
- Verification requirements: PHPUnit unit and integration coverage, API-focused regression tests, updated specs, `drift-detector` green.

### E3: Admin SPA Surface Hardening

- Scope: strengthen admin runtime, composable, rendering, and contract alignment so the SPA remains conformance-safe as new capabilities are added.
- Expected surfaces: `packages/admin/*`, `packages/admin-surface/*`, admin contract definitions, SPA specs, Vitest coverage.
- Dependencies: depends on stable API and admin-surface contracts.
- Verification requirements: Vitest green, admin build green, any necessary integration or adapter tests, updated specs, `drift-detector` green.

### E4: MCP Domain Extension or Cleanup

- Scope: govern MCP-specific extension, cleanup, or normalization work while preserving the provider-owned model established in M10.
- Expected surfaces: `packages/mcp/*`, MCP route/provider declarations, MCP specs, related PHP tests.
- Dependencies: depends on infrastructure/package-discovery invariants and on API/resource serialization stability where MCP tooling reuses shared behavior.
- Verification requirements: PHPUnit coverage for MCP provider and endpoint paths, spec alignment, `drift-detector` green.

### E5: Access-Control Model Refinement

- Scope: refine route, entity, and field access behavior where governance or conformance boundaries remain underspecified or brittle.
- Expected surfaces: `packages/access/*`, `packages/user/*`, related middleware, access-control specs, request/integration tests.
- Dependencies: depends on stable provider-owned route registration and documented baseline access semantics.
- Verification requirements: PHPUnit unit and integration coverage for middleware and policy behavior, spec alignment, `drift-detector` green.

### E6: Developer-Experience and Tooling Improvements

- Scope: improve diagnostics, verification ergonomics, and governance tooling without weakening enforcement or bypassing conformance controls.
- Expected surfaces: governance docs, tooling scripts, diagnostics commands, developer workflows.
- Dependencies: depends on all prior invariants remaining enforceable and observable.
- Verification requirements: targeted command/tool tests where applicable, documentation updates, `drift-detector` green, no degradation of executable surfaces.

## Governance Boundaries

M12 operates inside the M11 steady-state governance loop and does not replace it.

- New drift must be logged as `C19+` in the governance log family before remediation or execution work begins.
- Execution slices must be opened explicitly, scoped narrowly, and closed only after evidence-backed verification.
- PRs must remain slice-local, target `main`, include clear scope and invariant statements, and carry the required governance metadata.
- Verification must be performed on every slice using the executable surfaces relevant to that slice, with repo-wide green status preserved as a non-negotiable baseline.
- Invariants are enforced through governed-change review, required verification, spec maintenance, and `drift-detector` compliance.

## Dependencies and Constraints

The following constraints carry forward from M10 and M11:

- Cross-surface changes frequently couple provider discovery, route ownership, SPA runtime assumptions, and test harness expectations.
- Package-level ownership introduced in M10 must remain stable unless explicitly re-governed.
- Admin SPA runtime, API-layer ownership, MCP provider registration, and infrastructure discovery are now coupled through documented contracts and must not drift independently.
- Additional modeling may still be required where future slices introduce new governed surfaces or split existing ownership boundaries.
- Protected upstream surfaces and governance baselines must remain frozen unless changed through explicit governed approval.

## Exit Criteria for M12

M12 is complete only when all of the following are true:

- All approved M12 execution slices are merged.
- All new or refined invariants introduced during M12 are satisfied.
- All affected specs are updated and aligned with the implementation.
- All governed executable surfaces are green.
- The drift ledger is empty.
- No protected surfaces were violated.
- No unauthorized audit surfaces were introduced.
