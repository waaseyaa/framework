# FW-DEPTRAC-ADOPTION-01 — canonical PHP architecture analysis

- Initial parent: `d1e63f9de2d1300c366cf18b0f1eecd369379c1e`
- Current base: `a682a17e03d3ecee565798a87551210a9be90174`
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
| A | Pinned dependency, `admin-surface` model, exhaustive classification, uncovered failure, seeded controls, deterministic output, and Mermaid view | Implemented locally; exact-head hosted evidence pending |
| B and later | Seven-layer Framework model, PL rule parity, canonical CI/preflight integration, and evidence-backed retirement of overlap | Not started; owned by #3075 |

## Non-goals

Deptrac does not replace PHP-to-TypeScript wire conformance, public API lifecycle
declarations, PHPStan dead-code analysis, Composer policies unrelated to
architecture, provider/container reachability, HTTP qualification, split-package
acceptance, or rendered browser checks.

## Candidate A design and test plan

The package charter defines three internal layers: Boundary contract,
Application adapters, and Delivery and composition. The Deptrac configuration
will enumerate every current production class-like token into exactly one of
those layers and classify each referenced first-party package and Symfony as an
external layer. Allowed dependencies point only inward between internal layers;
external edges are allowed only where the current package contract requires
them. No skipped violation or baseline is planned for this slice.

The first Deptrac run rejected the charter's initial placement of
`AbstractAdminSurfaceHost` in Application adapters because the Boundary
contract's public `AdminSurfaceHostFactoryInterface` returns that base class.
The base is therefore classified with the public Boundary contract. This keeps
the factory dependency inward without an exception and reflects the real
custom-host lifecycle.

The default gate will run Deptrac with uncovered reporting enabled and
`--fail-on-uncovered`. A fixture-driven Architecture test will invoke the same
configuration against three controls: Application to Boundary passes, Boundary
to Application fails, and a dependency on an unclassified Admin Surface token
fails. The existing `check-package-layers` and its self-tests remain active.

Focused implementation evidence:

- `vendor/bin/phpunit tests/Architecture/AdminSurfaceDeptracGateTest.php`
- `composer check-admin-surface-deptrac`
- Deptrac JSON output for machine-readable inspection
- Deptrac `mermaidjs` output compared with the committed package view
- Composer policy and preflight-parity tests affected by manifest/gate wiring

The dependency installation is the one heavy shared-host operation for this
work package. Final qualification will broaden only because the root lockfile,
Composer scripts, preflight roster, and hosted gate wiring change.

On the first local run Deptrac created a zero-byte `.deptrac.cache` despite the
gate's `--no-cache` option. One exact-path PowerShell removal attempt was denied
by the execution policy. No alternate deletion command was attempted. The
root-only cache filename is now explicitly ignored; the committed Mermaid file
remains the maintained evidence artifact.

## Evidence ledger

The record was initialized before any Composer manifest, lockfile, Deptrac
configuration, preflight, or CI implementation change.

| Candidate | Command or inspection | Result |
| --- | --- | --- |
| Dirty Candidate A, base `a682a17e0` | Initial `php vendor/bin/phpunit tests/Architecture/AdminSurfaceDeptracGateTest.php --no-coverage` | Expected red: 4 tests failed because the locked Deptrac binary was absent |
| Dirty Candidate A | Candidate-local Composer install with PHP 8.5 `fileinfo` and `zip` enabled | Installed exact `deptrac/deptrac` 4.7.2 and six required new packages; unrelated dependency advances from the first broad solver run were reverted through Composer constraints |
| Dirty Candidate A | Lock comparison with `HEAD:composer.lock` | Seven new package versions, zero existing version changes, zero existing path-reference changes |
| Dirty Candidate A | `composer check-admin-surface-deptrac` | Pass: 0 violations, 0 skipped, 0 uncovered, 427 allowed, 0 warnings, 0 errors |
| Dirty Candidate A | Deptrac `debug:unassigned` and three `debug:layer` calls | Pass: no unassigned tokens; 24 Boundary contract, 4 Application adapters, 2 Delivery and composition |
| Dirty Candidate A | `php vendor/bin/phpunit tests/Architecture/AdminSurfaceDeptracGateTest.php --no-coverage` | Pass: 5 tests, 27 assertions, including allowed, forbidden, uncovered, production, and Mermaid controls |
| Dirty Candidate A | Two consecutive `composer report-admin-surface-deptrac` runs | Byte-identical valid JSON: 0 violations, 0 uncovered, 427 allowed |
| Dirty Candidate A | `composer admin-surface-dependency-view` plus committed-view control | Pass: Deptrac-generated Mermaid view is current |
| Dirty Candidate A | `php bin/check-composer-policy`; `php bin/check-package-layers`; `php bin/changelog-fragments validate` | Pass; existing package-layer gate retained, with its five accepted PL006 warnings; 145 fragments valid |
| Dirty Candidate A | Focused `PreflightParityTest` methods affected by roster and CI wiring | Pass: 7 tests, 559 assertions |
| Dirty Candidate A | Full `PreflightParityTest.php` attempt on native Windows | Seven tests passed; the pre-existing synthetic shell test failed because Windows resolves `true`/`false` differently. No product or gate assertion failed, and no scope expansion was made. |
| Dirty Candidate A | PHP CS Fixer dry run for the new Architecture test; `composer validate --strict --no-check-publish`; `git diff --check` | Pass |
| Dirty Candidate A | Runtime reflection path check | Admin Surface provider resolves from this owned worktree; Deptrac resolves from this worktree's `vendor` |

The exact checkpoint SHA, hosted report artifact, independent review, runtime,
and cache measurements will be added when Candidate A is committed and
published. No `skip_violations` entry or architecture exception was added.
