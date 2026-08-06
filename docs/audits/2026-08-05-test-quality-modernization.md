# Test quality modernization audit

Date: 2026-08-05
Program issue: #2235

## Decision

Waaseyaa has a broad and unusually strong test estate, but it does not yet
measure whether that estate remains deterministic, effective, or economical.
Keep PHPUnit, Vitest, and Playwright. Modernize them in controlled work
packages rather than adopting a new test syntax or rewriting passing tests.

The immediate priorities are:

1. move PHP tests to a supported PHPUnit generation;
2. make test inventory and configuration drift machine-readable;
3. expose and remediate order, clock, random, sleep, and global-state coupling;
4. replace the currently empty coverage artifact with measured coverage and a
   changed-code ratchet;
5. rebuild `waaseyaa/testing` around real framework contracts, then adopt it;
6. pilot mutation testing at security and editorial boundaries;
7. apply equivalent coverage and determinism standards to the admin SPA.

Parallel execution comes after isolation work. It is not itself a quality
signal and would make existing shared-state defects harder to diagnose.

## Reproducible baseline

Run:

```bash
composer test:inventory
php bin/test-quality-inventory --format=markdown
```

The command enumerates only paths returned by the repository's guarded Git
entrypoint. It therefore excludes vendor trees, generated worktrees, ignored
artifacts, and local probes. Counts are lexical signals, not quality scores.

Baseline on the pre-audit main revision:

| Signal | Count |
|---|---:|
| PHP test files | 1,660 |
| PHP test methods | 11,756 |
| Explicit assertion calls | 27,843 |
| PHPUnit configuration files | 20 |
| Test files with coverage metadata | 1,473 |
| Files with randomness signals | 189 |
| Files with sleep signals | 7 |
| Files with conditional skip signals | 23 |
| Vitest files | 74 |
| Playwright files | 17 |
| External test consumers of `waaseyaa/testing` | 0 |

The inventory is intentionally conservative. A random call can be properly
seeded, a skip can be a valid environment guard, and a missing `CoversClass`
can be correct for a contract or architecture test. Each signal requires
classification before remediation.

## What is already strong

- The root suite separates unit, integration, architecture, contract,
  fresh-install, packaged-form, and application-consumer evidence.
- Release cuts require green CI on both main and the exact release commit.
- Architecture gates cover package layering, Symfony imports, public surfaces,
  Composer policy, stale test paths, dead code, distribution shape, generated
  admin assets, and security-sensitive recurring regressions.
- SQLite-backed integration tests exercise real storage and kernel seams.
- The admin SPA has Vitest, production-build Playwright, Chromium and Firefox
  smoke coverage, traces on retry, and artifact retention.
- The repository generally uses PHPUnit attributes and coverage metadata.

These are valuable assets. Modernization should improve their signal and
maintenance cost, not replace them for fashion.

## Findings

### F1. PHPUnit 10 is outside upstream support

