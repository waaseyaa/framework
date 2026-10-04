# FW-SCHEMA-INSPECTION-01: bounded runtime schema inspection

Forge mirror: #3182. Base: `325c405a66741c4e2f7c21f0b56e2ebf46832d78`.
Owner: Codex, branch `codex/3182-schema-inspection` in its isolated worktree.

## Scope and design

Make each DBAL-backed `SchemaRequirement::assertAvailable()`
inspect table existence once and canonical column names once, regardless of
the number of required fields. The foundation factory wraps registered entity
guards in one read-only `DBALDatabase::inspectSchema()` operation, sharing one
catalog read and columns per table. Scope exit in `finally` discards snapshots,
restores nested scopes and makes escaped adapters live again. No persisted,
static, connection-lifetime or cross-request cache. All nine schema adapter
mutation methods refuse within inspection; callbacks must also avoid raw SQL DDL. An empty
field requirement still checks table existence without reading columns.
Other SchemaInterface implementations retain their existing per-field checks.
Use Doctrine's schema manager and the existing TableColumnNames helper;
no new generic caching infrastructure or dependency is needed.

Ownership: database-legacy schema guard, adapter and tests, plus the foundation
factory and scope-wiring test. Consumers include entity-storage; their constraints, activation fingerprint,
runtime ordering, authorization and schema mutation authority do not change.
The retained probe, changelog fragment, entity-system spec and generated test-only
SQLite/schema construction roster entries are also owned by this slice.

## Audit reconciliation

The committed coverage index marks database-legacy, entity-storage, entity and
audit not assessed, and foundation's route/lifecycle slice in progress.
This repair does not claim whole-package assessment or convergence.

- Completed groups assessment: preserve its observed base-table readiness
  contract, including uuid, langcode and _data on sql-blob tables. The hybrid
  relationship layout finding remains independent of column inspection.
- Completed admin-surface assessment: no controller, embed, host or wire
  contract changes. Its accepted residuals remain with their owners.
- May database usage inventory: its retirement recommendation is historical;
  accepted ADR-007 and current Composer metadata retain database-legacy as the
  supported DBAL persistence seam. This repair does not rename or retire it.
- August SQLite coupling inventory: portable Doctrine inspection is retained;
  existing writer-contention and snapshot-upgrade distinctions remain relevant
  to #3183, outside this slice.
- Existing reserved-column regressions #2163/#2171: compare canonical Column
  names through TableColumnNames, never quoted associative keys.
- Existing virtual-table filtering and schema guard error [S1-DB106] remain.

## Evidence plan

Before implementation, a failing schema-manager discriminator requires one
catalog enumeration and one column enumeration for a multi-field guard across
SQLite, PostgreSQL and MySQL platform doubles. Live SQLite controls cover
missing tables/columns, reserved names, newly dropped columns and unchanged DDL.
Run affected database schema tests and entity-storage/foundation schema guards.

Retain a 31-table validation benchmark with query counts and timings. This is
schema-guard evidence, not real kernel boot or installed consumer qualification.
Full #3182 remains open for real-consumer timing and exact-head hosted supported-profile qualification. No evidence from #3182 attributes or resolves #3183.

## Custody and qualification

Candidate-local Composer dependencies, installed from the unchanged lockfile.
Windows Git executable: `C:/Program Files/Git/cmd/git.exe`.
The worktree coordinator refuses native Windows drive-letter absolute paths;
the isolated retained worktree remains protected by this ownership record and
its dirty state. No existing worktree is borrowed or modified.
Independent review and exact-head hosted qualification remain required before
landing. Publication, landing, release and deployment are outside this slice.

## Initial guard-only evidence, 2026-10-03

Fetched origin/main still equals the base above. PHP 8.5.5, Doctrine DBAL 4.4.3,
PHPUnit 13.1.14, candidate-local Composer install from the unchanged lockfile.
Lockfile SHA-256: `c879487007897c66be2f8eb6b412a40da14319c42ca664e8802843563b22ef03`.

