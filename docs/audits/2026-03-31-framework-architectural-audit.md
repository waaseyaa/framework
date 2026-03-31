# Waaseyaa Framework Architectural Audit

Date: 2026-03-31

## Goal

Produce a clean, invariant-driven architectural snapshot of `waaseyaa/framework` as it exists today, without remediation work mixed into the audit itself.

Milestone 1 is audit-only. No code fixes are part of this milestone. The only permitted repository edits during Milestone 1 are documentation and metadata updates required to define, track, and preserve the audit.

## Scope

- Repository: `waaseyaa/framework`
- Excluded: downstream consumer apps, convergence audits, and all implementation fixes
- Outputs:
  - `docs/audits/2026-03-31-framework-architectural-audit.md`
  - one GitHub milestone for Milestone 1
  - one umbrella issue for the audit program
  - five pass issues
  - finding issues created beneath the pass issues after each pass rubric is stable

## Audit Principles

- The audit is invariant-driven, not preference-driven.
- The audit records reality first and defers all fixes.
- Findings must be queryable by both architectural concern and framework layer.
- Issues are created only for actionable drift.
- Every finding must cite explicit evidence categories.
- Closed vocabularies are frozen before the audit begins and are not extended during Milestone 1 unless the milestone design itself is revised.

## Milestone 1 Passes

Milestone 1 runs in five deterministic passes:

1. `M1-boundaries`
2. `M1-contracts`
3. `M1-testing`
4. `M1-docs-governance`
5. `M1-dx-tooling`

The milestone is bootstrapped in this order:

1. Create the Milestone 1 GitHub milestone.
2. Create one umbrella issue for the overall audit.
3. Create one pass issue for each Milestone 1 pass.
4. Freeze the audit vocabularies in the audit doc and in the issue body template.
5. Run each pass and only then open finding issues under that pass.

## Concern Model

The concern model is fixed for Milestone 1:

- `boundaries`
- `contracts`
- `testing`
- `docs-governance`
- `dx-tooling`

The audit document is organized by these concerns. The issue inventory mirrors the same concerns and additionally tags findings by layer.

## Layer Model

The layer model follows the framework architecture already documented in `CLAUDE.md`:

- `L0-foundation`
- `L1-core-data`
- `L2-content-types`
- `L3-services`
- `L4-api`
- `L5-ai`
- `L6-interfaces`
- `cross-layer`

`cross-layer` is used only when a finding cannot be cleanly attributed to one layer without obscuring the problem.

## Closed Vocabularies

### Subsystem taxonomy

- `kernel-bootstrap`
- `foundation-infra`
- `core-data`
- `content-types`
- `services`
- `api-routing`
- `ai`
- `interfaces`
- `admin-spa`
- `testing-harness`
- `docs-specs`
- `workflow-governance`
- `build-tooling`
- `package-topology`

Notes:

- `ai` is treated as a true subsystem because the repository contains a distinct Layer 5 package family: `ai-schema`, `ai-agent`, `ai-pipeline`, and `ai-vector`.
- `integration-adapters` is not part of the closed set at audit start. It may be proposed only if the audit reveals a recurring adapter seam that cannot be accurately represented inside the existing subsystem taxonomy.

### Severity

- `critical`
- `high`
- `medium`
- `low`

### Remediation class

- `invariant-break`
- `contract-gap`
- `coverage-gap`
- `governance-drift`
- `docs-drift`
- `tooling-gap`
- `cleanup-candidate`
- `framework-uplift`

### Audit phase

- `M1-boundaries`
- `M1-contracts`
- `M1-testing`
- `M1-docs-governance`
- `M1-dx-tooling`

### Evidence sources

Evidence must be cited using these standardized categories:

- `code`
- `docs`
- `tests`
- `dependency graph`
- `git history`

Each finding must name which evidence categories were used and include the concrete references under those categories.

## Audit Deliverables

Milestone 1 produces two primary artifacts.

### 1. Concern-organized audit document

The audit document is the canonical narrative artifact. It is organized by concern and, for each finding, records:

- invariant being checked
- evidence sources
- finding summary
- affected layers
- affected subsystem
- severity
- remediation class
- notes on later remediation sequencing

### 2. Layer-tagged issue inventory

The issue inventory is the operational artifact in GitHub. Every finding issue must use the fixed metadata shape:

- `Concern`
- `Layer`
- `Subsystem`
- `Severity`
- `Remediation class`
- `Evidence sources`
- `Audit phase`

Issue titles must use this format:

`audit(<concern>)(<layer>): <finding>`

Examples:

- `audit(boundaries)(L4-api): routing leaks higher-layer concerns into access checks`
- `audit(testing)(L1-core-data): entity policy invariants lack negative-path integration coverage`

## Audit Rubric By Pass

Each pass must define and stabilize its rubric before finding issues are opened.

### `M1-boundaries`

Inspect:

- package dependency direction
- layer import discipline
- composition-root containment
- kernel bootstrapping boundaries
- event-based upward communication seams
- package topology drift against the documented layer model

Expected evidence categories:

- `code`
- `dependency graph`
- `docs`
- `git history` when recent churn explains drift

### `M1-contracts`

Inspect:

- public interfaces and extension points
- entity, field, access, routing, and middleware contracts
- spec-to-code agreement
- package public API consistency
- contract asymmetries that are intentional but undocumented

Expected evidence categories:

- `code`
- `docs`
- `tests`
- `git history` when contract drift is historically introduced

### `M1-testing`

Inspect:

- unit, integration, and contract coverage for critical invariants
- negative-path coverage
- test harness quality
- missing regression protection around architectural seams
- tests that verify implementation detail rather than contract

Expected evidence categories:

- `tests`
- `code`
- `docs`
- `git history` when regressions or missing follow-through are visible

### `M1-docs-governance`

Inspect:

- `README.md`
- `CLAUDE.md`
- `AGENTS.md`
- `docs/specs/**`
- milestone tables
- issue workflow expectations
- codified context drift versus actual repository state

Expected evidence categories:

- `docs`
- `code`
- `tests` when documentation promises verification that does not exist
- `git history`

### `M1-dx-tooling`

Inspect:

- Composer scripts and package manifests
- local test and analysis ergonomics
- scaffolding fidelity
- package discoverability
- diagnostics and operator tooling
- developer workflow sharp edges

Expected evidence categories:

- `code`
- `docs`
- `tests`
- `dependency graph`
- `git history`

## GitHub Tracking Model

Milestone 1 creates:

- one milestone: `M1: Framework Architectural Audit`
- one umbrella issue describing the audit contract, scope, outputs, and sequencing
- five pass issues, one per audit phase

No finding issues are created until the relevant pass rubric is confirmed stable.

Finding issues should link back to:

- the umbrella issue
- the pass issue that discovered the finding
- the audit document section when practical

## Recommended Issue Body Shape

Each finding issue should use a stable body structure:

1. Summary
2. Invariant
3. Finding
4. Metadata
5. Evidence
6. Remediation direction
7. Sequencing notes

The metadata section should include the fixed fields and closed vocabularies exactly as frozen in this document.

## Out of Scope

- Fixing boundary violations
- Refactoring packages
- Adding or changing runtime behavior
- Closing test gaps
- Rewriting docs beyond what is needed to preserve the audit record
- Auditing downstream consumer apps
- Consumer convergence planning

## Exit Condition

Milestone 1 is complete when:

- the audit document exists and reflects all five passes
- the milestone exists in GitHub
- the umbrella issue exists
- the five pass issues exist
- every actionable finding discovered during a completed pass has a finding issue using the fixed metadata shape
- no remediation work has been mixed into the audit milestone

## Next Step

After user review of this document:

1. create the Milestone 1 milestone
2. create the umbrella issue
3. create the five pass issues
4. begin with `M1-boundaries`

## M1-boundaries

Status: in progress

No finding issues have been opened yet for this pass. This section records the frozen pass rubric and the initial dependency-map comparison before findings are emitted into GitHub.

### Rubric

The `M1-boundaries` pass checks six boundary invariants:

1. layer import discipline
2. package dependency direction
3. composition-root containment
4. kernel/bootstrap boundaries
5. upward communication seams
6. package topology drift

### Method

Initial evidence gathering for this pass starts with:

- generating a framework package dependency map from `packages/*/composer.json`
- comparing each package dependency edge to the documented layer table in `CLAUDE.md`
- identifying upward package dependencies
- identifying packages present in the repository but absent from the documented layer model
- enumerating kernel, bootstrap, and middleware files that may act as composition roots or cross-layer boundary seams

### Evidence Sources Used So Far

- `code`
  - `packages/*/composer.json`
  - `packages/foundation/src/Kernel/**`
  - `packages/*/src/Middleware/**`
- `docs`
  - `CLAUDE.md`
- `dependency graph`
  - package-to-package dependency map derived from Composer requirements

### Initial Dependency Map Comparison

The documented layer model in `CLAUDE.md` currently defines these package families:

- `L0-foundation`: foundation, cache, plugin, typed-data, database-legacy, testing, i18n, queue, scheduler, state, validation, mail, http-client, ingestion
- `L1-core-data`: entity, entity-storage, access, user, config, field, auth
- `L2-content-types`: node, taxonomy, media, path, menu, note, relationship
- `L3-services`: workflows, search, notification, billing, github
- `L4-api`: api, routing
- `L5-ai`: ai-schema, ai-agent, ai-pipeline, ai-vector
- `L6-interfaces`: cli, admin, admin-surface, graphql, mcp, ssr, telescope, deployer, inertia

Initial dependency-map comparison shows:

- two direct upward package dependencies against the documented layer order:
  - `waaseyaa/api` -> `waaseyaa/ssr`
  - `waaseyaa/testing` -> `waaseyaa/graphql`
- several composer packages exist in `packages/` but are not represented in the documented layer table:
  - `cms`
  - `core`
  - `engagement`
  - `full`
  - `geo`
  - `mercure`
  - `messaging`
  - `oauth-provider`
- the documented layer table includes `admin` as an interface package, but `packages/admin` is a JavaScript workspace with `package.json`, not a Composer package, so it does not appear in the Composer dependency graph

These are preliminary observations only. They are recorded here to anchor the pass rubric and evidence trail. Formal findings and GitHub finding issues remain deferred until the pass rubric is confirmed stable.

### Composition Root And Bootstrap Inventory

Initial boundary-seam inventory identified these composition-root and bootstrap-adjacent areas for deeper inspection in the remainder of `M1-boundaries`:

- `packages/foundation/src/Kernel/AbstractKernel.php`
- `packages/foundation/src/Kernel/HttpKernel.php`
- `packages/foundation/src/Kernel/ConsoleKernel.php`
- `packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php`
- `packages/foundation/src/Kernel/Bootstrap/ManifestBootstrapper.php`
- `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php`
- `packages/foundation/src/Kernel/Bootstrap/AccessPolicyRegistry.php`
- middleware seams in:
  - `packages/access/src/Middleware/AuthorizationMiddleware.php`
  - `packages/auth/src/Middleware/AuthenticateMiddleware.php`
  - `packages/user/src/Middleware/*.php`
  - `packages/foundation/src/Middleware/*.php`
  - `packages/telescope/src/Middleware/TelescopeRequestMiddleware.php`

### Formal Findings

#### audit(boundaries)(L4-api): api package imports interface-layer ssr package

- Concern: `boundaries`
- Layer: `L4-api`
- Subsystem: `api-routing`
- Severity: `high`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`, `dependency graph`
- Audit phase: `M1-boundaries`

Evidence:

- `code`: `packages/api/composer.json` declares `waaseyaa/ssr` in `repositories` and `require` (`lines 39-50`)
- `docs`: `CLAUDE.md` places `api` in Layer 4 and `ssr` in Layer 6 (`lines 66-74`)
- `dependency graph`: generated package map classifies `waaseyaa/api -> waaseyaa/ssr` as an upward edge

Boundary impact:

- This is a direct package-level import from the API layer into the Interfaces layer.
- It violates the documented rule that packages may import only from their own layer or lower.
- Because the dependency is declared at the Composer package boundary, this is stronger drift than an incidental internal namespace reference.

#### audit(boundaries)(L0-foundation): testing package imports interface-layer graphql package

- Concern: `boundaries`
- Layer: `L0-foundation`
- Subsystem: `testing-harness`
- Severity: `high`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`, `dependency graph`
- Audit phase: `M1-boundaries`

Evidence:

- `code`: `packages/testing/composer.json` declares a path repository to `../graphql` and requires `waaseyaa/graphql` (`lines 6-14`)
- `docs`: `CLAUDE.md` places `testing` in Layer 0 and `graphql` in Layer 6 (`lines 66-74`)
- `dependency graph`: generated package map classifies `waaseyaa/testing -> waaseyaa/graphql` as an upward edge

Boundary impact:

- The testing harness is documented as Foundation-layer infrastructure but depends directly on an Interfaces-layer package.
- This collapses the lower-layer testing utility surface into one specific interface implementation family.
- It also weakens the architectural meaning of Layer 0 as a reusable substrate.

#### audit(boundaries)(cross-layer): composer package topology exceeds documented layer model

