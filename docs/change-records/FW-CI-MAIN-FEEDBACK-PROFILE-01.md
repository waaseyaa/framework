# FW-CI-MAIN-FEEDBACK-PROFILE-01 - main feedback and full qualification

- Status: implemented for Framework #3170
- Parent: `8ac5587b621f425f09e81c3a53c906462294e8ab`
- Scope: hosted CI event profiles and exact-SHA release qualification
- Publication authority: unchanged; release-cut remains mandatory

## Finding

The repaired hosted run at `8ac5587b621f425f09e81c3a53c906462294e8ab`
started 57 jobs, took about 10.6 minutes wall time, and consumed 61.7 runner
minutes. The preceding failed attempt plus repair consumed 119.45 runner
minutes. A small PHPDoc repair repeated the broad matrix even though local
focused checks had already isolated the defect.

The earlier changed-package selector is not a suitable repair. Its corrected
dependency closure selected about 96 percent of the PHPUnit inventory and
saved only about 4 percent. Reintroducing it would add classification risk for
negligible benefit.

## Decision

1. An ordinary push to `main` runs a bounded feedback profile containing
   immutable-source, support, Composer-policy, lint, dead-code, governed-gate,
   manifest, ingestion, and security controls. It publishes
   `ci/main-feedback`.
2. Pull requests and explicit `workflow_dispatch` runs retain the complete CI
   graph. They publish `ci/full-qualification` only when all nine stable merge
   decisions succeed.
3. Superseded pull-request and ordinary main-feedback runs cancel. A manual
   full qualification run is not cancelled by a newer main push.
4. The generated workflow inventory and governed roster record the event
   selection. Full-profile producers are expected to skip on ordinary main.
5. Release-cut dispatches `ci.yml` with `profile=full` and requires the exact
   visible `ci/full-qualification` job to succeed on the release candidate.
   Workflow success alone is insufficient at that boundary.

## Boundaries

This is event-level profile selection, not path-based or package-based test
selection. It does not alter local hooks, focused-test obligations, branch
rewrite protection, split publication, package contents, or release tags.

The live main-protection ruleset remains governed by
`FW-SPRINT-MAIN-POLICY-01`. No required-check projection is changed here.

## Evidence plan

- Focused architecture tests must prove event selection, fail-closed decision
  jobs, cancellation, and release-cut binding.
- The generated inventory must be byte-identical to the workflow and roster
  conformance must report no errors.
- The candidate must receive a full exact-head hosted qualification before it
  lands.
- The first main run after landing must publish `ci/main-feedback`; its job
  count, wall time, and runner minutes are recorded on Framework #3170.
