# FW-CI-CHECK-ROSTER-AUDIT-01: CI check roster audit

Status: review candidate

GitHub mirror: waaseyaa/framework#3087

Pinned baseline: `2718edc02f8a47167db1b31b2192690bff773cfd`

## Intent

Establish a reviewable, source-controlled inventory of the checks visible on a
representative Framework pull request before any workflow, cadence, or branch
protection decision is made. The initial roster records producer identity,
classification, invariant ownership, trigger selection, dependency lineage,
current required status, failure ownership, artifacts, local equivalence, and
cost class.

The roster is evidence for the design and measurement phase. It does not
authorize a workflow edit, required-check migration, branch-rule change, or
cadence reduction.

## Frozen evidence

- `origin/main`: `2718edc02f8a47167db1b31b2192690bff773cfd`
- Representative pull request: `#3086`
- Final PR head: `5297875460d47d58a6328380d8702235d6afce2f`
- Successful CI run: `35275141646`
- Failed evidence head: `63c46174e08d3c3f50ad576980f6933c2d0b680c`
- Failed CI run: `35271711540`
- Ruleset: `15181711`, frozen with 22 required contexts

The final PR snapshot exposed 55 checks across five workflows. The
unconditional pull-request floor is 46 checks: the 41 jobs in `ci.yml` expand
to 45 visible contexts because the ordinary PHPUnit and random-order jobs are
matrices, and changelog discipline contributes the forty-sixth context.

The remaining nine contexts are conditional:

- four Admin execution checks selected by the PR's Admin paths;
- one Admin tag-publication check that was expected to skip on a pull request;
- one public-surface parity check selected by changed public-surface paths;
- three Dependabot Admin distribution checks selected by lockfile paths but
  expected to skip because the actor was not Dependabot and their dependency
  chain could not proceed.

## Decisions

1. `tools/ci-check-roster.json` schema version 1 is the initial portable
   inventory. Matrix producers occupy one roster entry and carry the exact
   template, axis, values, and observed expanded contexts.
2. Every check maps to an invariant with a named owner. A visible check name is
   not itself an invariant and multiple checks may intentionally share one.
3. Dependencies use stable roster IDs rather than GitHub job names. This makes
   derivative aggregates and expected dependency skips explicit.
4. `current_required_status` mirrors the frozen ruleset only. It does not
   recommend that a context remain required or become optional.
5. `unconditional_pr_floor` distinguishes checks emitted on every ordinary
   pull request from path-, actor-, dependency-, or tag-selected checks.
6. Local equivalence is either a concrete command or an explicit hosted-only
   reason. A hosted-only record is not represented as locally passing.
7. Cost classes are coarse observations for audit planning. Runtime
   measurements remain bound to their source runs and are not policy.

## Failure lineage captured by the failed run

At failed head `63c46174e08d3c3f50ad576980f6933c2d0b680c`, five execution jobs failed:
three ordinary PHPUnit shards and two random-order shards. The required
`ci/unit-tests`, `ci/coverage`, and `ci/random-order` contexts then failed as
derivative aggregates. This roster records those dependency edges so later
reporting can distinguish root execution failures from derivative red statuses.

This is overlap evidence for that run, not proof that random-order execution
has no unique defect-detection value.

## Validation

`tests/Architecture/CiCheckRosterManifestTest.php` validates the checked-in
manifest and contains discriminating negative fixtures. It rejects:

- duplicate stable check IDs;
- unknown check classes;
- malformed matrix patterns or expansions;
- invariants without a named owner.

It also binds the inventory to the pinned workflow producer jobs, verifies the
55 visible contexts, the 46-check unconditional floor, the 22 required-status
mapping, dependency references, and the frozen evidence identities.

## Scope boundary and next work

This slice changes documentation and audit data only. It does not change
workflows, branch rules, job names, contexts, triggers, cadence, permissions,
artifacts, or runtime behavior.

Later work under the same change record may measure unique detection, runner
minutes, critical path, reruns, and failure ownership, then propose a reviewed
roster and migration. No such migration is part of this candidate.