- Concern: `boundaries`
- Layer: `cross-layer`
- Subsystem: `package-topology`
- Severity: `medium`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`, `dependency graph`
- Audit phase: `M1-boundaries`

Evidence:

- `code`: `packages/*/composer.json` currently exists for 55 Composer packages, including `cms`, `core`, `engagement`, `full`, `geo`, `mercure`, `messaging`, and `oauth-provider`
- `docs`: `CLAUDE.md` layer table enumerates the package families used for boundary rules (`lines 66-74`)
- `dependency graph`: generated package map reports these Composer packages as `UNMAPPED` because they do not appear in the documented layer model

Boundary impact:

- Boundary enforcement depends on a complete package-to-layer mapping.
- Unmapped Composer packages cannot be evaluated against the documented layer rule without inference.
- This creates a topology gap where some repository packages participate in package boundaries but not in the official architectural model.

#### audit(boundaries)(L6-interfaces): admin is modeled as a layer package but implemented outside the Composer package graph

- Concern: `boundaries`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `medium`
- Remediation class: `cleanup-candidate`
- Evidence sources: `code`, `docs`, `dependency graph`
- Audit phase: `M1-boundaries`

Evidence:

- `code`: `packages/admin/package.json` defines `@waaseyaa/admin` as a JavaScript package (`lines 1-52`)
- `code`: `packages/admin/` contains a Node/Nuxt workspace and no `composer.json`
- `docs`: `CLAUDE.md` lists `admin` in the Layer 6 package table (`line 72`)
- `dependency graph`: the Composer-derived package map cannot place `admin` because it is not part of the Composer package topology

Boundary impact:

- The documented layer table mixes at least one non-Composer workspace into a Composer-oriented package model.
- This does not create an upward dependency by itself, but it blurs what “package” means when enforcing layer boundaries mechanically.
- It introduces ambiguity into package-topology audits that operate from `composer.json` as the source of truth.

#### audit(boundaries)(L0-foundation): extracted kernel bootstrappers carry cross-layer imports outside the documented kernel exemption

- Concern: `boundaries`
- Layer: `L0-foundation`
- Subsystem: `kernel-bootstrap`
- Severity: `medium`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-boundaries`

Evidence:

- `docs`: `CLAUDE.md` says only the Foundation `Kernel/` classes `AbstractKernel`, `HttpKernel`, and `ConsoleKernel` are exempt from the layer rule (`lines 74-76`)
- `docs`: `CLAUDE.md` separately notes that extracted bootstrappers live in `packages/foundation/src/Kernel/Bootstrap/` and that `AbstractKernel` delegates to them (`line 226`)
- `code`: `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php` imports `Waaseyaa\Entity\EntityTypeManager` and coordinates cross-layer provider registration (`lines 7-13`, `29-99`)
- `code`: `packages/foundation/src/Kernel/Bootstrap/AccessPolicyRegistry.php` imports `Waaseyaa\Access\EntityAccessHandler` from Core Data (`lines 7-8`, `24-55`)

Boundary impact:

- Cross-layer composition logic has been extracted from the named kernel classes into helper classes under `Kernel/Bootstrap`.
- The written architectural exemption still names only the three kernel classes, not the extracted bootstrap helpers.
- That leaves these bootstrappers in an ambiguous state: functionally composition-root code, but not explicitly covered by the boundary rule that permits upward imports.

#### audit(boundaries)(cross-layer): provider resolver enables synchronous cross-provider coupling outside documented upward seams

- Concern: `boundaries`
- Layer: `cross-layer`
- Subsystem: `kernel-bootstrap`
- Severity: `high`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-boundaries`

Evidence:

- `docs`: `CLAUDE.md` states that upward communication should occur via `DomainEvents` (`line 74`)
- `code`: `packages/foundation/src/ServiceProvider/ServiceProvider.php` exposes `setKernelResolver()` and `resolve()` fallback behavior for arbitrary class names (`lines 29-30`, `102-145`)
- `code`: `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php` wires that resolver so a provider can resolve kernel services and then scan other providers for matching bindings (`lines 51-75`)
- `code`: provider packages use `resolve()` to obtain services outside their own local bindings, including kernel-level and cross-provider services (`rg` hits across `packages/auth`, `packages/billing`, `packages/mail`, `packages/notification`, `packages/queue`, `packages/scheduler`, `packages/search`, and `packages/user`)

Boundary impact:

- The provider graph is not limited to local bindings plus explicit downward dependencies.
- Providers can synchronously reach across the runtime binding graph through a kernel-supplied service locator surface.
- This creates an upward communication seam that is broader than the documented `DomainEvents` rule and makes cross-layer coupling harder to reason about from package manifests alone.

#### audit(boundaries)(L1-core-data): user package reaches into interface-layer SSR through provider wiring

- Concern: `boundaries`
- Layer: `L1-core-data`
- Subsystem: `core-data`
- Severity: `high`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-boundaries`

Evidence:

- `docs`: `CLAUDE.md` places `user` in Layer 1 and `ssr` in Layer 6, with upward imports disallowed except for the named kernel classes (`lines 66-76`)
- `code`: `packages/user/src/UserServiceProvider.php` constructs `AuthMailer` with `\Waaseyaa\SSR\SsrServiceProvider::getTwigEnvironment()` (`lines 69-75`)
- `code`: `packages/user/composer.json` includes a local path repository to `../ssr`, confirming an intended runtime relationship even though `waaseyaa/ssr` is not declared in `require` (`lines 47-60`)

Boundary impact:

- A Core Data package directly reaches into an Interfaces-layer static service surface.
- This is not happening inside one of the explicitly exempt kernel composition roots.
- It creates a hidden upward dependency that is visible in code but only partially represented in package metadata.

#### audit(boundaries)(L6-interfaces): admin-surface provider rebuilds core access wiring from manifest storage

- Concern: `boundaries`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `medium`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-boundaries`

Evidence:

- `code`: `packages/admin-surface/src/AdminSurfaceServiceProvider.php` imports `PackageManifest` and `AccessPolicyRegistry` and, in `discoverAccessHandler()`, loads `storage/framework/packages.php`, rebuilds a manifest, and reconstructs an `EntityAccessHandler` (`lines 12-15`, `102-120`)
- `code`: the same provider uses that reconstructed access handler when building the generic host (`lines 50-56`)
- `docs`: `CLAUDE.md` states upward communication should occur through sanctioned seams and names only Foundation kernel classes as composition-root exceptions (`lines 74-76`)

Boundary impact:

- The admin-surface provider acts as a hidden composition root for access-policy discovery instead of consuming a kernel-composed access service.
- That duplicates part of the bootstrapping responsibility inside an Interfaces-layer package.
- It increases the chance that runtime access wiring diverges between the kernel path and the admin-surface path.

#### audit(boundaries)(L0-foundation): EventListenerRegistrar carries multi-layer orchestration outside the named kernel exemption

- Concern: `boundaries`
- Layer: `L0-foundation`
- Subsystem: `kernel-bootstrap`
- Severity: `medium`
- Remediation class: `invariant-break`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-boundaries`

Evidence:

- `docs`: `CLAUDE.md` exempts only `AbstractKernel`, `HttpKernel`, and `ConsoleKernel` from normal layer restrictions (`lines 74-76`)
- `code`: `packages/foundation/src/Kernel/EventListenerRegistrar.php` imports AI vector classes, API broadcast storage, and SSR render cache from higher layers (`lines 8-20`)
- `code`: the registrar wires broadcast, render-cache, discovery-cache, MCP-read-cache, and embedding lifecycle listeners (`lines 38-183`)

Boundary impact:

- This helper performs multi-layer event orchestration but is not one of the explicitly named kernel entry-point classes.
- It is functionally part of the composition root while remaining outside the written exemption boundary.
- That widens the set of upward-import-capable Foundation classes beyond what the architecture rule currently describes.

### Pass Summary

Inspected surfaces in `M1-boundaries`:

- package dependency map from `packages/*/composer.json`
- documented layer model in `CLAUDE.md`
- Foundation kernel composition roots:
  - `AbstractKernel`
  - `HttpKernel`
  - `ConsoleKernel`
- extracted kernel helpers:
  - `packages/foundation/src/Kernel/Bootstrap/*`
  - `packages/foundation/src/Kernel/EventListenerRegistrar.php`
- middleware seams in:
  - `packages/access/src/Middleware/*`
  - `packages/auth/src/Middleware/*`
  - `packages/user/src/Middleware/*`
  - `packages/foundation/src/Middleware/*`
  - `packages/telescope/src/Middleware/*`
- provider wiring across `packages/*/src/*ServiceProvider.php`
- hidden composition-root candidates in provider route registration, middleware registration, event subscriber registration, queue worker bindings, and CLI command wiring

Boundary-specific conclusions:

- The named kernel classes still behave like deliberate composition roots and were not themselves recorded as violations simply for importing across layers.
- The larger boundary drift is around helper and provider surfaces that now perform composition-root work outside the exact boundaries described by the architecture rule.
- Several upward or hidden cross-layer relationships are present in code and runtime wiring even when they are absent, incomplete, or ambiguous in package metadata.
- Middleware files themselves did not produce additional direct layer violations beyond the broader composition and provider wiring findings already captured.

Finding issues opened during `M1-boundaries`:

- `#823` `audit(boundaries)(L4-api): api package imports interface-layer ssr package`
- `#824` `audit(boundaries)(L6-interfaces): admin is modeled as a layer package but implemented outside the Composer package graph`
- `#825` `audit(boundaries)(cross-layer): composer package topology exceeds documented layer model`
- `#826` `audit(boundaries)(L0-foundation): testing package imports interface-layer graphql package`
- `#827` `audit(boundaries)(L0-foundation): extracted kernel bootstrappers carry cross-layer imports outside the documented kernel exemption`
- `#828` `audit(boundaries)(cross-layer): provider resolver enables synchronous cross-provider coupling outside documented upward seams`
- `#829` `audit(boundaries)(L1-core-data): user package reaches into interface-layer SSR through provider wiring`
- `#830` `audit(boundaries)(L0-foundation): EventListenerRegistrar carries multi-layer orchestration outside the named kernel exemption`
- `#831` `audit(boundaries)(L6-interfaces): admin-surface provider rebuilds core access wiring from manifest storage`

`M1-boundaries` is ready to hand off to `M1-contracts`.

## M1-contracts

Status: findings recorded and pass summary prepared

This section froze the `M1-contracts` rubric before findings were emitted into GitHub. The finding set for this pass is now recorded below and linked from milestone issues `#832`, `#833`, `#834`, `#835`, `#836`, `#837`, `#838`, `#839`, `#840`, and `#841`.

### Rubric

The `M1-contracts` pass checks six contract invariants:

1. public interfaces
2. entity and field contracts
3. access and routing semantics
4. package API consistency
5. documented extension points
6. spec-to-code agreement

### Method

Initial evidence gathering for this pass starts with:

- loading subsystem specs that define public behavior and package seams
- comparing documented interfaces and semantics to the actual PHP interfaces, concrete implementations, and service-provider surfaces
- checking whether documented extension points are still the real extension points in code
- identifying contract drift that is distinct from boundary drift, while cross-referencing any relevant `M1-boundaries` issues

### Evidence Sources Used So Far

- `docs`
  - `docs/specs/entity-system.md`
  - `docs/specs/access-control.md`
  - `docs/specs/field-access.md`
  - `docs/specs/api-layer.md`
- `code`
  - interface and implementation files under `packages/entity/`, `packages/entity-storage/`, `packages/field/`, `packages/access/`, `packages/routing/`, and `packages/api/`

### Contract Classification Rule

- Do not reclassify existing boundary findings as contract findings.
- If a contract issue depends on or is made riskier by a boundary issue, reference the relevant `M1-boundaries` issue in the new finding but classify the new finding under `contracts`.

### Formal Findings

#### audit(contracts)(L4-api): access-control and API specs place AccessChecker in routing while the public class lives in access

- Concern: `contracts`
- Layer: `L4-api`
- Subsystem: `api-routing`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `docs`: `docs/specs/access-control.md` states that `routing` provides `AccessChecker` and describes route-level access there
- `docs`: `docs/specs/api-layer.md` lists `src/AccessChecker.php` under `packages/routing/`
- `code`: the concrete class is `packages/access/src/AccessChecker.php` in namespace `Waaseyaa\Access`
- `code`: repository usages import `Waaseyaa\Access\AccessChecker`; there are no usages of `Waaseyaa\Routing\AccessChecker`

Contract impact:

- The documented package and namespace of a public route-access class are wrong.
- Consumers reading the access-control or API specs would be directed to the wrong package boundary.
- This is contract drift even though the runtime behavior works, because package location is part of the public API surface for framework consumers.

#### audit(contracts)(L4-api): paired-nullable access context is documented but unenforced in ResourceSerializer and SchemaPresenter

- Concern: `contracts`
- Layer: `L4-api`
- Subsystem: `api-routing`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `docs`: `docs/specs/api-layer.md` says `?EntityAccessHandler` and `?AccountInterface` follow a paired-nullable pattern and that only the both-null and both-non-null states are meaningful
- `docs`: `docs/specs/field-access.md` repeats the same paired-nullable contract for `ResourceSerializer::serialize()` and `SchemaPresenter::present()`
- `code`: `packages/api/src/ResourceSerializer.php` only checks `if ($accessHandler !== null && $account !== null)` and silently skips filtering otherwise
- `code`: `packages/api/src/Schema/SchemaPresenter.php` uses the same silent guard for field filtering and edit restriction

Contract impact:

- The public API documents an invalid partial state, but the implementation does not reject it.
- Callers can pass one half of the access context and receive an unfiltered response or schema without any explicit failure.
- This weakens the reliability of the documented field-access contract for API consumers and extension authors.

#### audit(contracts)(L0-foundation): ServiceProvider extension hooks expose concrete EntityTypeManager instead of the public interface surface

- Concern: `contracts`
- Layer: `L0-foundation`
- Subsystem: `build-tooling`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `docs`: `README.md` states “Interface-first. Public APIs are defined as interfaces. Implementations are swappable.”
- `code`: `packages/foundation/src/ServiceProvider/ServiceProvider.php` declares `routes()`, `commands()`, `graphqlMutationOverrides()`, and `middleware()` with concrete `\Waaseyaa\Entity\EntityTypeManager` parameters
- `code`: the repository also defines `packages/entity/src/EntityTypeManagerInterface.php` as the public interface for that subsystem

Contract impact:

- The framework’s main provider extension point binds extension authors to a concrete manager class where an interface already exists.
- That conflicts with the repo’s published public-API principle and makes the extension surface less swappable than advertised.
- This is a contract-level inconsistency in a core extension point, not merely an internal implementation choice.

#### audit(contracts)(L1-core-data): entity-system spec publishes outdated RevisionableStorageInterface signatures

- Concern: `contracts`
- Layer: `L1-core-data`
- Subsystem: `core-data`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `docs`: `docs/specs/entity-system.md` documents `RevisionableStorageInterface` as:
  - `loadRevision(int|string $revisionId)`
  - `loadMultipleRevisions(array $ids)`
  - `deleteRevision(int|string $revisionId)`
  - `getLatestRevisionId(int|string $entityId): int|string|null`
- `code`: `packages/entity/src/Storage/RevisionableStorageInterface.php` actually defines:
  - `loadRevision(int|string $entityId, int $revisionId)`
  - `loadMultipleRevisions(int|string $entityId, array $revisionIds)`
  - `deleteRevision(int|string $entityId, int $revisionId)`
  - `getLatestRevisionId(int|string $entityId): ?int`
  - `getRevisionIds(int|string $entityId): array`

Contract impact:

- The published revision storage contract no longer matches the real method signatures.
- Consumers implementing against the spec would build the wrong method shapes.
- The omitted `getRevisionIds()` method also means the documented revision contract is incomplete.

#### audit(contracts)(L1-core-data): entity-system spec omits public EntityTypeManagerInterface registration methods

- Concern: `contracts`
- Layer: `L1-core-data`
- Subsystem: `core-data`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `docs`: `docs/specs/entity-system.md` documents `EntityTypeManagerInterface` with only `getDefinition()`, `getDefinitions()`, `hasDefinition()`, and `getStorage()`
- `code`: `packages/entity/src/EntityTypeManagerInterface.php` also exposes `registerEntityType()` and `registerCoreEntityType()`
- `code`: `packages/entity/src/EntityTypeManager.php` implements both registration methods and documents reserved `core.` namespace semantics on them

Contract impact:

- The entity manager’s public mutation surface is incomplete in the spec.
- Extension authors using the spec as the canonical contract would miss the official registration entry points and the reserved-namespace behavior tied to them.
- This is spec-to-code drift on a central subsystem interface.

#### audit(contracts)(L6-interfaces): admin-surface host extension surface is bound to concrete EntityTypeManager rather than the entity manager interface

- Concern: `contracts`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `docs`: `README.md` states public APIs are interface-first and implementations are swappable
- `code`: `packages/admin-surface/src/Host/GenericAdminSurfaceHost.php` constructor type-hints concrete `EntityTypeManager`
- `code`: `packages/admin-surface/src/AdminSurfaceServiceProvider.php` route hook also type-hints concrete `EntityTypeManager`
- `code`: `packages/entity/src/EntityTypeManagerInterface.php` exists as the interface for this subsystem
- `code`: `packages/admin-surface` tests are written against the concrete manager class throughout the host surface

Contract impact:

- The admin-surface host and provider extension APIs bind integrators to a concrete entity manager implementation.
- That weakens the swappability story for one of the framework’s main UI-extension surfaces.
- This is distinct from the boundary concerns in `#831`; the contract problem is that the exposed host/provider API is less abstract than the framework’s published extension model claims.

#### audit(contracts)(L0-foundation): ServiceProviderInterface omits documented extension hooks invoked by kernel call sites

- Concern: `contracts`
- Layer: `L0-foundation`
- Subsystem: `foundation-infra`
- Severity: `high`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `code`: `packages/foundation/src/ServiceProvider/ServiceProviderInterface.php` declares `register()`, `boot()`, `routes()`, `provides()`, and `isDeferred()`, but does not declare `commands()`, `middleware()`, `graphqlMutationOverrides()`, or `setKernelResolver()`
- `code`: `packages/foundation/src/ServiceProvider/ServiceProvider.php` exposes those additional hooks on the abstract base class
- `code`: `packages/foundation/src/Kernel/ConsoleKernel.php`, `packages/foundation/src/Kernel/HttpKernel.php`, and `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` call the hook methods directly as part of normal kernel orchestration
- `docs`: `docs/specs/plugin-extension-points.md` describes the extra hook methods as the stable extension contract for packages
- `docs`: `docs/specs/package-discovery.md` documents the interface without the hook surface, while the extension-point spec documents the richer surface

Contract impact:

- There is no single authoritative public type for the service-provider extension contract.
- Consumers programming to `ServiceProviderInterface` cannot rely on the same hook surface the kernels and extension docs treat as stable.
- This is stronger than a documentation mismatch because the typed interface, abstract base, and runtime call sites disagree about what the extension contract actually is.

#### audit(contracts)(L6-interfaces): admin-surface session contract omits email verification state used by backend and SPA runtime

- Concern: `contracts`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `code`: `packages/admin-surface/contract/types.ts` defines `AdminSurfaceAccount` with `id`, `name`, `email`, and `roles`, but no `emailVerified`
- `code`: `packages/admin-surface/src/Host/AdminSurfaceSessionData.php` serializes backend session payloads with `account.emailVerified`
- `code`: `packages/admin/app/contracts/auth.ts` defines `AdminAccount.emailVerified?: boolean`, and `packages/admin/app/middleware/auth.global.ts` / `packages/admin/app/components/auth/VerificationBanner.vue` read that field
- `docs`: `docs/specs/admin-spa.md` describes the same gate using snake_case `email_verified`, which does not match either the backend payload or the frontend runtime contract

Contract impact:

- The shared host contract package does not fully describe the session payload the backend emits and the SPA consumes.
- Extension authors integrating against `packages/admin-surface/contract/types.ts` would miss the email-verification field entirely.
- The spec also uses a third naming variant, which increases the risk of mismatched host implementations across the published admin-surface integration boundary.

#### audit(contracts)(L6-interfaces): admin-surface catalog contract omits description emitted by backend and consumed by SPA runtime

- Concern: `contracts`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `code`: `packages/admin-surface/contract/types.ts` defines `AdminSurfaceCatalogEntry` without a `description` field
- `code`: `packages/admin-surface/src/Catalog/EntityDefinition.php` emits `description` in the backend catalog payload when present
- `code`: `packages/admin/app/contracts/catalog.ts` and `packages/admin/app/plugins/admin.ts` both model `description?: string` on catalog entries and pass it into the SPA runtime catalog
- `docs`: `docs/specs/admin-spa.md` describes the dashboard as rendering entity type cards from the admin-surface catalog and explicitly uses entry descriptions in the SPA-facing catalog shape

Contract impact:

- The shared admin-surface contract package is narrower than the payload the backend emits and the SPA consumes.
- Extension authors implementing hosts against `packages/admin-surface/contract/types.ts` would not know `description` is part of the published catalog surface.
- This weakens the reliability of the admin-surface integration boundary for custom hosts and alternate consumers.

#### audit(contracts)(L4-api): JsonApiRouteProvider contract omits the public api.discovery route and ApiDiscoveryController surface

- Concern: `contracts`
- Layer: `L4-api`
- Subsystem: `api-routing`
- Severity: `medium`
- Remediation class: `contract-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-contracts`

Evidence:

- `docs`: `docs/specs/api-layer.md` describes `JsonApiRouteProvider` as registering five CRUD routes per entity type and does not include the top-level discovery route in the route table
- `docs`: the API-layer package table omits `packages/api/src/ApiDiscoveryController.php` entirely
- `code`: `packages/api/src/JsonApiRouteProvider.php` always registers `api.discovery` at `GET /api` before per-entity CRUD routes
- `code`: `packages/api/src/ApiDiscoveryController.php` is a public controller surface returned by that route and documents itself as handling `GET /api`

Contract impact:

- The documented public route-provider surface is incomplete.
- Consumers treating `JsonApiRouteProvider::registerRoutes()` as the canonical contract would miss the discovery endpoint and its controller payload shape.
- This is contract drift on a public routing API, not just an internal implementation detail.

### Pass Summary

`M1-contracts` found that the largest contract drift is not in runtime method behavior but in the published seam definitions around the framework.

Main patterns:

- central public interfaces and specs are out of sync in `core-data`, especially around entity registration and revision storage
- extension surfaces in `foundation` and `admin-surface` are less coherent than advertised, with the interface, abstract base, shared contract package, and runtime consumers describing different public shapes
- API-layer public docs under-describe the actual route and controller surface, especially where discovery and route-access seams are involved
- admin-surface has repeated contract narrowing where the shared contract package lags both backend emitters and SPA consumers

Severity distribution:

- `high`: `#838`
- `medium`: `#832`, `#833`, `#834`, `#835`, `#836`, `#837`, `#839`, `#840`, `#841`

Layer concentration:

- `L0-foundation`: `#833`, `#838`
- `L1-core-data`: `#835`, `#837`
- `L4-api`: `#832`, `#834`, `#841`
- `L6-interfaces`: `#836`, `#839`, `#840`

Sequencing note for later remediation:

- `#838` is the most important contract finding because it indicates the framework lacks a single authoritative public type for its service-provider extension surface.
- The admin-surface contract issues should be remediated as a single batch later because they affect the same host-to-SPA integration boundary.
- The API-layer contract issues should be remediated together with spec updates so route, controller, and access semantics converge in one pass.

Hand-off:

- `M1-contracts` is ready to hand off to `M1-testing`.

## M1-testing

Status: in progress

This section freezes the `M1-testing` rubric before findings are emitted into GitHub.

### Rubric

The `M1-testing` pass checks six testing invariants:

1. invariant-focused test coverage
2. negative-path coverage
3. contract-verification tests for access, routing, entity and field semantics, and extension points
4. test harness quality
5. missing regression protection
6. layer-appropriate test placement

### Method

Initial evidence gathering for this pass starts with:

- inventorying test directories across PHP packages and the admin workspace
- mapping existing unit, contract, and integration tests to the invariants documented in the boundary and contract passes
- checking whether known public seams have regression tests that would fail on drift
- distinguishing missing test protection from the underlying boundary or contract findings that those tests should lock down

### Evidence Sources Used So Far

- `tests`
  - package test suites under `packages/*/tests`
  - repository integration suites under `tests/Integration`
- `code`
  - public seam implementations under `packages/foundation/`, `packages/api/`, `packages/admin-surface/`, and `packages/admin/`
  - previously identified contract-drift surfaces from `M1-contracts`

### Testing Classification Rule

- Do not reclassify existing boundary or contract findings as testing findings.
- If a testing gap leaves an existing boundary or contract issue unprotected, reference the upstream issue in the new finding but classify the new finding under `testing`.

### Formal Findings

#### audit(testing)(L4-api): serializer and schema field-access tests do not cover invalid partial access-context states

- Concern: `testing`
- Layer: `L4-api`
- Subsystem: `api-routing`
- Severity: `medium`
- Remediation class: `coverage-gap`
- Evidence sources: `tests`, `code`, `docs`
- Audit phase: `M1-testing`

Evidence:

- `docs`: `docs/specs/api-layer.md` and `docs/specs/field-access.md` define a paired-nullable contract for `ResourceSerializer::serialize()` and `SchemaPresenter::present()`
- `code`: `packages/api/src/ResourceSerializer.php` and `packages/api/src/Schema/SchemaPresenter.php` silently accept partial access context instead of rejecting it
- `tests`: `packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php` and `packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php` only cover the both-null and both-non-null happy paths
- `tests`: there are no tests exercising the invalid one-null / one-non-null states or asserting failure semantics for them

Testing impact:

- The suite verifies field filtering behavior but does not lock the documented invalid-state invariant.
- This leaves `#834` without negative-path regression protection.
- A future change could continue accepting partial access context silently and the current tests would still pass.

