# S1 Upgrade Compatibility

**Status:** Candidate contract for Stage 0 enterprise remediation.
**Anchor:** [#2336](https://github.com/waaseyaa/framework/issues/2336).
**Machine contract:** `packages/foundation/resources/upgrade/s1-v1.json`, loaded
through `UpgradePreflightContract` so the resource survives the Foundation
subtree split and consumers do not depend on a monorepo-root path.

## Purpose

This contract answers one question without changing state:

> Is an exactly observed installation eligible to begin the named S1
> transition?

The framework owns the decision vocabulary and pure evaluator. A consumer owns
collection of its dependency, configuration, schema, maintenance, and recovery
evidence. Deployment execution and recovery proof remain consumer work.

## Named transition

The first transition is `alpha293-to-s1-v1`.

- The supported predecessor is annotated tag `v0.1.0-alpha.293`, peeled
  framework commit `74fa67d94e1b523a26252c0642fd8f4f87c7841f`.
- The target compatibility baseline is framework commit
  `85b5e1b5b80f9f8dc347c1e3286cd42d989bf47f`, identified independently of
  the still-alpha `VERSION` text.
- The 59 installed framework-owned packages must belong to the single
  alpha.293 release cohort and match its canonical package-set digest. A
  `dev-main` framework package, an unrecognized split commit, a different
  tag, or an incomplete package inventory is not silently inferred compatible.
  The supported framework digest is
  `sha256:d44b9145d62b05cf447432585e019e01570895bd44884644e98794a45f14bb37`.
- The two Anokii extension packages are bound separately to the immutable
  predecessor digest
  `sha256:1c1eb5734843ac9423de87e8b5280fb17106cdce5196a555d95aa1ede850e916`
  and require explicit compatibility evidence. Their exact development commits
  are not mistaken for framework release-cohort membership.
- Target packages must be bound to the target framework commit by separately
  verifiable provenance. The evaluator does not manufacture provenance.
- Runtime support is named as `s1-v1`; consumer certification remains a
  separate evidence input.

The exact mixed Sheg baseline has 57 framework packages at alpha.293, two
framework `dev-main` exceptions, and two exact Anokii extension commits. It is
therefore not blessed as a supported predecessor. Its framework source evidence
must first become the coherent 59-package alpha.293 cohort or it is refused
with `SOURCE_PACKAGE_SET_MIXED` and
`SOURCE_PACKAGE_DIGEST_UNSUPPORTED`.

Package-set digests use UTF-8, LF-terminated rows sorted by package name. Each
row is tab-separated `name`, normalized Composer `version`, source
`reference`, and dist `reference`. The `sha256:` value hashes the complete
byte sequence including the final LF. Framework-owned and extension packages
are hashed separately so an extension cannot masquerade as a split framework
package.

## Boundary and non-goals

The preflight is pure and read-only. The consumer entry point loads the
Foundation-bundled contract, accepts observation data, and returns a
deterministic result. The lower-level evaluator accepts decoded contract data
for contract/fixture verification. It does not:

- read production files, Composer state, configuration, or a database;
- enter maintenance mode;
- install dependencies;
- import configuration;
- compile or apply schema migrations;
- call `migrate:rollback`;
- restore a backup;
- certify a consumer or deployment.

Those effects belong to an orchestrator after a `ready` decision. The
consumer must retain the exact observation and result beside its deployment
evidence.

## Observation envelope

An observation declares `schema_version: 1`, the exact `transition_id`, and
these six gates:

1. **source** — tag, peeled framework commit, complete package count and one
   release-cohort assertion.
2. **target** — exact framework commit, package-set digest, and verified
   source-to-split provenance status.
3. **platform** — named support profile and consumer-certification status.
4. **configuration** — versioned format, canonical manifest authority,
   active/sync drift, dependency validation, and deletion intent.
5. **schema** — migration-ledger authority, live-schema fingerprint, pending
   plan, and destructive-operation inventory.
6. **operations** — maintenance state, immutable backup evidence reference,
   restore-test status, and dry-run evidence reference.

Evidence references are content digests plus durable identifiers, not booleans
with no provenance. Unknown fields are rejected so a misspelt authority cannot
silently disappear.

## Ordered evaluation

Evaluation order is fixed and machine-readable:

1. `observation`
2. `source`
3. `target`
4. `platform`
5. `configuration`
6. `schema`
7. `operations`

Later gates never override an earlier refusal. Within a gate, reason codes are
returned in the contract's declared order. The complete result is safe to
serialize and compare byte-for-byte after canonical JSON encoding.

## Decisions

Precedence is `invalid` > `unsupported` > `blocked` > `ready`.

- `invalid`: the envelope is malformed, ambiguous, contains unknown keys, or
  targets a different contract.
- `unsupported`: the source identity/package cohort or target identity is not
  a supported transition endpoint.
- `blocked`: the transition endpoints are recognized, but platform,
  configuration, schema, maintenance, dry-run, or recovery evidence is absent,
  stale, unknown, or failed.
- `ready`: every required gate is exact and verified. This authorizes only
  the consumer's separately governed apply phase.

Warnings cannot produce `ready`. Legacy/null-checksum migration rows,
unversioned configuration, manifest-only verification, unknown live schema,
implicit deletion, missing restore evidence, and a mixed package set are
terminal preflight reasons.

## Configuration and schema authority

Existing convenience signals are deliberately insufficient:

- `ConfigManifest::verify()` does not currently bind every version, package,
  aggregate checksum, or recursive canonicalization invariant.
- The stable sync envelope does not currently carry a required supported
  format version.
- Migration verify treats historical null-checksum rows as a blocking unknown,
  binds new legacy rows to exact executable source, and compares the stored
  coordinator manifest with read-only live-schema introspection. Consumer
  readiness still requires zero unknown rows and exact retained evidence.
- Schema rollback can remove ledger authority when no reversible source ran.

The evaluator therefore consumes stronger explicit evidence fields and refuses
`unknown`. It does not reinterpret these open findings as accepted risk.

## Failure and recovery semantics

Preflight failure changes nothing and is safely repeatable.

After an apply phase has begun, any phase failure must:

1. stop before the next phase;
2. keep the application in maintenance mode;
3. retain the preflight, phase, and post-failure observations;
4. avoid generic configuration or schema rollback;
5. permit only a newly preflighted forward resume or a separately authorized,
   evidence-backed restore procedure.

The framework's forward-only release policy still applies. A successful
`migrate:rollback` command or config import is not recovery proof. Destructive
restore execution remains outside this work package.

## Consumer evidence point

`S0-SHEG-02` must collect a real observation from a disposable consumer,
prove the unsupported mixed predecessor is refused, construct or name an
eligible predecessor fixture, exercise every decision class, and demonstrate
non-destructive failure containment. It must not claim production recovery
until the separate recovery gate is authorized and complete.

## Verification

The framework package requires:

- a retained red regression before the evaluator exists;
- table-driven tests for every decision and required reason code;
- key-order invariance and unknown-key rejection;
- proof that evaluation performs no writes;
- a structural checker binding JSON keys, order, fixture outcomes, docs, and
  changelog;
- ordinary Unit, Architecture, Composer, style, static-analysis, and exact-head
  CI gates.
