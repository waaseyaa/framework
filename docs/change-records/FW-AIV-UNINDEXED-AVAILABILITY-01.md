# FW-AIV-UNINDEXED-AVAILABILITY-01: narrow projection-failure isolation

- Forge mirror: #3176; debt AIV-EXEC-COUPLING-001; parent #3137 stays open.
- Base: `98ec43b182f2f05ea3f64705ba2ecb6a29594c15`.
- Worktree: `C:/dev/waaseyaa/framework-worktrees/ai-vector-coupling-3176`.
- Branch: `codex/3176-never-indexed-availability`.
- Root owns integration, specifications, audit reconciliation, public/schema
  authorities and qualification. Explicit retained ownership preserves other
  lanes; the coordinator's Windows path refusal remains unchanged.

## Bounded design

Unrelated, policy-undeclared identities proven never indexed remain writable
when embedding projection storage fails, provided their entity database and
generation authority remain healthy. Previously indexed or uncertain identities
retain transactional invalidation, freshness and rollback. This is not general
projection-outage tolerance, deferred deletion or a new visibility framework.

Add a monotonic `potentially_indexed` marker to the existing generation row.
Existing rows are conservatively true; legacy vectors without rows are
backfilled before activation. New undeclared source-only identities may be
false. Source changes still lock and advance the identity within the entity
transaction before deciding whether projection deletion is required. Declared
identities and true/unknown history retain the original fail-closed behavior.
Missing or corrupt authority refuses. Historical uncertainty is not absence.

Indexing intent promotes history before provider execution. Pure undeclared
lifecycle/refresh cleanup rotates its token without manufacturing indexing
history. Canonical direct stores promote history under the same row lock and
in the same transaction as replacement, preserving an existing token. Failed
replacement rolls both effects back. Deletion never clears history. Legacy
embeddings-only storage remains supported; execution activation requires the
new migration and quiescence of older writers.

Artifact installation is a verified Framework-owned producer of vectors.
Serving tokens and tombstones stay authoritative. Candidate preparation must
conservatively reconcile imported vector identities with indexing history,
without accepting build-time tokens or falsely reporting unchanged history
digests. Its versioned contract and evidence must describe this transformation.
No Studio or Cloud change is required.

The marker is ai-vector domain coordination, not generic locking machinery.
Reuse established DBAL transactions/schema tooling and maintained Symfony
Process for bounded independent-connection tests. No transport, queue,
authorization or framework-wide persistence rewrite is included. Custom guards
retain conservative existing behavior unless independently qualified for this
new capability. Public guard/storage interface signatures remain unchanged.

## Ownership and test plan

- Core lane: guard, canonical database storage, executor, provider composition,
  forward-only migration and focused guard unit tests.
- Contract lane: shared SQLite/PostgreSQL fault and concurrency contracts,
  their bounded peer and migration fixtures.
- Artifact lane: deployer preparation/catalogue, focused preparation tests and
  SQLite artifact specification.
- Root: remaining documentation, fragments, generated authorities, integration,
  independent immutable review and hosted qualification. Shared-file integration
  waits for explicit stable handoffs. No donor vendor or autoload workarounds.

Capture a meaningful failing fault discriminator against the base before core
implementation. Require repeated undeclared create/update/delete during a real
projection fault, declared/history/in-flight fail-closed controls, direct-store
atomicity, migration legacy controls and missing/corrupt authority refusals.
Independent-connection races exercise absent and false identities in both lock
orders, with source commit, provider intent and direct store. Retain lifecycle,
refresh, old success/failure, deletion, exclusion, unpublication, throwing-hook,
batch and enclosing-transaction regression evidence. Providers remain outside
entity transactions. No unconfirmed cleanup or storage count is success.

Focused affected suites and default preflight run serially on native Windows,
with mandatory hooks. Exact-head hosted full qualification owns complete
SQLite/PostgreSQL and installation evidence. Independent subagent review examines
an immutable candidate. Open a separate PR after initial review. Explicitly
dispatch full CI with `sha=<candidate>` and verify its retained source artifact
before landing that identical SHA; green PR synthetic-merge CI is insufficient.
Observe exact-SHA main feedback after authorized landing. The #3178 provenance
deviation is an exception and must not recur.

No #3143 or private AIV-SEC-001 work, whole-package convergence, release, tag or
deployment. Narrow completion does not promise availability for conservatively
classified historical identities during projection outage.

## Implementation checkpoint

Candidate-local class reflection resolves guard, storage, executor and deployer
preparer into this worktree. The original source fault discriminator failed
before implementation. A scratch-only original artifact-preparer probe also
failed three history/fault discriminators; the live candidate was never restored
to old source during that probe.

Focused native evidence after integration: shared SQLite conformance 34 tests,
539 assertions; ai-vector units, directly affected integration callers and
transactional hook regressions 136 tests, 842 assertions; deployer preparation
and catalogue 43 tests, 365 assertions. Scoped production PHPStan passed.
Refresh bypass reports processed with zero stored/removed, including batch mode.
The provider-composition fixture uses the supported managed transaction boundary.
Schema and SQLite construction rosters record the new migration and test peers.
Public interface signatures and generated public-surface aggregates are unchanged.
Deployer declares database-legacy in require-dev for canonical migration fixtures;
production dependencies remain unchanged. Native default preflight initially
identified that test edge, infrastructure-spec coverage and line-ending drift;
integration repaired those findings before the immutable review checkpoint.
Default native preflight then passed: 42 gates executed, one governed equivalent
input reuse, zero failures and three not-applicable. Complete engine/backend and
installation proof remains owned by hosted full qualification.

Independent immutable review and exact-head hosted full qualification remain
pending at this committed checkpoint. Hosted real PostgreSQL owns the complete
backend verdict; local SQLite passes do not substitute for it. Final immutable
SHA, retained source artifact, review and landing evidence belong in #3176.