#### audit(testing)(L6-interfaces): admin-surface integration boundary lacks shared contract-verification tests for session and catalog payload shape

- Concern: `testing`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `high`
- Remediation class: `coverage-gap`
- Evidence sources: `tests`, `code`
- Audit phase: `M1-testing`

Evidence:

- `code`: the shared contract package in `packages/admin-surface/contract/types.ts` defines the nominal host-to-SPA boundary
- `code`: backend emitters in `packages/admin-surface/src/Host/AdminSurfaceSessionData.php` and `packages/admin-surface/src/Catalog/EntityDefinition.php` currently emit fields (`emailVerified`, `description`) that drift from the shared contract package
- `tests`: `packages/admin-surface/tests/Unit/Host/AdminSurfaceSessionDataTest.php` and `packages/admin-surface/tests/Unit/Catalog/CatalogBuilderTest.php` verify backend DTO/build output in isolation
- `tests`: `packages/admin/tests/components/auth/VerificationBanner.spec.ts` and SPA runtime tests verify frontend behavior in isolation
- `tests`: there are no cross-boundary tests that assert backend session/catalog payloads conform to the shared `packages/admin-surface/contract` types consumed by the SPA

Testing impact:

- The suite is split across backend and frontend layers but does not verify the published integration boundary itself.
- This left both `#839` and `#840` able to drift without a failing test.
- The gap affects a public extension seam, so missing regression protection is more severe than a normal unit-test omission.

#### audit(testing)(L0-foundation): foundation tests do not verify service-provider hook coherence across interface, base class, and kernel call sites

- Concern: `testing`
- Layer: `L0-foundation`
- Subsystem: `foundation-infra`
- Severity: `high`
- Remediation class: `coverage-gap`
- Evidence sources: `tests`, `code`, `docs`
- Audit phase: `M1-testing`

Evidence:

- `code`: `packages/foundation/src/ServiceProvider/ServiceProviderInterface.php`, `packages/foundation/src/ServiceProvider/ServiceProvider.php`, and the kernel call sites expose different effective hook surfaces
- `docs`: `docs/specs/plugin-extension-points.md` documents the richer hook surface as stable
- `tests`: `packages/foundation/tests/Unit/ServiceProvider/ServiceProviderTest.php` only checks bindings, tags, deferred behavior, and the default no-op `routes()` method
- `tests`: searches across `packages/foundation/tests` show no tests asserting that `commands()`, `middleware()`, `graphqlMutationOverrides()`, and `setKernelResolver()` are part of a coherent, stable provider contract across the interface, base class, and kernel orchestration

Testing impact:

- The suite exercises pieces of the provider system, but not the core extension invariant that the documented provider contract matches what kernels actually invoke.
- This left `#838` unguarded by any contract-verification test.
- Because this seam underpins package extensibility, the missing regression protection sits on a high-value foundation boundary.

#### audit(testing)(L0-foundation): kernel unit tests rely on reflection and private state instead of stable public seams

- Concern: `testing`
- Layer: `L0-foundation`
- Subsystem: `foundation-infra`
- Severity: `medium`
- Remediation class: `cleanup-candidate`
- Evidence sources: `tests`, `code`
- Audit phase: `M1-testing`

Evidence:

- `tests`: `packages/foundation/tests/Unit/Kernel/HttpKernelTest.php` repeatedly uses `ReflectionMethod`, `ReflectionProperty`, and `setAccessible()` to invoke private methods and mutate internal kernel state
- `tests`: the same suite sets internal config and provider arrays directly instead of exercising those behaviors through stable bootstrap or orchestration seams
- `tests`: `packages/foundation/tests/Unit/Http/ResponseSenderTest.php` and `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php` also use reflection to reach non-public methods
- `code`: the audited architecture explicitly treats kernels as composition roots to be verified through seams and integration points rather than private implementation hooks

Testing impact:

- These tests are brittle against internal refactors while providing weaker protection for public behavior.
- They bias the harness toward implementation detail instead of invariant verification.
- This increases maintenance cost and reduces confidence that passing tests reflect stable framework behavior.

#### audit(testing)(L6-interfaces): admin-surface route and host wiring has package-local unit tests but no root integration coverage

- Concern: `testing`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `high`
- Remediation class: `coverage-gap`
- Evidence sources: `tests`, `code`
- Audit phase: `M1-testing`

Evidence:

- `tests`: `packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderTest.php` verifies route registration and host handler behavior using a local fake host
- `tests`: searches across `tests/Integration` show no root integration tests covering `AdminSurfaceServiceProvider`, `GenericAdminSurfaceHost`, `/admin/_surface/session`, or `/admin/_surface/catalog`
- `code`: the admin workspace and admin-surface package form a cross-package interface seam with backend route registration, host serialization, and SPA bootstrap behavior spread across multiple packages
- `code`: `packages/admin/tests/setup.ts` stubs `/_surface/session` and `/_surface/catalog`, so frontend tests do not exercise the real backend-admin integration seam

Testing impact:

- The admin-surface seam has unit coverage inside each package, but no end-to-end regression protection at the repository integration level.
- That leaves route wiring, host payloads, and SPA bootstrap compatibility vulnerable to cross-package drift even when local unit suites pass.
- This gap is downstream of `#842` but broader: it affects route/bootstrap wiring, not just shared payload-shape verification.

#### audit(testing)(L0-foundation): foundation unit suites import higher-layer collaborators instead of testing through lower-layer seams

- Concern: `testing`
- Layer: `L0-foundation`
- Subsystem: `foundation-infra`
- Severity: `medium`
- Remediation class: `cleanup-candidate`
- Evidence sources: `tests`, `code`
- Audit phase: `M1-testing`

Evidence:

