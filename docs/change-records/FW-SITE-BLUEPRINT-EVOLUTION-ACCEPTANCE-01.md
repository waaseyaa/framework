# FW-SITE-BLUEPRINT-EVOLUTION-ACCEPTANCE-01 — additive successor acceptance

Status: candidate

Anchor mirror: waaseyaa/framework#2787

Parent candidate: `c0a8d5d4dab09d9bb527ec502f5c61b88b027564`

Parent change record: `FW-SITE-BLUEPRINT-01` work package 01D

## Intent

Bind reviewable acceptance evidence to the existing governed blueprint execution
contract for one supported, non-destructive evolution: applying the canonical
`minimal.yaml` blueprint and then the canonical `complete.yaml` blueprint under
a fresh exact-digest approval.

This record adds no execution authority. It tests the shipped
`ApplicationBlueprintCompiler` through `SiteInitializationService` and the real
`site:init` console boundary.

## Candidate scope

The candidate owns only:

- additive-successor, drift-refusal, and rollback tests in
  `packages/cli/tests/Unit/Site/BlueprintExecutionAdmissionTest.php`;
- one real-process successor test in
  `packages/cli/tests/Integration/SiteBlueprintProcessTest.php`;
- three pinned process envelopes under
  `packages/cli/tests/Fixtures/SiteInit/BlueprintProcess/`; and
- the corresponding unreleased change fragment.

No production source, compiler roster, transaction implementation, generated
artifact, package manifest, CI workflow, or shared qualification gate changes.

## Bound acceptance

1. A literal reviewed set of eleven paths defines the additive difference from
   the canonical minimal plan to the canonical complete plan. Tests compare the
   compiler's two complete path sets and the engine's reported `adds` and
   `drops` against that independent oracle.
2. Preview under receipt B reports that exact addition, reports no drop, and
   leaves the receipt-A project snapshot byte-identical.
3. Apply publishes the successor through the existing transaction and records
   receipt B in the generated blueprint evidence. Reapply reports
   `no_changes` without changing the snapshot.
4. Drift in an already-managed artifact refuses the successor before any new
   artifact appears and preserves the attempted project's complete snapshot.
5. A fault after replacement begins restores the receipt-A filesystem and
   evidence snapshot, attributes the terminal failure to receipt B through the
   existing recovery receipt chain, and leaves no transaction or stage residue.
6. The real console process is pinned at planned, applied, and no-change
   envelopes for the same two-receipt transition.

## Authority and safety boundaries

- Approval remains a separate `BlueprintDecisionReceipt` bound to the exact
  manifest and blueprint digests. This candidate does not authenticate its
  claimed actor.
- `SiteInitializationService` remains the only transaction, collision,
  rollback, recovery, and generated-ownership authority.
- The compiler's existing closed additive eligibility is unchanged. No flag,
  force path, overwrite permission, or subtractive evolution is introduced.
- Pinned process fixtures are observations of the canonical implementation;
  they do not define a second wire contract or permit tests to normalize away
  path sets, outcomes, or decision-receipt identity.

## Specification review

Reviewed `docs/specs/site-golden-path.md`, especially the approved blueprint
execution, closed additive roster, decision-receipt evidence, process-envelope,
and transaction sections. The candidate exercises those existing requirements
without changing behavior or architecture, so the spec remains current and no
`spec-reviewed:` commit trailer is required by `docs/specs/workflow.md`.

## Verification profile

Required focused evidence for this work package:

- `php -d memory_limit=1G ./vendor/bin/phpunit packages/cli/tests/Unit/Site/BlueprintExecutionAdmissionTest.php packages/cli/tests/Integration/SiteBlueprintProcessTest.php --no-coverage`;
- PHP syntax checks for both changed test files;
- JSON parsing for the three new process fixtures;
- `php bin/changelog-fragments validate`;
- the repository's mandatory default pre-push preflight before publication.

Full Unit, Integration, and Architecture qualification and exact-head hosted CI
belong to review-candidate acceptance, not this checkpoint commit.

## Residual scope

This candidate does not complete #2787. Packaged supported-upgrade and host
matrix evidence still compose the separately governed #2664 project lifecycle
and #2857 provider activation work. Existing-data/schema migration,
destructive retirement, and subtractive blueprint evolution remain outside the
fresh additive boundary and require their own reviewed contract.