- Before repair, the three platform-discriminator cases failed because table
  existence was inspected more than once. After repair the database schema
  suite passes: 59 tests, 146 assertions, no notices or skips.
- EntityTypeManagerFactory: 10 tests, 28 assertions pass.
- SqlSchemaHandler, bundle fields and registry fallback: 40 tests, 91 assertions
  pass. TwoAxisSchemaSyncProvision integration: 3 tests, 9 assertions pass.
- BundleScopedUniqueKey and EntityTypeManagerFactoryFieldTypes: 11 tests,
  28 assertions pass, retaining constraint refusal and field-type authority.
- Retained probe: `tests/Fixtures/Audits/DatabaseLegacy/FW-SCHEMA-INSPECTION-01-guard-cost.php`.
  Seven passes through the unchanged 31-table, eight-field fixture: baseline
  1,302 SQL queries (1,303 on initial catalog-filter warmup), candidate 155.
  Representative first run median: baseline 84.259 ms, candidate 15.206 ms.
  Timings include Doctrine SQL logging overhead and are synthetic local
  schema-validation measurements, not production or full kernel boot timings.

Symfony reuse review: this slice adds no generic cache or lifecycle manager.
Existing Doctrine schema inspection, TableColumnNames and Doctrine logging
middleware provide the required primitives without a parallel mechanism.

Spec impact: entity-system and infrastructure now record per-requirement
inspection and freshness, including the adapter's canonical fieldNames seam.
S1 schema mutation authority and field-access policies remain unchanged.

Independent read-only subagent review approved the seven-file immutable patch
SHA-256 `61f384fe9f8debb07e017c38589f1266af22bd14e6c43092d3557672bb65bd1a`,
with no actionable source findings. Subsequent housekeeping fixes rename and
format the changelog fragment, record generated test-only roster entries and
extend this evidence record and infrastructure spec; the production implementation and tests are
unchanged. The reviewer subsequently approved that housekeeping delta with no findings.

The initial default preflight identified missing test roster entries, the
fragment filename/format and an affected infrastructure spec requiring an update.
Canonical roster regeneration added six test SQLite
construction entries and three test-only DDL entries, with no production
classification changes. Both roster gates and the fragment validator now pass.
The final default preflight and complete hosted qualification are separate
evidence boundaries; no local run is a hosted qualification claim.

## Expanded operation and kernel evidence, 2026-10-03

The new catalog discriminator failed before `inspectSchema()` existed. Expanded
scope tests exercise one catalog read for multiple tables, one column read per
table, reserved names, case-insensitive table existence, all nine DDL refusals,
nested failure, escaped adapters and fresh same-connection drift. A factory test
proves the registered-entity loop owns one inspection operation. The combined
schema/factory suites pass 86 tests / 222 assertions. Focused PHPStan on the
three changed source classes passes.

Retained probe:
`tests/Fixtures/Audits/DatabaseLegacy/FW-SCHEMA-INSPECTION-01-kernel-boot.php`.
Invoke with an independently installed artifact root, caller-owned disposable
project root and `prepare`, then `boot` or a refusal control. Preparation uses
real migrations, schema sync, `install:init`, field-access scanning and manifest
compilation. Fixture discovery metadata points to that artifact's own installed
packages; the real autoloader remains authoritative. No donor dependencies,
bootstrap override or vendor edits. Four core definitions plus 27 synthetic
indexed sql-column definitions produce 31 entity types. Parent kernel hooks
run; logging wraps the real driver without removing SQLite middleware.
This is synthetic core composition plus migration tooling, not FETDER's full
topology or a production HTTP/FPM request.

Seven interleaved fresh-process samples, identical probe protocol:

| Artifact | Median boot ms | Guard/composition ms | Query validation ms | Total SQL | Guard SQL |
| --- | ---: | ---: | ---: | ---: | ---: |
| Main at recorded base | 514.501 | 117.855 | 207.116 | 1,392 | 972 |
| Scoped candidate | 407.493 | 48.997 | 204.719 | 294 | 196 |
| Published alpha.303 | 496.263 | 118.152 | 206.658 | 1,392 | 972 |

Main and candidate use independent installs of identical lockfiles. Published
qualification uses 63 Waaseyaa packages at alpha.303, DBAL 4.5.0 and separately
resolved third-party dependencies. This is distribution baseline evidence, not
FETDER's lock or an isolated source comparison. Harness-only Symfony Filesystem
8.0.0 supplies Path. Published lock SHA-256:
`db0e77409c2fdb090d50c86840fe5f5247c5360570869f5f335f685df68061af`.
Reflection output verifies each artifact's real DBALDatabase/SchemaRequirement
source. No vendor source is modified.

Every successful sample verifies unchanged sqlite_master DDL and byte-stable
preflight. Each retains 54 durable privileged-read audit rows (reserve/finalize
for 27 indexed definition probes). Query validation remains dominated by those
writes; fingerprint inspection remains separate and live. Native disk and SQL
logging affect timings; query counts/refusal behavior are durable evidence.
Earlier guard-only kernel timing used a different protocol and is historical,
not directly comparable to this final measurement series.

Candidate and published cohorts both refuse missing table and missing langcode
with regenerated ready preflight ([S1-DB106]), plus schema drift with stale
preflight (FieldAccessActivationBlocked). All six controls verify unchanged
DDL/preflight and zero appended audit rows. These probes do not identify the
concurrent failing writer in #3183.

Maintained infrastructure: Doctrine owns catalog enumeration, canonical columns
and platform handling. Full `introspectSchema()` also reads indexes/foreign keys
and unrelated schema detail, which retain separate authority. This is a bounded
domain validation lifetime, not a generic cache service. No Symfony cache or new
generic cache manager is introduced; equivalence/freshness/DDL refusal are tested.

Raw evidence is retained in `C:/dev/waaseyaa/3182-evidence`:
`kernel-timings-final.json`, `kernel-controls.json`, published lock and review.
Portable probes/tests are repository-owned. Live MySQL/PostgreSQL, real FETDER
before/after, and full exact-head hosted qualification remain pending. Platform
doubles are not live-backend qualification. No package is marked converged and
no issue is closed by this local slice.

Final affected schema, factory, schema-handler, bundle constraint and two-axis
integration run: 137 tests / 343 assertions, green with no warnings/skips.
Focused PHPStan on all four changed source classes: no errors.
The additional DBALDatabaseTest diagnostic executes 26 tests / 68 assertions,
but native Windows unlink cleanup emits seven warnings in three tests. The
unchanged base reproduces the identical seven warnings; this is host-limited
cleanup evidence, not a green test result or a candidate regression. No test or
warning policy is weakened. The combined diagnostic before isolating this
host limitation executed 163 tests / 411 assertions with those seven warnings.

Independent read-only review approved the complete expanded patch
SHA-256 `11cdada06e3064a302fbf38d68b528556a109ac42dd9da57fc12304089741c75`
with no actionable findings, independently recalculating the retained kernel
measurements and refusal controls. The scope is a caller contract, not a
connection-wide enforcement boundary for raw SQL or independently held adapters.
Source/tests/specs/rosters remained frozen during review.

Expanded default preflight executed 43 gates: 42 passed, one fragment line-ending
failure, zero hosted-required and three not-applicable. Normalizing the fragment
to LF changes no indexed source bytes; the direct fragment validator then passes.
The final governed rerun is retained as `preflight-expanded-final.json` externally;
its equivalent-input reuse remains the runner's evidence, not fresh execution.
Full hosted qualification, including hosted-only ownership, is still pending.