- `tests`: `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php` imports `Waaseyaa\Api\Http\DiscoveryApiHandler`, `Waaseyaa\SSR\SsrPageHandler`, and `Waaseyaa\Access\EntityAccessHandler`
- `tests`: `packages/foundation/tests/Unit/Kernel/HttpKernelTest.php` imports higher-layer collaborators including `Waaseyaa\Api\Http\DiscoveryApiHandler`, `Waaseyaa\SSR\SsrPageHandler`, `Waaseyaa\User\AnonymousUser`, and `Waaseyaa\User\DevAdminAccount`
- `tests`: these lower-layer suites construct or depend on higher-layer objects directly rather than isolating foundation behavior behind package-local seams or promoting the scenario to integration coverage
- `code`: the architecture model places `foundation` below API and interface-layer concerns, so importing those collaborators in foundation unit suites blurs the intended layer boundary inside the test harness

Testing impact:

- Layer placement in the test suite is less strict than the runtime architecture it is meant to protect.
- This makes lower-layer tests more sensitive to higher-layer churn and obscures whether a failure belongs to foundation logic or to a collaborator above it.
- The result is weaker signal from unit tests and less disciplined boundary protection in the harness itself.

### Pass Summary

`M1-testing` found that the repository has broad test quantity, but coverage is uneven at the exact seams where architectural drift is occurring.

Main patterns:

- high-value public seams are often tested in isolation on each side but not verified end-to-end across the real boundary
- negative-path coverage drops off where contracts declare invalid states or shape guarantees rather than ordinary success behavior
- foundation tests include implementation-detail assertions and reflection-heavy access patterns that reduce harness quality
- lower-layer test suites do not always respect the same layer discipline expected of production code

Severity distribution:

- `high`: `#842`, `#843`, `#846`
- `medium`: `#844`, `#845`, `#847`

Layer concentration:

- `L0-foundation`: `#843`, `#845`, `#847`
- `L4-api`: `#844`
- `L6-interfaces`: `#842`, `#846`

Sequencing note for later remediation:

- `#842` and `#846` should be treated as one admin-surface verification program later: shared contract verification plus root integration coverage for route/bootstrap wiring.
- `#843`, `#845`, and `#847` should be sequenced together as a foundation harness cleanup pass because they all affect the signal quality of lower-layer verification.
- `#844` should be fixed alongside the remediation for `#834` so the contract change and the negative-path regression tests land together.

Hand-off:

- `M1-testing` is ready to hand off to `M1-docs-governance`.

## M1-docs-governance

Status: in progress

This section freezes the `M1-docs-governance` rubric before findings are emitted into GitHub.

### Rubric

The `M1-docs-governance` pass checks five documentation and governance invariants:

1. accuracy and completeness of `CLAUDE.md`, `AGENTS.md`, `README.md`, and `docs/specs/**`
2. alignment between documented architecture and actual layer and subsystem topology
3. drift in milestone tables and issue workflow expectations
4. whether codified context matches the architecture revealed in `M1-boundaries`, `M1-contracts`, and `M1-testing`
5. whether contract packages and subsystem specs remain authoritative

### Method

Initial evidence gathering for this pass starts with:

- comparing the canonical layer and package descriptions in `CLAUDE.md` and `README.md` against the package roster and dependency-map drift already captured in `M1-boundaries`
- checking whether the orchestration table and subsystem specs still cover the active package surface and identify authoritative sources for each subsystem
- verifying whether subsystem specs that are treated as authoritative still match the public contracts and runtime payloads examined in `M1-contracts`
- checking workflow and governance docs for expectations that no longer match the current repository structure or audit program

### Evidence Sources Used So Far

- `docs`
  - `CLAUDE.md`
  - `AGENTS.md`
  - `README.md`
  - `docs/specs/workflow.md`
  - `docs/specs/entity-system.md`
  - `docs/specs/api-layer.md`
  - `docs/specs/admin-spa.md`
  - `docs/specs/package-discovery.md`
  - `docs/specs/plugin-extension-points.md`
- `code`
  - package manifests under `packages/*/composer.json`
  - contract packages and runtime emitters under `packages/admin-surface/`, `packages/api/`, and `packages/foundation/`
- `tests`
  - pass evidence from `M1-testing` where documentation promises authoritative verification that is absent
- `dependency graph`
  - package roster and dependency-map evidence from `M1-boundaries`

### Docs-Governance Classification Rule

- Do not reclassify boundary, contract, or testing findings as docs-governance findings.
- If a documentation or governance source continues to present stale architecture or stale public contracts as authoritative, open a `docs-governance` finding and reference the upstream audit issues that exposed the drift.

### Formal Findings

#### audit(docs-governance)(cross-layer): canonical architecture documents publish an incomplete framework package topology

- Concern: `docs-governance`
- Layer: `cross-layer`
- Subsystem: `package-topology`
- Severity: `high`
- Remediation class: `governance-drift`
- Evidence sources: `docs`, `code`, `dependency graph`
- Audit phase: `M1-docs-governance`

Evidence:

- `docs`: `CLAUDE.md` states the monorepo contains `52 PHP packages` and publishes a 7-layer table that omits active Composer packages including `cms`, `core`, `engagement`, `full`, `geo`, `mercure`, `messaging`, and `oauth-provider`
- `docs`: `README.md` repeats the outdated package count as `52 independent packages` and mirrors the same incomplete layer table
- `docs`: both `CLAUDE.md` and `README.md` model `admin` as a layer package in the architecture table even though the repository implementation is a JS workspace rather than a Composer package
- `code`: the repository currently contains `55` package `composer.json` manifests under `packages/`
- `dependency graph`: `M1-boundaries` already recorded the omitted Composer package set in `#825` and the `admin` topology mismatch in `#824`

Docs-governance impact:

- The two most canonical architecture sources publish a topology that is no longer the system being audited.
- This weakens milestone planning, layer reasoning, and package ownership because contributors are pointed at an incomplete package map before they ever reach subsystem specs.
- The drift is governance-level because these files are treated as the constitution for architecture and workflow decisions.

#### audit(docs-governance)(cross-layer): codified context does not cover the full active subsystem surface or authoritative ownership

- Concern: `docs-governance`
- Layer: `cross-layer`
- Subsystem: `docs-specs`
- Severity: `medium`
- Remediation class: `docs-drift`
- Evidence sources: `docs`, `code`
- Audit phase: `M1-docs-governance`

Evidence:

- `docs`: the orchestration table in `CLAUDE.md` leaves several active package families with no specialist context or canonical spec coverage, including `packages/graphql/*`, `packages/search/*`, `packages/ssr/*`, `packages/telescope/*`, `packages/workflows/*`, `packages/billing/*`, `packages/github/*`, and `packages/deployer/*`
- `docs`: the same table has no entries at all for active Composer packages `engagement`, `geo`, `mercure`, `messaging`, and `oauth-provider`
- `docs`: `AGENTS.md` tells contributors to rely on `CLAUDE.md` as the authoritative codified context workflow, so these omissions are not merely optional reference gaps
- `code`: the active package roster under `packages/*/composer.json` includes those package families as first-class repository surfaces

Docs-governance impact:

- The codified-context workflow cannot direct contributors to an authoritative source for a non-trivial part of the active repository.
- This creates silent governance drift: the repo has real subsystems that exist in code but not in the declared ownership and context map.
- The result is inconsistent auditability and a higher chance that future work proceeds without an agreed source of truth.

#### audit(docs-governance)(cross-layer): subsystem specs continue to present stale public contracts as authoritative sources

- Concern: `docs-governance`
- Layer: `cross-layer`
- Subsystem: `docs-specs`
- Severity: `high`
- Remediation class: `governance-drift`
- Evidence sources: `docs`, `code`, `tests`
- Audit phase: `M1-docs-governance`

Evidence:

- `docs`: `docs/specs/entity-system.md`, `docs/specs/api-layer.md`, `docs/specs/admin-spa.md`, `docs/specs/package-discovery.md`, and `docs/specs/plugin-extension-points.md` all describe themselves as subsystem specifications or stable contracts
- `docs`: those specs publish public surfaces that `M1-contracts` found to be stale, including outdated `EntityTypeManagerInterface` and `RevisionableStorageInterface` contracts (`#835`, `#837`), omitted `api.discovery` route-provider surface (`#841`), and incomplete admin-surface session and catalog payload contracts (`#839`, `#840`)
- `docs`: `docs/specs/plugin-extension-points.md` and `docs/specs/package-discovery.md` describe service-provider extension hooks as stable even though the interface, base class, and kernel call sites are already divergent (`#838`)
- `code`: the actual runtime and contract package surfaces in `packages/admin-surface/contract/types.ts`, `packages/api/src/JsonApiRouteProvider.php`, `packages/api/src/ApiDiscoveryController.php`, and `packages/foundation/src/ServiceProvider/*` do not match those published specs
- `tests`: `M1-testing` found no shared contract-verification coverage that would currently force these published specs and runtime surfaces back into alignment (`#842`, `#843`)

Docs-governance impact:

- The issue is no longer just individual contract drift; the governance problem is that stale subsystem specs still present themselves as authoritative.
- That undermines the codified-context model, because contributors cannot tell whether the contract package, the runtime surface, or the spec is the source of truth.
- Without an explicit authority model, future fixes risk updating one artifact while leaving the others to drift again.

#### audit(docs-governance)(cross-layer): workflow milestone table no longer reflects the repo's actual GitHub roadmap

- Concern: `docs-governance`
- Layer: `cross-layer`
- Subsystem: `workflow-governance`
- Severity: `high`
- Remediation class: `governance-drift`
- Evidence sources: `docs`, `code`
- Audit phase: `M1-docs-governance`

Evidence:

- `docs`: `docs/specs/workflow.md` says milestone tables are authoritative and must be updated whenever milestones are added, closed, or redescribed
- `docs`: the documented milestone table only covers `v0.7` through `v2.0`
- `code`: the live GitHub milestone set contains additional framework milestones not represented in the table, including early historical milestones such as `v0.1 — Framework Identity`, `v0.2 — Auth & Storage`, `v0.3 — Native SSR Engine`, and `v0.5 — Diidjaaheer`
- `code`: the live GitHub milestone set also contains active milestones absent from the workflow table, including `Phase 1: Entity Extensions` and `M1: Framework Architectural Audit`
- `docs`: the workflow rules state that milestones define the roadmap and that contributors should align to the active milestone structure

Docs-governance impact:

- The governance doc declares the milestone table authoritative, but the actual roadmap now lives partly outside that table.
- Contributors following the documented workflow cannot reconstruct the real milestone history or the currently active roadmap from the spec alone.
- This is governance drift rather than a product-planning disagreement because the documented process explicitly makes milestone state part of the repo's operating contract.

#### audit(docs-governance)(L6-interfaces): admin-surface documentation splits authority between the contract package and a contradictory subsystem spec

- Concern: `docs-governance`
- Layer: `L6-interfaces`
- Subsystem: `admin-spa`
- Severity: `high`
- Remediation class: `governance-drift`
- Evidence sources: `docs`, `code`, `tests`
- Audit phase: `M1-docs-governance`

Evidence:

- `code`: `packages/admin-surface/contract/types.ts` declares itself as the shared types defining the integration boundary between the admin SPA and host applications
- `code`: the SPA-facing contracts in `packages/admin/app/contracts/auth.ts` and `packages/admin/app/contracts/catalog.ts` already extend beyond that shared package with `emailVerified?: boolean` and `description?: string`
- `docs`: `docs/specs/admin-spa.md` documents the same public surface using a third vocabulary, including `currentUser.email_verified` and `auth.require_verified_email`, while elsewhere describing catalog cards that rely on entry descriptions
- `code`: the runtime backend and SPA use camelCase `emailVerified` and `requireVerifiedEmail`, as shown in `packages/admin-surface/src/Host/AdminSurfaceSessionData.php`, `packages/admin/app/middleware/auth.global.ts`, and `packages/admin/app/components/auth/VerificationBanner.vue`
- `tests`: `M1-testing` found no shared contract-verification tests forcing the contract package, subsystem spec, backend emitter, and SPA consumer back into a single authoritative shape (`#842`)
- Upstream contract context: `#839` and `#840`

Docs-governance impact:

- The subsystem has no single named authority for its host-to-SPA public surface.
- Extension authors can read the contract package, the subsystem spec, and the SPA contracts and get three different answers about the same boundary.
- This is governance drift because the repo has both a contract package and a subsystem spec, but neither is being maintained as the unambiguous source of truth.

#### audit(docs-governance)(cross-layer): README onboarding flow points at a package identity that does not match the framework monorepo

- Concern: `docs-governance`
- Layer: `cross-layer`
- Subsystem: `docs-specs`
- Severity: `medium`
- Remediation class: `docs-drift`
- Evidence sources: `docs`, `code`
- Audit phase: `M1-docs-governance`

Evidence:

- `docs`: `README.md` tells users to bootstrap a site with `composer create-project waaseyaa/waaseyaa my-site`
- `code`: the root Composer package for this repository is `waaseyaa/framework`, declared in `composer.json`
- `code`: the monorepo also contains meta-packages such as `waaseyaa/core`, `waaseyaa/cms`, and `waaseyaa/full`, but no local package named `waaseyaa/waaseyaa`
- `docs`: the same README presents itself as the primary onboarding surface for the framework repo

Docs-governance impact:

- A new contributor or evaluator can follow the README and immediately target a package identity that is not represented in the audited repository.
- This is onboarding drift rather than a runtime bug, but it weakens the framework's canonical entry path and makes the public docs less trustworthy.
- The issue sits at governance level because the top-level README is the repo's primary public contract for how to adopt the framework.

### Pass Summary

`M1-docs-governance` found that the main source-of-truth problem in this repo is not a lack of documentation volume, but a lack of maintained authority boundaries between canonical docs, subsystem specs, contract packages, and the live GitHub roadmap.

Main patterns:

- canonical architecture and onboarding docs drifted away from the actual repository package surface and adoption path
- codified-context guidance in `CLAUDE.md` no longer covers the full active subsystem roster or authoritative ownership model
- several subsystem specs still present themselves as authoritative after contracts and tests already showed that their published public surfaces are stale
- workflow governance docs declare milestone tables authoritative even though the live roadmap now exists partly outside the documented table
- admin-surface is the clearest authority-split example: contract package, subsystem spec, backend runtime, and SPA contracts all describe the same public boundary differently

Severity distribution:

- `high`: `#848`, `#849`, `#851`, `#852`
- `medium`: `#850`, `#853`

Layer concentration:

- `cross-layer`: `#848`, `#849`, `#850`, `#852`, `#853`
- `L6-interfaces`: `#851`

Sequencing note for later remediation:

- `#848`, `#850`, and `#853` should be treated as one codified-context and onboarding cleanup program covering the canonical architecture map, package roster coverage, and top-level adoption flow.
- `#849` and `#851` should be treated as one authority-model program: each affected subsystem needs an explicit decision about whether the spec, the contract package, or the runtime surface is authoritative, followed by synchronized updates and regression protection.
- `#852` should be remediated alongside any broader milestone-governance cleanup so the documented roadmap and the live GitHub milestone set are reconciled in one pass.

