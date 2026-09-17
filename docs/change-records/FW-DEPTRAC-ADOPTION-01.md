# FW-DEPTRAC-ADOPTION-01 — canonical PHP architecture analysis

- Parent: `d1e63f9de2d1300c366cf18b0f1eecd369379c1e`
- Forge mirror: `waaseyaa/framework#3075`
- First scoped consumer: `FW-ADMIN-SURFACE-CONVERGENCE-01`
- Authority: Deptrac adoption and measured architecture-gate migration only;
  no removal or weakening of existing gates without proven parity and reviewed
  migration evidence

## Outcome

Adopt a compatible pinned Deptrac 4.x development dependency as Framework's
canonical PHP architecture-dependency analyser. Move dependency extraction,
layer assignment, uncovered-dependency reporting, boundary enforcement, and
architecture visualization to the maintained upstream tool while preserving
the meaning and rationale of every current Waaseyaa rule, baseline, exemption,
and seeded control.

## Migration boundary

Issue #3074 supplies the first bounded proving ground inside
`waaseyaa/admin-surface`. That slice will classify every production PHP token,
fail on uncovered dependencies, enforce the package's reviewed internal
boundaries, and produce a Mermaid dependency view. It will not create another
permanent hand-written PHP dependency scanner.

Repository-wide migration remains governed by issue #3075. The following rules
must each receive an explicit Deptrac replacement, maintained-tool replacement,
irreducible Waaseyaa manifest-policy disposition, or evidence-backed retirement
before overlapping code is removed:

- PL001 package name and directory agreement
- PL002 package layer classification
- PL003 required dependency classification
- PL004 upward Composer edge
- PL005 PHP import edge
- PL006 same-layer dependency cycles
- PL007 undeclared package dependency
- PL008 fully qualified and string-based class references
- PL010 package test dependency declaration
- kernel and HTTP-substrate exemptions
- cycle, undeclared-edge, string-reference, and test-edge baselines

`bin/check-package-layers` remains required throughout the #3074 slice. It may
be reduced or removed only in a later #3075 candidate after equivalent or
stronger detection and exception scoping are independently reviewed.

## Planned candidates

| Candidate | Scope | Status |
| --- | --- | --- |
| A | Pinned dependency, `admin-surface` model, exhaustive classification, uncovered failure, seeded controls, deterministic output, and Mermaid view | Paused before design |
| B and later | Seven-layer Framework model, PL rule parity, canonical CI/preflight integration, and evidence-backed retirement of overlap | Not started; owned by #3075 |

## Non-goals

Deptrac does not replace PHP-to-TypeScript wire conformance, public API lifecycle
declarations, PHPStan dead-code analysis, Composer policies unrelated to
architecture, provider/container reachability, HTTP qualification, split-package
acceptance, or rendered browser checks.

## Evidence ledger

The record was initialized before any Composer manifest, lockfile, Deptrac
configuration, preflight, or CI implementation change. Exact candidate SHAs,
commands, reports, runtime/cache observations, parity findings, exceptions, and
independent review will be appended when Candidate A begins.