The root and package manifests constrain PHPUnit to `^10.5`. Upstream's
[supported versions](https://phpunit.de/supported-versions.html) page marks
PHPUnit 10 as unsupported, while the framework already requires PHP 8.5.
This creates avoidable security, compatibility, and maintenance debt.

Remediation: upgrade in its own reversible work package. First run the suite
under PHPUnit 13, inventory deprecations and removed APIs, update all canonical
configurations mechanically, and prove packaged consumers. Do not combine the
upgrade with semantic test rewrites.

### F2. Coverage is configured but not produced or governed

CI installs PCOV, invokes all PHP suites with `--no-coverage`, then uploads
`build/logs/clover.xml`. The advertised Clover artifact is therefore normally
absent. No line, branch, package, or changed-code threshold is enforced. The
admin Vitest configuration declares V8 coverage but CI runs plain Vitest and
sets no thresholds.

Remediation: first publish honest PHP and admin coverage reports. Establish
baselines by package and critical boundary, then add a changed-code ratchet.
Avoid an arbitrary repository-wide 100 percent target; coverage identifies
untested code but does not prove useful assertions.

### F3. Test order and nondeterminism are not continuously challenged

PHPUnit runs in its default order. There is no random-order CI lane or logged
seed. The inventory finds 189 files with randomness signals, seven with sleep
signals, and 23 with conditional skip signals. Known defects such as #2231
and #2069 demonstrate real clock and static-environment coupling.

Remediation: classify the signals, introduce fixed clock and seeded-random
helpers, remove sleeps where observable synchronization is possible, and run
a reproducible random-order lane. PHPUnit documents both
[test ordering](https://docs.phpunit.de/en/13.0/textui.html#test-order) and
[random order seeds](https://docs.phpunit.de/en/13.0/configuration.html#the-executionorder-attribute).
Every CI failure must print the seed needed for local replay.

### F4. Twenty PHPUnit configurations can drift independently

The root, skeletons, packaged-form fixture, and 17 packages own PHPUnit XML.
The existing path gate prevents orphaned tests, but it does not enforce a
shared schema version or the project's required strictness, cache, color,
coverage-source, and failure-policy attributes.

Remediation: declare canonical invariants and add a mechanical conformance
gate. Keep package-specific suite paths, extensions, and bootstrap files where
they express real isolation. Do not copy the complete root configuration into
every package.

### F5. `waaseyaa/testing` is disconnected from the real suite

**Remediated after the baseline:** the package now supplies only focused typed
fixtures for mutable entity time, immutable principals, synthetic entity
types, owned temporary files and DBAL SQLite databases, and kernel-service
resolution. Access, Auth, Audit, API, Admin Surface, and MCP provide
representative consumers. The former array, no-op service-bag, event-recorder,
and raw-PDO surfaces are deprecated, and MCP keeps its transport fixture in its
Layer 6 test namespace. Access is the first mechanically isolated split-package
suite: CI clean-installs it, runs all package tests, and statically rejects an
undeclared sibling or another package's `autoload-dev` namespace.

At the audit baseline, no tracked test outside `packages/testing` consumed its
namespace. Its README
claims shared in-memory storage and integration bootstrap utilities that its
public surface does not provide. `CreatesApplication` is a no-op service bag,
`InteractsWithApi` returns request-shaped arrays instead of Symfony requests,
`InteractsWithAuth` stores arrays instead of framework principals, and
`RefreshDatabase` asks tests for raw PDO rather than the framework DBAL
boundary. Blind adoption would standardize unrealistic tests.

Remediation: treat the current package as an unadopted prototype. Add small,
typed helpers only where repeated real-suite fixtures prove demand: mutable
entity clock, immutable decision account/principal, isolated DBAL database and
temporary directory, kernel/request builder, and MCP request/auth fixtures.
Deprecate or repair helpers that bypass production contracts. Require at least
three representative adopters before calling a helper canonical.

### F6. Test taxonomy is mostly directory-based

Only a handful of files use PHPUnit `Small`, `Medium`, or `Large` attributes.
The suite has meaningful directories, but runtime and isolation expectations
are not machine-readable. This blocks safe parallelization and makes slow-test
budgets hard to enforce.

Remediation: define taxonomy by behavior, not merely directory:

- unit: no filesystem, network, process, sleep, or persistent database;
- contract: one public cross-package contract, implementation-independent;
- integration: real framework collaborators and isolated SQLite/filesystem;
- consumer/application: skeleton or external app compatibility;
- architecture: static repository invariant;
- E2E: browser plus production-shaped frontend/backend boundary.

Add size metadata incrementally when tests are touched or when runtime data
identifies outliers. Do not bulk-label without measurement.

### F7. Mutation effectiveness is unknown

The estate counts many assertions, but no mutation tool checks whether those
assertions detect plausible production defects. High-risk authorization,
workflow, MCP approval, sanitization, and revision code deserves stronger
evidence than line execution.

Remediation: pilot [Infection](https://infection.github.io/guide/) on two or
three bounded critical packages with MSI and covered-MSI reported first. Tune
or ignore equivalent mutants with documented reasons. Set blocking thresholds
only after two stable baseline runs.

### F8. Admin test execution has policy drift

The main CI uses Node 22 and both Chromium and Firefox. The path-filtered admin
workflow uses Node 25 and Chromium only for integration, while the package
requires Node `>=22.12.0`. Vitest coverage exists as a local script but is not
an artifact or gate. Playwright retries can hide first-attempt flakiness unless
retry outcomes are tracked.

Remediation: choose one pinned Node major across workflows, publish Vitest
coverage, define component/composable/adapter changed-code expectations, and
record first-attempt failures separately from final pass/fail. Keep the dual
browser main gate; add WebKit only if supported user or platform requirements
justify its cost.

## Delivery plan and exit measures

| Work package | Exit measure |
|---|---|
| Inventory and config conformance | Git-aware JSON/Markdown inventory in CI; all 20 configs pass declared invariants |
| PHPUnit upgrade | Supported major; full, packaged-form, and skeleton suites green; no unclassified deprecations |
| Determinism | Random-order seed logged and replayable; clock/random/sleep/skip ledger classified; no known order leak |
| Coverage | Real PHP and Vitest artifacts; package baselines; changed-code ratchet blocks regression |
| Shared fixtures | Real-contract clock/account/DBAL/request/MCP helpers; at least three critical-package adopters each where applicable |
| Mutation pilot | Stable MSI and covered-MSI for selected critical packages; evidence-based threshold decision |
| Admin quality | One Node policy; Vitest coverage gate; browser retry/flake scorecard |
| Parallelization | Enabled only for suites proven process-isolated; runtime improves without new flakes |

Tracked delivery issues:

- #2240 - supported PHPUnit major and configuration conformance;
- #2241 - replayable random order and deterministic fixtures;
- #2242 - honest PHP/admin coverage and changed-code ratchets;
- #2243 - real-contract `waaseyaa/testing` helpers and adopters;
- #2244 - bounded Infection mutation pilot;
- #2245 - admin Node, Vitest, and Playwright policy alignment.

## Explicit non-goals

- No Pest or Behat migration without a demonstrated capability gap.
- No mass rewrite of passing PHPUnit tests for syntax consistency.
- No mocking framework added merely to reduce typing.
- No repository-wide 100 percent coverage mandate.
- No parallel runner until shared global, filesystem, port, and database state
  is classified and controlled.