Hand-off:

- `M1-docs-governance` is ready to hand off to `M1-dx-tooling`.

## M1-dx-tooling

Status: in progress

This section freezes the `M1-dx-tooling` rubric before findings are emitted into GitHub.

### Rubric

The `M1-dx-tooling` pass checks six tooling and developer-experience invariants:

1. Composer scripts and package manifests
2. local test and analysis ergonomics
3. scaffolding fidelity
4. package discoverability
5. diagnostics and operator tooling
6. developer workflow sharp edges

### Method

Initial evidence gathering for this pass starts with:

- comparing the documented top-level workflow commands against the actual root Composer script surface and package-local script surfaces
- checking whether package manifests expose the metadata the package discovery tooling expects contributors to use
- verifying that scaffolding and operator commands exist where the docs and command surfaces say they do
- looking for hidden registration paths, manual boot wiring, or shell-script coupling that makes local work harder than the public workflow suggests

### Evidence Sources Used So Far

- `code`
  - root `composer.json`
  - package manifests under `packages/*/composer.json`
  - `bin/waaseyaa`
  - `packages/foundation/src/Discovery/PackageManifestCompiler.php`
  - `packages/foundation/src/Kernel/ConsoleKernel.php`
  - `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php`
  - `packages/admin/package.json`
  - scaffolding commands under `packages/cli/src/Command/*`
- `docs`
  - `README.md`
  - `CLAUDE.md`
  - package READMEs and subsystem specs that describe package discovery and local workflows

### DX-Tooling Classification Rule

- Do not reclassify boundary, contract, testing, or docs-governance findings as dx-tooling findings.
- If a contributor-facing workflow, manifest surface, or operator tool is harder to use because of hidden wiring, missing top-level affordances, or inconsistent tooling entry points, classify it under `dx-tooling` even when the underlying subsystem also has boundary or docs drift.

### Formal Findings

#### audit(dx-tooling)(cross-layer): package discovery tooling is not the authoritative registration path for active command and route surfaces

- Concern: `dx-tooling`
- Layer: `cross-layer`
- Subsystem: `package-topology`
- Severity: `high`
- Remediation class: `tooling-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-dx-tooling`

Evidence:

- `code`: `packages/foundation/src/Discovery/PackageManifestCompiler.php` loads coarse-grained `providers`, `commands`, and `routes` from `extra.waaseyaa` metadata in package `composer.json`
- `code`: active packages with contributor-visible route or command surfaces lack that metadata entirely, including `waaseyaa/cli`, `waaseyaa/api`, `waaseyaa/graphql`, `waaseyaa/mcp`, and `waaseyaa/telescope`
- `code`: those surfaces are instead wired through hidden/manual registration paths such as the large hard-coded command list in `packages/foundation/src/Kernel/ConsoleKernel.php`
- `code`: the root package has no `extra.waaseyaa` metadata either, so the manifest compiler cannot serve as a single declarative registry for repo-level tooling surfaces
- `docs`: package discovery docs and package READMEs tell contributors to think in terms of manifest compilation and `optimize:manifest`, but the actual command and route inventory still depends on manual kernel wiring

DX-tooling impact:

- Contributors cannot rely on package manifests as the authoritative place to discover how new commands, providers, or routes become active.
- Adding a new tooling surface requires knowledge of hidden registration paths inside the kernels, which raises the cost of extension and makes onboarding less mechanical than the framework claims.
- This is a tooling gap rather than a docs-only problem because the discovery mechanism itself is not covering the active developer-facing surfaces.

#### audit(dx-tooling)(cross-layer): the root workflow surface lacks a unified verification entry point for the full monorepo

- Concern: `dx-tooling`
- Layer: `cross-layer`
- Subsystem: `build-tooling`
- Severity: `medium`
- Remediation class: `tooling-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-dx-tooling`

Evidence:

- `code`: `composer run --list` at the repo root exposes only `cs-check`, `cs-fix`, `dev`, and `phpstan`
- `code`: the repo has no root Composer script for running the full PHP test suite, no root wrapper for admin workspace tests or builds, and no single verification command spanning the monorepo surfaces
- `code`: the admin workspace has its own independent script surface in `packages/admin/package.json` (`test`, `build`, `test:e2e`, etc.), but those entry points are package-local knowledge rather than part of the root workflow
- `docs`: `CLAUDE.md` and `README.md` document multiple verification flows across PHPUnit and admin workspace commands, but those flows are not represented as a coherent top-level command surface

DX-tooling impact:

- A contributor landing in the repo root does not get a single command model for verifying the project.
- Verification requires switching between direct PHPUnit invocation and package-local npm commands by memory, which increases workflow friction and makes automation less uniform.
- The problem is especially visible in a monorepo that otherwise presents itself as a unified framework workspace.

#### audit(dx-tooling)(cross-layer): composer dev couples two long-running processes through a brittle shell one-liner

- Concern: `dx-tooling`
- Layer: `cross-layer`
- Subsystem: `build-tooling`
- Severity: `medium`
- Remediation class: `cleanup-candidate`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-dx-tooling`

Evidence:

- `code`: the root `composer.json` `dev` script starts the PHP built-in server in the background, launches `npm run dev --prefix packages/admin`, and then relies on `kill $!` in the same one-liner for teardown
- `docs`: `CLAUDE.md` presents `composer dev` as the canonical way to start the dev server stack
- `code`: the script has no trap or supervisor semantics for partial failure, signal forwarding, or cleanup when one side exits unexpectedly
- `code`: the repo already treats PHP and admin workspace development as a two-process workflow, so this entry point is a primary contributor touchpoint rather than an internal helper

DX-tooling impact:

- The main local-development entry point depends on fragile shell coupling rather than a resilient process orchestration model.
- When one side of the stack fails or is interrupted unexpectedly, cleanup behavior is less predictable and debugging the dev loop becomes harder than it needs to be.
- This is a developer-workflow sharp edge rather than a pure shell-style nit because the script is the published top-level entry point for local development.

#### audit(dx-tooling)(cross-layer): package-local discoverability is uneven because many active packages have no README surface

- Concern: `dx-tooling`
- Layer: `cross-layer`
- Subsystem: `package-topology`
- Severity: `medium`
- Remediation class: `tooling-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-dx-tooling`

Evidence:

- `code`: a non-trivial set of active packages under `packages/` has no package-local `README.md`, including `admin-surface`, `auth`, `billing`, `deployer`, `engagement`, `geo`, `github`, `graphql`, `http-client`, `inertia`, `ingestion`, `mercure`, `messaging`, `notification`, `oauth-provider`, and `scheduler`
- `code`: these packages still present as first-class package identities through their `composer.json` manifests and are part of the active repo surface
- `docs`: the repo relies on package-level descriptions, codified context, and subsystem specs for discoverability, but package-local entry points are inconsistent across the monorepo

DX-tooling impact:

- Contributors scanning the monorepo cannot assume that each package has a minimal local entry point describing purpose, activation model, and important commands.
- This makes package discoverability uneven and increases the cost of finding the right tooling or subsystem entry path from the codebase alone.
- The issue is tooling-facing because it affects how engineers navigate and reason about the monorepo package surface during day-to-day work.

#### audit(dx-tooling)(cross-layer): implemented CLI operator and scaffolding commands are missing from the registered console surface

- Concern: `dx-tooling`
- Layer: `cross-layer`
- Subsystem: `build-tooling`
- Severity: `high`
- Remediation class: `tooling-gap`
- Evidence sources: `code`, `docs`
- Audit phase: `M1-dx-tooling`

Evidence:

- `code`: the repo contains implemented CLI command classes for `queue:work`, `queue:failed`, `queue:retry`, `queue:flush`, `schedule:run`, `schedule:list`, `scaffold:auth`, and `telescope:validate` under `packages/cli/src/Command/*`
- `code`: `php bin/waaseyaa list --raw` does not expose any of those commands
- `code`: `packages/foundation/src/Kernel/ConsoleKernel.php` manually registers many commands, but none of those command classes appear in the registration list
- `docs`: `docs/specs/operations-playbooks.md` documents queue and scheduling commands as operational workflows even though they are absent from the actual CLI surface

DX-tooling impact:

- Operator and scaffolding capabilities exist in code but are unavailable through the framework's public CLI entry point.
- This creates a particularly sharp tooling trap because both contributors and operators can discover the command classes or read the playbooks and still fail to execute the documented workflows.
- The issue is tooling-facing rather than docs-only because the console registration surface itself is incomplete.

### Pass Summary

`M1-dx-tooling` found that the framework's developer-experience story is strongest at the level of raw capabilities, but weaker at the level of coherent, authoritative entry points.

Main patterns:

- package discovery and manifest tooling are not yet the single authoritative activation model for developer-facing routes, commands, and providers
- the root repo workflow surface is thinner than the real monorepo workflow, forcing contributors to mix root Composer commands, direct PHPUnit calls, and package-local npm scripts by memory
- the main local-development entry point relies on brittle shell coupling instead of resilient process orchestration
- package and CLI discoverability are uneven: some packages lack local entry points, and some implemented commands are not actually reachable from the registered console surface

Severity distribution:

- `high`: `#854`, `#858`
- `medium`: `#855`, `#856`, `#857`

Layer concentration:

- `cross-layer`: `#854`, `#855`, `#856`, `#857`, `#858`

Sequencing note for later remediation:

- `#854` and `#858` should be treated as one command-and-discovery activation program: align manifest metadata, console registration, and operator/scaffold command exposure so tooling surfaces become declarative and reachable.
- `#855` and `#856` should be treated as one root-workflow ergonomics pass covering resilient local dev orchestration and a unified verification entry point.
- `#857` should be handled alongside the codified-context remediation work from `M1-docs-governance`, because package discoverability depends on both package-local entry points and canonical repo navigation aids.

Hand-off:

- `M1-dx-tooling` is complete.

## M2-remediation-planning

Status: in progress

This section freezes the `M2-remediation-planning` rubric before remediation-planning artifacts are emitted into GitHub.

### Rubric

The `M2-remediation-planning` pass checks six planning invariants:

1. grouping all `M1` findings into remediation themes
2. constructing a dependency-ordered remediation graph across `boundaries`, `contracts`, `testing`, `docs-governance`, and `dx-tooling`
3. identifying cross-layer sequencing constraints
4. defining uplift phases that preserve architectural invariants
5. distinguishing high-risk versus low-risk remediation clusters
6. producing a milestone-ready remediation roadmap

### Method

Initial synthesis for this pass starts with:

- treating `#823` through `#858` as the complete `M1` finding inventory and clustering them by remediation intent rather than by original audit concern alone
- deriving theme dependencies from the already documented sequencing notes in `M1-boundaries`, `M1-contracts`, `M1-testing`, `M1-docs-governance`, and `M1-dx-tooling`
- separating foundational sequencing blockers from work that can be parallelized once architectural invariants are re-established
- mapping each remediation theme to an uplift phase that can be executed without collapsing layer boundaries or weakening public contracts during the transition

### Remediation-Planning Classification Rule

- Do not re-audit `M1` findings and do not reclassify them into new concern types.
- Every `M2` artifact must reference existing `M1` issues and explain sequencing, dependency, or phase placement only.
- Keep all `M2` work inside the remediation-planning concern: grouping, dependency modeling, sequencing, and roadmap construction.

### Remediation Themes

`M2` clusters the `M1` finding set into five remediation themes.

#### Theme A: Architectural Core Hardening

Scope:

- restore the declared layer and package topology as an actual enforcement surface
- move cross-layer orchestration back into named composition roots
- remove hidden upward seams and interface-layer backreferences from lower layers
- make package and command activation declarative rather than kernel-special-case driven

Primary input findings:

- `#823`, `#824`, `#825`, `#826`, `#827`, `#828`, `#829`, `#830`, `#831`, `#848`, `#850`, `#854`, `#857`, `#858`

Risk:

- `high`

#### Theme B: Public Contract And Authority Unification

Scope:

- establish one authoritative public type per extension seam
- align runtime surfaces, contract packages, and subsystem specs
- eliminate concrete-type leakage from framework extension points
- resolve admin-surface, entity-system, and API public-surface drift

Primary input findings:

- `#832`, `#833`, `#834`, `#835`, `#836`, `#837`, `#838`, `#839`, `#840`, `#841`, `#849`, `#851`

Risk:

- `high`

#### Theme C: Verification And Harness Realignment

Scope:

- add contract-verification coverage around the corrected public seams
- realign lower-layer tests to stable seams instead of reflection and higher-layer collaborators
- add missing integration coverage where package-local tests do not protect the real boundary

Primary input findings:

- `#842`, `#843`, `#844`, `#845`, `#846`, `#847`

Risk:

- `medium`

#### Theme D: Codified Context And Governance Alignment

Scope:

- bring canonical architecture docs, milestone governance, and onboarding docs back into sync with the hardened framework
- decide and document authority boundaries between specs, contract packages, and runtime surfaces
- align package discoverability guidance with the actual monorepo topology

Primary input findings:

- `#848`, `#849`, `#850`, `#851`, `#852`, `#853`, `#857`

Risk:

- `medium`

#### Theme E: Root Workflow And Operator Ergonomics

Scope:

- make the top-level workflow surface coherent for development, verification, and operations
- expose implemented commands through the public CLI
- replace brittle process coupling and hidden command paths with stable operator entry points

Primary input findings:

- `#854`, `#855`, `#856`, `#858`

Risk:

- `medium`

### Remediation DAG

The remediation dependency graph is:

1. Theme A has no upstream dependency inside `M2`; it is the architectural base.
2. Theme B depends on Theme A.
3. Theme C depends on Theme B, and partially on Theme A where test placement is affected by topology repair.
4. Theme D depends on Theme A and Theme B.
5. Theme E depends on Theme A, and partially on Theme D where root workflow and discoverability surfaces are documented together.

Compact DAG:

- `A -> B`
- `A -> C`
- `A -> D`
- `A -> E`
- `B -> C`
- `B -> D`
- `D -> E` (documentation-facing portion only)

### Cross-Layer Sequencing Constraints

1. Topology and activation-model fixes must land before contract cleanup when the contract surface is currently routed through invalid or hidden composition seams.
2. Contract unification must land before new contract-verification tests, otherwise the test harness will lock down the wrong surface.
3. Codified-context and subsystem-spec updates should not be treated as a substitute for runtime contract repair; docs should follow the chosen authority model, not lead it.
4. Root workflow ergonomics should not wrap commands or flows that are still hidden or manually registered; command exposure and activation must stabilize first.
5. Admin-surface remediation spans boundaries, contracts, testing, docs, and DX; it can be parallelized internally only after the core authority decision for the host-to-SPA boundary is made.

### Uplift Phases

#### Phase 1: Architectural Base Recovery

Theme coverage:

- Theme A

Goal:

- restore the layer model, composition-root containment, package topology truth, and declarative activation surfaces

Parallelization:

- mostly non-parallelizable
- boundary fixes touching kernel/bootstrap/provider activation should be sequenced tightly

#### Phase 2: Public Surface Unification

Theme coverage:

- Theme B

Goal:

- make every major extension seam and public boundary coherent before new tests and docs are written around it

Parallelization:

- can split into substreams after foundation authority is decided:
  - foundation provider contract
  - admin-surface host contract
  - API/entity-system public surfaces

#### Phase 3: Verification Lock-In

Theme coverage:

- Theme C

Goal:

- add regression protection only after the corrected seams are in place

Parallelization:

- can run in parallel across foundation, API, and admin-surface once Phase 2 decisions are merged

#### Phase 4: Authority And Governance Sync

Theme coverage:

- Theme D

Goal:

- update canonical docs, milestone governance, and subsystem authority boundaries to reflect the remediated architecture

Parallelization:

- partially parallelizable
- subsystem-spec alignment should trail the runtime/public-surface changes they describe

#### Phase 5: Workflow And Operator Polish

Theme coverage:

- Theme E

Goal:

- make the top-level workflow and operator tooling coherent after activation and authority models are stable

Parallelization:

- largely parallelizable after Themes A and D stabilize

### High-Risk And Low-Risk Clusters

High-risk clusters:

- Theme A: kernel/bootstrap/provider activation and package-topology remediation
- foundation slice of Theme B: service-provider contract authority
- admin-surface slice spanning Themes B and C: host contract, runtime payloads, and end-to-end verification

Lower-risk clusters:

- Theme D onboarding and package-discoverability cleanup (`#850`, `#853`, `#857`)
- Theme E root workflow wrappers and dev-process ergonomics once command exposure is corrected

### Milestone-Ready Roadmap

Recommended execution roadmap:

1. `M3: Architectural Base Recovery`
   - deliver Theme A only
   - exit when layer topology, activation model, and composition-root boundaries are hardened
2. `M4: Public Surface Unification`
   - deliver Theme B
   - exit when foundation, API, entity-system, and admin-surface public contracts have single authoritative surfaces
3. `M5: Verification Lock-In`
   - deliver Theme C
   - exit when new seams are protected by contract, integration, and lower-layer harness tests
4. `M6: Governance And Discoverability Alignment`
   - deliver Theme D and the doc/discoverability portion of Theme E
   - exit when canonical docs and subsystem authority boundaries reflect the remediated framework
5. `M7: Workflow And Operator Ergonomics`
   - deliver the remaining Theme E work
   - exit when root workflow, command exposure, and local/operator ergonomics are coherent

### Parallelization Notes

Safe parallel lanes after Phase 1:

- API/entity-system contract cleanup
- admin-surface contract cleanup
- foundation contract authority cleanup

Safe parallel lanes after Phase 2:

- foundation harness cleanup
- API negative-path and contract verification
- admin-surface shared-boundary and integration coverage

Not good parallelization candidates:

- kernel/bootstrap/provider activation refactors across foundation
- any work that tries to update subsystem docs before the corresponding authority decision is merged

### Milestone Drafts

#### M3: Architectural Base Recovery

Scope boundaries:

- includes Theme A only
- covers topology truth, composition-root containment, upward-seam hardening, declarative activation, and package/CLI discoverability foundations
- excludes public contract cleanup, new regression coverage, and final docs authority sync except where needed to support the activation model

Assigned themes and finding clusters:

- Theme A
- `#823`, `#824`, `#825`, `#826`, `#827`, `#828`, `#829`, `#830`, `#831`
- `#848`, `#850`
- `#854`, `#857`, `#858`

Dependency justification:

- this milestone sits at the root of the remediation DAG
- downstream milestones `M4`, `M5`, `M6`, and `M7` all depend on the architecture and activation model becoming trustworthy first

Sequencing notes:

1. package-topology and composition-root truth first
2. provider/bootstrap/orchestration seam cleanup second
3. declarative activation and command discoverability third
4. low-risk package discoverability cleanup last inside the milestone

Risk considerations:

- highest-risk milestone in the roadmap
- work spans foundation, kernel/bootstrap, provider activation, and cross-layer routing/registration seams
- should be executed as tightly controlled slices rather than many parallel changes

Expected outputs and exit criteria:

- layer/package topology is aligned with runtime activation
- cross-layer orchestration is contained to approved roots
- activation surfaces are declarative enough for downstream contract and DX work to rely on them
- all assigned findings are either remediated or intentionally split into downstream implementation issues without reopening the architectural base

#### M4: Public Surface Unification

Scope boundaries:

- includes Theme B only
- covers public type authority, runtime/contract/spec alignment, and concrete-type leakage removal
- excludes new test lock-in and broad docs/governance cleanup beyond the contract authority choices required to complete this milestone

Assigned themes and finding clusters:

- Theme B
- `#832`, `#833`, `#834`, `#835`, `#836`, `#837`, `#838`, `#839`, `#840`, `#841`
- `#849`, `#851`

Dependency justification:

- depends on `M3` because many contract surfaces are currently routed through invalid topology or hidden activation paths
- must complete before `M5` so verification does not lock in the wrong public seam
- must complete before `M6` so governance/docs work reflects a real authority decision

Sequencing notes:

1. foundation provider authority first
2. API/entity-system public surface alignment second
3. admin-surface host/session/catalog authority third
4. cross-artifact authority sync last within the milestone

Risk considerations:

- high-risk due to foundational extension hooks and admin-surface authority decisions
- should allow limited parallelization only after the foundation provider contract decision is settled

Expected outputs and exit criteria:

- each major public seam has one authoritative type or contract surface
- runtime, contract package, and spec-level public surfaces no longer disagree on the same boundary
- concrete-type leakage is removed from framework extension APIs where M1 identified it
- `M5` can write verification against stable, intentional seams

#### M5: Verification Lock-In

Scope boundaries:

- includes Theme C only
- covers contract-verification tests, integration protection, and lower-layer harness cleanup
- excludes runtime contract redesign and broad docs/governance work

Assigned themes and finding clusters:

- Theme C
- `#842`, `#843`, `#844`, `#845`, `#846`, `#847`

Dependency justification:

- depends on `M4` because verification must follow the corrected public surfaces
- partially depends on `M3` because lower-layer test placement should not be repaired before topology and activation paths are corrected

Sequencing notes:

1. contract-verification gaps on authoritative seams first
2. admin-surface integration coverage and shared-boundary verification second
3. foundation harness cleanup third
4. remaining negative-path and lower-layer placement cleanup last

Risk considerations:

- medium risk overall, but admin-surface and foundation harness work still touch cross-package seams
- most parallelizable milestone after `M4`

Expected outputs and exit criteria:

- corrected public seams are protected by contract, integration, and negative-path coverage where required
- lower-layer suites verify through stable seams rather than reflection or higher-layer collaborators
- future regressions in the remediated architecture would fail tests close to the affected boundary

#### M6: Governance And Discoverability Alignment

Scope boundaries:

- includes Theme D and the docs/discoverability overlap that depends on M3/M4 outputs
- covers canonical docs, subsystem authority decisions, milestone governance, onboarding, and package discoverability guidance
- excludes root workflow/operator polish that still depends on final tooling surfaces in `M7`

Assigned themes and finding clusters:

- Theme D
- `#848`, `#849`, `#850`, `#851`, `#852`, `#853`, `#857`

Dependency justification:

- depends on `M3` and `M4` because docs and authority models must follow corrected architecture and public surfaces
- should precede the documentation-facing part of `M7` so workflow ergonomics are documented only once

Sequencing notes:

1. authority-model decisions reflected in subsystem specs first
2. canonical architecture and package roster sync second
3. milestone/workflow governance sync third
4. onboarding and package-discoverability cleanup last

Risk considerations:

- medium risk
- lower technical blast radius than `M3`/`M4`, but high coordination risk if attempted before runtime decisions settle

Expected outputs and exit criteria:

- canonical docs reflect the remediated architecture and public authority model
- milestone/workflow docs reflect the actual roadmap model chosen for remediation
- onboarding and discoverability surfaces no longer point contributors at obsolete topology or package identity assumptions

#### M7: Workflow And Operator Ergonomics

Scope boundaries:

- includes the remaining Theme E work after `M3` and `M6`
- covers root workflow wrappers, command exposure, operator command reachability, and local dev ergonomics
- excludes architecture changes that belong to `M3` and contract authority changes that belong to `M4`

Assigned themes and finding clusters:

- Theme E
- `#854`, `#855`, `#856`, `#858`
- docs/discoverability dependency: `#857`

Dependency justification:

- depends on `M3` because command exposure and workflow polish should wrap stable activation surfaces
- partially depends on `M6` because public workflow documentation and discoverability should settle after governance alignment

Sequencing notes:

1. expose implemented commands through the real console surface first
2. unify root verification/workflow entry points second
3. replace brittle dev-process coupling third
4. final operator/playbook polish last

Risk considerations:

- medium risk
- mostly workflow-facing, but still touches public command exposure and operator affordances
- can run with more parallelism than `M3` or `M4` once dependencies are satisfied

Expected outputs and exit criteria:

- root workflow and operator tooling entry points are coherent and reachable
- implemented operator/scaffolding capabilities are exposed through the public CLI surface
- local development and verification affordances match the intended monorepo workflow model

### M3 Implementation Backlog

The `M3` execution backlog stays inside Theme A and is organized as one bootstrap slice plus three dependency-ordered execution tracks.

#### M3 Bootstrap

Purpose:

- freeze the execution boundary for `M3`
- prevent Theme B, C, D, or E work from leaking into the architectural-base milestone
- define the dependency gates and completion conditions for all downstream tracks

Assigned finding clusters:

- topology truth cluster: `#824`, `#825`, `#848`, `#850`, `#857`
- orchestration boundary cluster: `#823`, `#826`, `#827`, `#828`, `#829`, `#830`, `#831`
- activation/discoverability cluster: `#854`, `#858`

Bootstrap issues to track:

- milestone dependency gate
- implementation split into three ordered tracks
- explicit non-goals for contract redesign, test lock-in, and final docs authority sync

Dependency role:

- bootstrap must complete first because it is the execution contract for the rest of `M3`

#### Track 1: Topology Truth And Composition-Root Inventory

Purpose:

- reconcile the real package/layer surface with the intended framework topology
- explicitly name what is and is not a package-layer concern before deeper refactors

Assigned finding clusters:

- `#824`, `#825`
- architecture/discoverability overlap needed to support execution: `#848`, `#850`, `#857`

Dependency-ordered task groups:

1. establish the canonical package roster and layer membership table
2. resolve the `admin` workspace versus package-graph distinction
3. normalize cross-package topology assumptions used by kernels and manifests
4. update package discoverability entry points only as needed to support the corrected topology

Cross-package coordination notes:

- touches root `composer.json`, package manifests, `CLAUDE.md`, `README.md`, and any package-level discoverability surfaces
- should produce stable inputs for later provider/bootstrap and command-activation work

Risk and rollback considerations:

- medium-to-high risk because a mistaken topology normalization can mis-sequence every later remediation milestone
- keep topology corrections reviewable as small commits and avoid bundling runtime contract changes into this track

Track exit criteria:

- package and workspace identities used by the framework are unambiguous
- the execution team can name the intended layer/package topology without relying on stale docs or hidden assumptions

#### Track 2: Kernel, Provider, And Upward-Seam Recovery

Purpose:

- move cross-layer orchestration back into approved composition roots
- eliminate hidden provider/bootstrap seams that bypass the declared architecture

Assigned finding clusters:

- `#823`, `#826`, `#827`, `#828`, `#829`, `#830`, `#831`

Dependency-ordered task groups:

1. contain extracted bootstrap helpers inside the approved kernel exemption boundary
2. reduce synchronous cross-provider and event-listener orchestration outside named roots
3. remove lower-layer backreferences into interface-layer runtime surfaces
4. collapse rebuilt access/runtime wiring back behind the corrected composition roots

Cross-package coordination notes:

- primary write surface spans `packages/foundation`, `packages/api`, `packages/user`, `packages/testing`, and `packages/admin-surface`
- this track must not finalize new public contracts; it should restore architectural placement first, then hand off contract shape decisions to `M4`

Risk and rollback considerations:

- highest-risk execution track in `M3`
- use narrow refactor slices with runnable checkpoints because kernel/bootstrap changes can strand the whole repo if merged too broadly
- if a slice destabilizes bootstrapping, revert only that seam and keep the topology truth work intact

Track exit criteria:

- cross-layer orchestration is contained to approved roots
- lower-layer packages no longer reach upward through hidden runtime seams
- admin-surface and testing no longer rely on invalid higher-layer wiring paths to function

#### Track 3: Declarative Activation And Discoverability Recovery

Purpose:

- make command, route, and package activation surfaces declarative and reachable
- align the repo's discoverability and CLI activation model with the corrected architectural base

Assigned finding clusters:

- `#854`, `#858`
- discoverability support overlap: `#857`

Dependency-ordered task groups:

1. make active command/route/provider surfaces discoverable from the intended manifest model
2. expose implemented CLI/operator/scaffolding commands through the real console surface
3. align package-local discoverability surfaces with the corrected activation model

Cross-package coordination notes:

- touches `packages/foundation` discovery/bootstrap code, `packages/cli`, package manifests, and package-local README surfaces
- should follow Track 1 topology corrections and run after the critical boot/orchestration seams in Track 2 are stable enough to expose declaratively

Risk and rollback considerations:

- medium risk
- command exposure can be rolled back independently if it destabilizes the console surface, but manifest/activation truth should not be mixed with unrelated workflow-polish changes

Track exit criteria:

- active developer-facing and operator-facing surfaces are reachable through the intended activation path
- package discoverability is consistent enough that `M7` can focus on workflow polish instead of activation truth

#### Cross-Package Coordination Notes

Shared coordination rules for `M3`:

1. `packages/foundation` is the highest-conflict surface; kernel/bootstrap edits should be serialized.
2. `packages/admin-surface`, `packages/api`, `packages/user`, and `packages/testing` should adjust to the corrected architecture, not define new public authority models during `M3`.
3. `CLAUDE.md`, `README.md`, and package-local discoverability surfaces may be updated only to reflect the corrected architectural base needed for execution; broader authority sync belongs to `M6`.
4. Any change that starts shaping public extension contracts must be deferred into `M4`.

#### M3 Risk And Rollback

Global M3 risks:

- bootstrapping regressions in foundation can stall every other remediation stream
- partial activation-model changes can leave commands or routes half-declarative and harder to debug
- premature contract cleanup can contaminate the milestone boundary and make rollback harder

Rollback strategy:

1. keep Track 1 commits separable from Track 2 runtime refactors
2. keep Track 2 kernel/bootstrap slices small enough to revert independently
3. keep Track 3 command-exposure and manifest changes isolated from root-workflow polish

#### M3 Exit Criteria

`M3` is complete when:

- topology truth, package identity, and composition-root containment are corrected for Theme A surfaces
- hidden upward seams and invalid orchestration paths covered by `#823` through `#831` are removed or relocated into approved roots
- declarative activation and command reachability are stable enough for downstream remediation to rely on them
- no unresolved `M3` work requires redefining public contracts; that handoff belongs to `M4`

### M4 Implementation Backlog

The `M4` execution backlog stays inside Theme B and is organized as one bootstrap slice plus three dependency-ordered execution tracks.

#### M4 Bootstrap

Purpose:

- freeze the execution boundary for `M4`
- prevent `M5` verification lock-in and `M6` governance sync from leaking into the public-surface unification milestone
- define authority-decision gates and handoff conditions for downstream testing and docs work

Assigned finding clusters:

- foundation authority cluster: `#833`, `#838`, `#849`
- API/entity-system public surface cluster: `#832`, `#834`, `#835`, `#837`, `#841`
- admin-surface authority cluster: `#836`, `#839`, `#840`, `#851`

Bootstrap issues to track:

- milestone authority-decision gate
- implementation split into three ordered tracks
- explicit non-goals for new contract-verification coverage and broad governance cleanup

Dependency role:

- bootstrap must complete first because it defines the authoritative-surface boundary M5 and M6 will consume

#### Track 1: Foundation Provider Authority Recovery

Purpose:

- establish one authoritative public contract for framework service-provider extension seams
- remove concrete-type leakage from the foundation extension surface without widening scope into verification work

Assigned finding clusters:

- `#833`, `#838`
- authority-model overlap needed for execution: `#849`

Dependency-ordered task groups:

1. decide the authoritative public type for service-provider extension hooks
2. align interface, base class, and kernel-consumed hook surface to that authority
3. remove concrete `EntityTypeManager` leakage from extension-facing provider APIs
4. record the authority outcome only to the extent required for downstream tracks and later governance sync

Cross-package coordination notes:

- touches `packages/foundation` first, with downstream effects on provider consumers and docs references
- this track is the least parallelizable part of `M4` and should settle before other tracks finalize their public seams

Risk and rollback considerations:

- highest-risk contract track in `M4`
- a mistaken authority choice will propagate into admin-surface, testing, and governance work
- keep interface/base-class/kernel alignment changes in small, reviewable slices so the prior extension surface can be restored if necessary

Track exit criteria:

- the framework has one authoritative provider extension surface
- no remaining Theme B work depends on ambiguous foundation hook authority

#### Track 2: API And Entity-System Public Surface Alignment

Purpose:

- unify the API and entity-system public surfaces around the corrected architectural base from `M3`
- resolve spec/runtime/interface mismatches without adding downstream regression tests yet

Assigned finding clusters:

- `#832`, `#834`, `#835`, `#837`, `#841`

Dependency-ordered task groups:

1. resolve the `AccessChecker` public-location mismatch and API route-provider surface truth
2. align entity-system public interfaces with the intended runtime surface
3. fix paired-nullable access-context semantics so the authoritative API surface is explicit
4. ensure the resulting API/entity-system public surfaces are ready for `M5` verification lock-in

Cross-package coordination notes:

- primarily spans `packages/api`, `packages/entity`, and specs/contract references that document those surfaces
- should run after Track 1 establishes the foundation authority model, but can then proceed mostly independently of the admin-surface track

Risk and rollback considerations:

- medium risk
- avoid bundling docs/governance cleanup or negative-path tests into this track; keep it focused on public-surface truth
- if one public seam proves unstable, revert that slice without rolling back unrelated entity-system or API surface fixes

Track exit criteria:

- API and entity-system public seams no longer disagree across runtime, interface, and documented contract shape
- the `M5` verification phase can target these seams directly without additional authority decisions

#### Track 3: Admin-Surface Boundary Authority Recovery

Purpose:

- establish a single authoritative host-to-SPA public boundary for admin-surface after `M3` restored valid architecture placement
- align runtime payloads, shared contract package, and extension surface expectations without yet adding the tests that will lock them down

Assigned finding clusters:

- `#836`, `#839`, `#840`
- authority-model overlap: `#851`

Dependency-ordered task groups:

1. decide the authoritative admin-surface boundary artifact
2. align host extension surfaces to the correct abstraction level
3. align session payload shape across runtime and shared contract surface
4. align catalog payload shape across runtime and shared contract surface

Cross-package coordination notes:

- spans `packages/admin-surface`, `packages/admin`, and any shared contract surface used between them
- should begin only after Track 1 settles provider/authority patterns, but can then run in parallel with Track 2

Risk and rollback considerations:

- high risk because this track feeds directly into `M5` integration and contract verification
- keep session and catalog unification separable so a bad decision in one payload shape does not block the other
- do not mix governance wording or test assertions into this track; those belong to `M6` and `M5`

Track exit criteria:

- admin-surface has one named authoritative host-to-SPA boundary
- session and catalog public shapes are aligned across the chosen authority surface and runtime emitters

#### Cross-Package Coordination Notes

Shared coordination rules for `M4`:

1. foundation provider authority must settle before admin-surface and API/entity-system tracks finalize their own public surfaces.
2. `packages/foundation`, `packages/api`, `packages/entity`, `packages/admin-surface`, and `packages/admin` are the primary write surfaces; avoid mixing unrelated docs cleanup from `M6`.
3. Any new test lock-in must be deferred into `M5`, even if a corrected public surface makes the missing coverage obvious.
4. Any broad codified-context or subsystem authority sync must be deferred into `M6`; `M4` should only make the minimum authority declarations needed to finish the runtime/public contract alignment.

#### M4 Risk And Rollback

Global M4 risks:

- choosing the wrong authoritative surface and then propagating it into multiple packages
- blending runtime contract repair with verification or governance cleanup, making rollback harder
- admin-surface and foundation authority work colliding on the same extension concepts without a fixed ordering

Rollback strategy:

1. keep Track 1 foundation authority changes isolated from Track 2 and Track 3
2. keep API/entity-system slices independent from admin-surface payload-shape alignment
3. preserve the post-`M3` architectural base while reverting only the affected public-surface slice if an authority decision proves wrong

#### M4 Exit Criteria

`M4` is complete when:

- each Theme B seam has one authoritative public surface
- foundation provider hooks, API/entity-system contracts, and admin-surface boundaries no longer depend on unresolved authority decisions
- no remaining `M4` work requires new regression coverage to define the shape; that handoff belongs to `M5`
- `M5` and `M6` can proceed using stable, intentional public surfaces rather than inferred ones

### M5 Implementation Backlog

The `M5` execution backlog stays inside Theme C and is organized as one bootstrap slice plus four dependency-ordered verification tracks.

#### M5 Bootstrap

Purpose:

- freeze the execution boundary for `M5`
- prevent runtime contract redesign or governance cleanup from leaking into verification lock-in
- define the dependency gates between contract-verification, admin-surface integration, harness cleanup, and lower-layer test placement work

Assigned finding clusters:

- contract-verification cluster: `#843`, `#844`
- admin-surface verification cluster: `#842`, `#846`
- harness and placement cluster: `#845`, `#847`

Bootstrap issues to track:

- milestone dependency gate
- implementation split into four ordered tracks
- explicit non-goals for public-surface redesign, topology repair, and broad docs/governance sync

Dependency role:

- bootstrap must complete first because it fixes the verification scope after `M3` and `M4` handoff and prevents the milestone from redefining seams it is supposed to lock down

#### Track 1: Authoritative Seam Contract Verification

Purpose:

- add contract-verification coverage around the authoritative public seams established by `M4`
- ensure the first verification slice targets stable foundation and API contracts rather than inferred runtime behavior

Assigned finding clusters:

- `#843`, `#844`

Dependency-ordered task groups:

1. identify the corrected public seams from `M4` that now require direct contract-verification coverage
2. add foundation provider-surface verification through the authoritative hook contract rather than concrete implementation detail
3. add API negative-path and access-context verification for the corrected field-access and serializer seams
4. establish a stable contract-test pattern that later tracks can reuse without redefining seam ownership

Cross-package coordination notes:

- primarily spans `packages/foundation`, `packages/api`, and the repository test harness
- should consume the authority outcomes from `M4` without reopening them
- must keep assertions anchored to public seams so Track 3 can remove reflection-based tests cleanly

Risk and rollback considerations:

- medium risk because a mistaken test target can accidentally lock in the wrong public seam
- keep foundation and API contract-verification changes separable so one seam can be rolled back without invalidating the other
- do not mix runtime contract corrections into this track; if a seam is still unstable, the work must bounce back to the planning backlog rather than be fixed implicitly here

Track exit criteria:

- corrected foundation and API public seams have direct contract-verification coverage
- invalid partial access-context states and similar negative paths fail through stable public assertions rather than indirect behavior checks

#### Track 2: Admin-Surface Integration And Shared-Boundary Verification

Purpose:

- lock down the admin-surface host-to-SPA boundary after `M4` establishes its authoritative contract surface
- add root-level regression protection for the real route, session, and catalog integration seam

Assigned finding clusters:

- `#842`, `#846`

Dependency-ordered task groups:

1. anchor the shared admin-surface verification target to the authoritative boundary chosen in `M4`
2. add shared-boundary verification for session and catalog payload shape
3. add repository-level integration coverage for the `/admin/_surface/*` route and host wiring seam
4. confirm package-local tests and root integration tests complement each other instead of duplicating the same contract checks

Cross-package coordination notes:

- spans `packages/admin-surface`, `packages/admin`, backend host wiring, and repository-level integration tests
- should begin only after the admin-surface authority decision from `M4` is settled
- will likely share fixtures or helpers with Track 1, but must not import higher-layer helpers into lower-layer suites while doing so

Risk and rollback considerations:

- medium-to-high risk because this track exercises one of the broadest cross-package seams in the repo
- keep shared contract-shape verification separate from route/bootstrap integration coverage so failures can be isolated quickly
- if the root integration seam proves unstable, revert only the integration slice and preserve the narrower shared-boundary assertions

Track exit criteria:

- admin-surface session and catalog boundaries are verified against the authoritative contract surface
- the real `/admin/_surface/*` seam has repository-level regression protection
- package-local and root-level verification responsibilities are distinct and non-overlapping

#### Track 3: Foundation Harness And Stable-Seam Cleanup

Purpose:

- remove reflection-heavy and private-state-dependent tests from lower-layer foundation suites
- re-center foundation verification on stable public seams established earlier in the remediation plan

Assigned finding clusters:

- `#845`

Dependency-ordered task groups:

1. inventory reflection-based and private-state assertions that are still carrying architectural coverage
2. replace them with seam-level assertions that target stable kernel, provider, or bootstrap outcomes
3. ensure foundation harness helpers support those seams without exposing new private-state shortcuts
4. retire obsolete implementation-detail assertions once the replacement coverage is in place

Cross-package coordination notes:

- primarily local to `packages/foundation` and the shared test harness, with minimal downstream package writes expected
- should follow Track 1 so replacement seam-level tests already exist for the main foundation extension surfaces

Risk and rollback considerations:

- medium risk because over-aggressive cleanup can silently reduce coverage while appearing to simplify the harness
- land replacement seam assertions before deleting reflection-based tests
- if replacement tests prove too weak, revert the cleanup slice without disturbing other verification tracks

Track exit criteria:

- foundation suites verify behavior through stable public seams rather than reflection or private-state inspection
- the remaining foundation harness no longer depends on implementation-detail assertions for architectural confidence

#### Track 4: Lower-Layer Placement And Collaborator Isolation Cleanup

Purpose:

- eliminate lower-layer suites that import higher-layer collaborators or bypass the intended seam/factory surfaces
- finish the placement cleanup after topology and public authority decisions are stable

Assigned finding clusters:

- `#847`

Dependency-ordered task groups:

1. inventory lower-layer suites that still depend on higher-layer collaborators or direct implementation shortcuts
2. re-home those tests to the appropriate layer or replace collaborators with lower-layer seam/factory usage
3. normalize helper usage so lower-layer suites do not reacquire higher-layer dependencies through shared fixtures
4. close the milestone by checking that placement cleanup did not erode the contract and integration coverage added in Tracks 1 and 2

Cross-package coordination notes:

- spans `packages/foundation` first, but may touch shared harness helpers that influence other package suites
- should run last because earlier tracks define the stable seams and helper patterns this cleanup must adopt

Risk and rollback considerations:

- lower technical risk than the earlier tracks, but high regression risk if suites are moved or rewritten without preserving intent
- keep placement moves and seam-substitution changes in separate slices so a bad relocation does not force rollback of useful collaborator cleanup

Track exit criteria:

- lower-layer tests no longer import higher-layer collaborators or bypass intended seam/factory surfaces
- layer-appropriate test placement is consistent with the remediated topology from `M3`

#### Cross-Package Coordination Notes

Shared coordination rules for `M5`:

1. `M4` authority outcomes are inputs, not active design space; if a seam is still undefined, stop and escalate rather than improvising a contract inside tests.
2. `packages/foundation`, `packages/api`, `packages/admin-surface`, and `packages/admin` are the primary write surfaces; keep docs/governance edits out of `M5` except for minimal execution-support notes.
3. Contract-verification patterns established in Track 1 should be reused by the admin-surface work rather than reinvented per subsystem.
4. Lower-layer placement cleanup must not precede the stable seam and helper patterns created by the earlier tracks.

#### M5 Risk And Rollback

Global M5 risks:

- locking in tests against a seam that still reflects pre-`M4` behavior
- replacing reflection-heavy coverage with weaker seam-level assertions that miss the original invariant
- coupling admin-surface integration coverage too tightly to package-local fixtures or unstable helpers

Rollback strategy:

1. keep Track 1 contract-verification slices independent across foundation and API
2. keep Track 2 shared-boundary verification separate from root integration coverage
3. land Track 3 replacement assertions before deleting the old harness behavior checks
4. keep Track 4 placement moves isolated from semantic assertion changes wherever possible

#### M5 Exit Criteria

`M5` is complete when:

- Theme C seams are protected by contract, integration, and negative-path coverage at the right boundary level
- foundation suites no longer rely on reflection, private state, or higher-layer collaborators for architectural confidence
- admin-surface has both authoritative shared-boundary verification and root integration protection for the real host/route seam
- later milestones can assume regressions in the remediated architecture will fail close to the affected seam

### M6 Implementation Backlog

The `M6` execution backlog stays inside Theme D and the approved discoverability overlap, and is organized as one bootstrap slice plus four dependency-ordered governance tracks.

#### M6 Bootstrap

Purpose:

- freeze the execution boundary for `M6`
- prevent workflow-polish work from `M7` or runtime/public-surface redesign from `M3` and `M4` from leaking into governance alignment
- define the dependency gates between authority sync, architecture/codified-context sync, roadmap governance sync, and onboarding/discoverability cleanup

Assigned finding clusters:

- authority-model cluster: `#849`, `#851`
- canonical architecture cluster: `#848`, `#850`
- workflow governance cluster: `#852`
- onboarding and discoverability cluster: `#853`, `#857`

Bootstrap issues to track:

- milestone dependency gate
- implementation split into four ordered tracks
- explicit non-goals for runtime architecture repair, contract redesign, new verification lock-in, and final workflow ergonomics polish

Dependency role:

- bootstrap must complete first because it fixes the post-`M3`/`M4` authority assumptions that all later documentation and governance artifacts must follow

#### Track 1: Subsystem Authority And Contract-Spec Sync

Purpose:

- reflect the authority decisions made in `M4` back into subsystem specs and shared contract references
- eliminate contradictory "authoritative source" claims across subsystem documentation without reopening runtime contract design

Assigned finding clusters:

- `#849`, `#851`

Dependency-ordered task groups:

1. identify each subsystem boundary whose authority model changed in `M4`
2. update subsystem specs to name one authoritative public surface per seam
3. remove contradictory or split authority claims between shared contract packages, subsystem specs, and local consumer docs
4. leave execution-ready guidance that later workflow docs can reference without restating subsystem authority

Cross-package coordination notes:

- spans `docs/specs/**`, `packages/admin-surface/contract`, and any package-local contract references that currently claim authority
- should begin only after `M4` authority outcomes are stable and accepted
- must avoid introducing new runtime decisions; this track documents authority, it does not invent it

Risk and rollback considerations:

- medium risk because wording mistakes here can silently reintroduce ambiguity after `M4` resolved it
- keep subsystem authority updates grouped by seam so an incorrect admin-surface or provider authority statement can be reverted independently
- do not mix broader onboarding or milestone-table changes into this track

Track exit criteria:

- each remediated subsystem seam has one named authoritative source in docs
- subsystem specs and contract-package references no longer contradict each other about public-surface ownership

#### Track 2: Canonical Architecture And Codified-Context Sync

Purpose:

- bring `CLAUDE.md`, the canonical architecture narrative, and the framework package roster back into sync with the remediated topology from `M3`
- ensure contributors can understand the real subsystem and package surface without inferring missing pieces from code

Assigned finding clusters:

- `#848`, `#850`
- discoverability overlap needed for execution: `#857`

Dependency-ordered task groups:

1. update the canonical package roster and layer/subsystem topology in codified-context docs
2. reconcile orchestration and ownership guidance with the actual active subsystem surface
3. align package discoverability guidance with the corrected package and workspace model
4. confirm downstream docs can reference the canonical architecture table without restating obsolete topology assumptions

Cross-package coordination notes:

- primarily spans `CLAUDE.md`, `README.md`, package-level README surfaces, and architecture-facing docs under `docs/specs/**`
- should follow Track 1 where subsystem authority wording depends on the named public source, but can then proceed mostly independently of workflow-governance updates

Risk and rollback considerations:

- medium risk because `CLAUDE.md` is the repo's highest-leverage context surface and stale edits there can mis-sequence future work
- keep topology tables, orchestration guidance, and discoverability guidance in separable slices so one bad update does not force a full doc rollback
- do not drift into M7 workflow ergonomics while updating discoverability language

Track exit criteria:

- canonical architecture docs reflect the remediated package topology and subsystem surface
- contributors no longer need to infer active package ownership or architecture shape from repository spelunking

#### Track 3: Milestone And Workflow Governance Sync

Purpose:

- align roadmap, milestone, and issue-workflow documentation with the actual remediation program now running in GitHub
- restore governance docs as accurate operating context rather than historical residue

Assigned finding clusters:

- `#852`

Dependency-ordered task groups:

1. update milestone tables and workflow references to the active remediation roadmap
2. align issue-workflow expectations with the audit-to-remediation operating model established in `M1` and `M2`
3. record any governance rules needed for later milestones without duplicating subsystem-specific authority content
4. leave a stable governance surface that `M7` can reference without re-explaining the roadmap model

Cross-package coordination notes:

- primarily local to `docs/specs/workflow.md` and adjacent governance documents
- can proceed once the remediation roadmap is accepted, but should still follow the core architecture and authority sync so the workflow docs reference the correct milestone sequence

Risk and rollback considerations:

- lower technical risk than the other tracks, but high coordination risk if the written roadmap diverges from GitHub state
- keep milestone-table updates and workflow-expectation updates in distinct slices so they can be reverted independently if the roadmap changes

Track exit criteria:

- workflow and milestone docs describe the actual remediation roadmap and operating expectations
- contributors can use governance docs without cross-checking GitHub to discover the basic milestone model

#### Track 4: Onboarding And Package Discoverability Cleanup

Purpose:

- bring onboarding guidance and package-level discoverability surfaces into alignment with the corrected framework identity and topology
- remove obsolete package identity and entry-point guidance that would mislead new contributors after `M3` and `M4`

Assigned finding clusters:

- `#853`, `#857`

Dependency-ordered task groups:

1. correct root onboarding identity and create-project guidance
2. align package-level README and discoverability surfaces with the corrected package roster and activation model
3. normalize contributor-facing entry points so onboarding guidance matches the canonical architecture and governance docs
4. confirm the resulting discoverability guidance does not duplicate workflow-polish work reserved for `M7`

Cross-package coordination notes:

- spans `README.md`, package-local READMEs, and contributor-facing discoverability entry points across the monorepo
- should run after Track 2 establishes the canonical topology wording and after Track 3 settles milestone/workflow references that onboarding may point to

Risk and rollback considerations:

- medium risk because broad README churn can easily reintroduce contradictory terminology across the repo
- keep root onboarding fixes separate from package-local discoverability edits so a bad package README batch does not block correction of the root identity story
- avoid slipping into operator or workflow ergonomics content that belongs in `M7`

Track exit criteria:

- onboarding guidance reflects the real framework identity and package topology
- package discoverability surfaces no longer point contributors at obsolete package names, missing packages, or outdated activation assumptions

#### Cross-Package Coordination Notes

Shared coordination rules for `M6`:

1. `M3` and `M4` outputs are authoritative inputs; `M6` documents them and must not reopen those decisions.
2. `CLAUDE.md`, `README.md`, `docs/specs/**`, and package-local README surfaces are the main write surfaces; keep test or runtime changes out of the milestone.
3. Authority wording should be settled before canonical architecture sync, and both should precede onboarding cleanup so contributors see one coherent story.
4. Any workflow ergonomics or operator playbook polish beyond documentation truthfulness belongs to `M7`, not `M6`.

#### M6 Risk And Rollback

Global M6 risks:

- reintroducing ambiguity by documenting multiple "authoritative" sources for the same seam
- updating onboarding or workflow docs before canonical architecture and authority wording settle
- blending governance truth with workflow-polish decisions that still depend on `M7`

Rollback strategy:

1. keep Track 1 subsystem authority updates independent from Track 2 architecture-table changes
2. keep Track 3 milestone/workflow updates separate from onboarding language updates in Track 4
3. revert package-local discoverability edits independently of root docs when necessary

#### M6 Exit Criteria

`M6` is complete when:

- canonical docs reflect the remediated architecture, authority model, and package roster
- workflow and milestone docs describe the actual remediation roadmap and operating expectations
- onboarding and package discoverability surfaces no longer point contributors at obsolete topology or package identity assumptions
- `M7` can focus on workflow and operator ergonomics rather than cleaning up documentation truth

### M7 Implementation Backlog

The `M7` execution backlog stays inside Theme E and the approved discoverability dependency from `M6`, and is organized as one bootstrap slice plus four dependency-ordered workflow tracks.

#### M7 Bootstrap

Purpose:

- freeze the execution boundary for `M7`
- prevent unresolved architecture, contract, or governance truth work from leaking into the final workflow-ergonomics milestone
- define the dependency gates between command exposure, root workflow entry points, dev-process coupling cleanup, and operator/playbook polish

Assigned finding clusters:

- command exposure cluster: `#854`, `#858`
- root workflow cluster: `#856`
- dev-process coupling cluster: `#855`
- discoverability dependency cluster: `#857`

Bootstrap issues to track:

- milestone dependency gate
- implementation split into four ordered tracks
- explicit non-goals for activation-model redesign, public-contract changes, and canonical-doc truth work already assigned to `M3`, `M4`, and `M6`

Dependency role:

- bootstrap must complete first because it fixes the post-`M3` and post-`M6` assumptions that the public CLI and workflow wrappers are allowed to depend on

#### Track 1: Public Command Exposure And Operator Reachability

Purpose:

- expose implemented operator and scaffolding capabilities through the public console surface built on the corrected activation model from `M3`
- make command reachability coherent before higher-level workflow wrappers depend on it

Assigned finding clusters:

- `#854`, `#858`

Dependency-ordered task groups:

1. inventory implemented commands and operator/scaffolding capabilities that should be reachable through the public CLI surface
2. align command registration and discovery with the stable activation path established in `M3`
3. expose missing operator and scaffolding commands through the real console surface
4. verify that later workflow entry points can target the public CLI rather than hidden or manual registration seams

Cross-package coordination notes:

- primarily spans `packages/foundation` discovery/bootstrap code, `packages/cli`, command providers, and root workflow entry points that call into them
- must consume `M3` activation outcomes without reopening activation-model truth
- should finish before Track 2 so root workflow wrappers only target commands that are publicly reachable

Risk and rollback considerations:

- medium risk because command exposure changes touch developer and operator entry points directly
- keep command-surface exposure changes separate from root workflow wrapper updates so a bad command registration slice can be reverted without rolling back workflow cleanup
- do not mix discoverability/doc truth edits into this track beyond the minimum needed to exercise the public CLI

Track exit criteria:

- implemented operator and scaffolding commands are reachable through the intended public console surface
- no remaining `M7` workflow work depends on hidden command registration paths

#### Track 2: Root Workflow Entry-Point Unification

Purpose:

- unify the monorepo's root verification and workflow entry points around the public command and script surfaces that remain after `M3` and Track 1
- reduce fragmentation in how contributors invoke common development and verification flows

Assigned finding clusters:

- `#856`
- execution dependency: `#857`

Dependency-ordered task groups:

1. inventory the intended root workflow entry points for common verification and developer flows
2. align those entry points with the publicly reachable command and script surfaces from Track 1
3. remove or de-emphasize redundant root workflow paths that bypass the intended monorepo workflow model
4. leave a stable root workflow surface that the operator-polish track can document and refine without redesigning it

Cross-package coordination notes:

- spans root `composer.json`, task or script entry points, and any contributor-facing workflow wrappers referenced by onboarding/discoverability surfaces
- should follow Track 1 and consume the documentation truth from `M6` rather than redefining contributor guidance here

Risk and rollback considerations:

- medium risk because changes at the root workflow surface can strand everyday contributor commands if over-consolidated
- keep new canonical entry points separate from removals or de-emphasis of legacy ones so rollback can preserve continuity
- avoid slipping into milestone/governance wording changes that belong to `M6`

Track exit criteria:

- root verification and workflow entry points are coherent, minimal, and aligned with the intended monorepo workflow model
- contributors no longer need multiple competing root-level paths for the same common flow

#### Track 3: Dev-Process Coupling And Local Ergonomics Cleanup

Purpose:

- remove brittle local development couplings, especially long-running process chains that currently rely on fragile shell composition
- make the canonical local-development path resilient enough to serve as the basis for operator and contributor workflows

Assigned finding clusters:

- `#855`

Dependency-ordered task groups:

1. inventory brittle process-coupling entry points in the current local-development workflow
2. replace the highest-risk shell coupling with clearer, restartable workflow surfaces
3. ensure the resulting local-dev path composes cleanly with the unified root workflow entry points from Track 2
4. leave diagnostics or helper affordances in a form that Track 4 can polish without redesigning the underlying process model

Cross-package coordination notes:

- primarily spans root Composer or task scripts and any local-development wrappers that orchestrate long-running processes
- should follow Track 2 so local-dev ergonomics are cleaned up around a stable root workflow model

Risk and rollback considerations:

- medium risk because local-dev workflow regressions are immediately user-visible and can block day-to-day repo use
- keep process-orchestration replacement separate from wrapper naming or documentation polish so a bad orchestration change can be reverted independently
- avoid changing runtime activation or command authority in the name of convenience

Track exit criteria:

- the canonical local-development workflow no longer depends on brittle shell one-liners for core process composition
- contributors can start and reason about the local workflow through explicit, stable entry points

#### Track 4: Operator And Playbook Polish

Purpose:

- finish the workflow surface with coherent operator-facing affordances, diagnostics entry points, and playbook-level guidance
- ensure the final public workflow model is usable without reintroducing hidden seams or duplicated onboarding truth

Assigned finding clusters:

- `#854`, `#858`
- discoverability dependency: `#857`

Dependency-ordered task groups:

1. inventory the operator-facing flows and diagnostics surfaces that remain after Tracks 1-3
2. align operator affordances and playbook guidance to the stable command and workflow surfaces now in place
3. prune or de-emphasize leftover operator paths that still assume hidden commands or obsolete discoverability guidance
4. close the milestone by confirming that operator ergonomics now sit on top of stable public entry points rather than special-case knowledge

Cross-package coordination notes:

- spans public CLI surfaces, operator-facing docs or playbooks, and any diagnostic entry points exposed through the root workflow model
- should run last because it depends on stable command exposure, root workflow unification, and local-dev ergonomics from the earlier tracks

Risk and rollback considerations:

- lower technical risk than the earlier tracks, but high coherence risk if guidance diverges from the real public workflow surface
- keep operator-facing doc or playbook changes separate from command-surface adjustments so a guidance correction does not force rollback of working tooling

Track exit criteria:

- operator-facing affordances and playbook guidance align to stable public command and workflow surfaces
- no remaining workflow guidance depends on hidden commands, obsolete discoverability assumptions, or brittle special-case knowledge

#### Cross-Package Coordination Notes

Shared coordination rules for `M7`:

1. `M3` activation truth and `M6` documentation truth are authoritative inputs; `M7` refines workflow ergonomics on top of them.
2. Root `composer.json`, `packages/cli`, `packages/foundation`, task or script entry points, and operator-facing workflow surfaces are the main write surfaces.
3. Command exposure must settle before root workflow unification, and both must settle before dev-process cleanup and final operator polish.
4. Any doc changes in `M7` should be workflow-facing polish only; canonical architecture, authority, and roadmap truth stay in `M6`.

#### M7 Risk And Rollback

Global M7 risks:

- wrapping unstable or partially exposed command surfaces in new root workflows
- reducing entry-point sprawl in a way that strands existing contributor habits without a clean replacement
- letting workflow polish drift back into activation truth or governance truth work

Rollback strategy:

1. keep Track 1 command-surface exposure changes independent from Track 2 root workflow wrapper changes
2. keep Track 3 process-orchestration cleanup isolated from naming and guidance polish
3. keep Track 4 operator/playbook changes separate from underlying command or workflow mechanics

#### M7 Exit Criteria

`M7` is complete when:

- root workflow and operator tooling entry points are coherent, reachable, and based on stable public surfaces
- implemented operator and scaffolding capabilities are exposed through the intended public CLI
- local development and verification affordances no longer depend on brittle or hidden process coupling
- the remediation roadmap ends with one usable workflow model instead of parallel, contradictory entry points
