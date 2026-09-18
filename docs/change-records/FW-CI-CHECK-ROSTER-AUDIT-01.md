# FW-CI-CHECK-ROSTER-AUDIT-01: governed CI policy contract

Status: review candidate

GitHub mirror: waaseyaa/framework#3087

Pinned baseline: `2718edc02f8a47167db1b31b2192690bff773cfd`

## Intent and boundary

`tools/ci-check-roster.json` is a compact, hand-authored statement of intended
governance policy. It is not a manual copy of the expanded workflow graph.
Task 1 defines and self-validates the policy vocabulary, attestation subjects,
aggregate-lineage rules, artifact contract, and current required projection.

Generated workflow inventory is reserved for Task 2. Offline comparison of that
inventory with this policy is reserved for Task 3. Consequently, neither this
record nor its focused architecture test claims that every workflow job, trigger,
matrix expansion, dependency, or artifact in workflow YAML has been inventoried
or conforms to policy.

This slice changes no workflow, ruleset, branch rule, product code, release, or
deployment behavior. Aggregate producers described by the policy are contracts
only and are not implemented here.

## Frozen observations

- `origin/main`: `2718edc02f8a47167db1b31b2192690bff773cfd`
- Representative pull request: `#3086`
- Final PR head: `5297875460d47d58a6328380d8702235d6afce2f`
- Successful CI run: `35275141646`
- Failed evidence head: `63c46174e08d3c3f50ad576980f6933c2d0b680c`
- Failed CI run: `35271711540`
- Live ruleset: `15181711`, strict, with 22 required contexts
- GitHub Actions App integration ID: `15368`

The required projection preserves the live ruleset binding, not merely its
visible names. Twenty-one contexts are bound to GitHub Actions App `15368`.
`ci/mutation-pilot` is intentionally name-only and has a null integration ID.
These observations are frozen inputs to later live audit and migration work;
they do not authorize a ruleset change.

The earlier 55-check PR snapshot and 51-entry hand inventory remain historical
evidence only. They are not represented as a complete policy inventory because
conditional operational producers, including `Enable native auto-merge`, can
appear outside that snapshot.

## Policy model

Producer policy uses independent dimensions instead of overloading a single
class:

- role: policy, setup, execution, aggregate, advisory, publication, or
  orchestration;
- expansion: singleton or matrix;
- selection: composable `all_of` or `any_of` expressions over unconditional,
  path, actor, event, or label predicates;
- disposition: required, diagnostic, expected-skip, or publication-only;
- authority: merge, release, operational, or informational;
- cadence: pull request, merge group, main, scheduled, release, manual, or
  event-driven.

Stable producer and invariant IDs are references inside the policy. They are not
assertions about current GitHub job IDs or check-name derivation. The eight
invariant-level decisions are source/repository policy, PHP behavior/coverage,
security/authorization, public/package contracts, consumer acceptance, browser
acceptance, platform/runtime acceptance, and release integrity. Every current
required projection context references one owned decision.

The model includes `enable-native-auto-merge` as operational orchestration with
the live selector semantics: manual `workflow_dispatch`, or a `pull_request`
`labeled` event whose label is exactly `auto-merge-when-green`. It has no actor
predicate, and its diagnostic disposition permits the operational job to run
successfully. This is a contract representation, not workflow parsing.

## Attestation subjects

The vocabulary defines six distinct coordinates:

1. pull-request head SHA;
2. merge-ref SHA;
3. merge-group combined SHA;
4. main SHA;
5. run attempt;
6. artifact source SHA.

Each producer declares cadence-specific subject profiles. Pull-request,
merge-group, main, release/manual, and event-driven executions are alternatives,
not a conjunctive list of coordinates. Subject substitution is forbidden:
evidence for a PR head cannot silently satisfy a merge-ref, merge-group, or main
profile. Artifact-producing and consuming profiles additionally bind the
artifact source SHA, which must match exactly.

The schema supports a future merge-group profile bound to the merge-group
combined SHA. No current producer declares merge-group cadence or claims that a
merge queue exists.

## Aggregate lineage contract

Future stable aggregates must be owned within one workflow policy, use
`if: always()` semantics, and explicitly require successful prerequisite
results. Failure, cancellation, and missing prerequisites fail closed. A skipped
prerequisite also fails unless the policy supplies a not-applicable decision
bound to subject type, subject SHA, run attempt, producer ID, and reason. A
not-applicable decision cannot be reused for a different subject.

Task 1 records lineage for PHP behavior, PHP coverage, and random-order policy.
It does not implement those aggregates. Ordinary PHP shard coverage uses the
bounded artifact family `php-test-shard-1` through `php-test-shard-4`, expressed
by `php-test-shard-*`; it does not rely on an undefined matrix expression.

Random-order execution remains on every pull request at the current two-shard
width `[1, 2]`. Even a move to three shards is measurement-gated. Width and
cadence decisions wait until Task 8.

`ci/mutation-pilot` remains an unconditional required pull-request policy with
merge authority. Its intentionally name-only ruleset binding does not make it
path-selected, diagnostic, informational, or scheduled.

## Focused validation

`tests/Architecture/CiCheckRosterManifestTest.php` parses the checked-in policy
and validates only Task 1 guarantees:

- schema shape and exact controlled vocabularies;
- stable, unique IDs and valid internal references;
- complete, satisfiable, cadence-specific and non-substitutable subject profiles;
- aggregate lineage, terminal-state, and SHA-bound not-applicable rules;
- the bounded shard artifact contract;
- all eight owned invariant decisions and the unique 22-context projection from
  each required context to one of those decisions;
- the exact required-context integration bindings;
- the two-shard random-order and required pull-request mutation policies;
- the conditional auto-merge policy shape;
- the deferred Task 2 and Task 3 boundaries and residual task order.

Discriminating negative fixtures cover invalid enums, duplicate IDs, broken or
unmapped invariant references, missing or leaking subject profiles, flat
conjunctive subjects, selector drift, premature merge-group claims, random-order
width and cadence drift, mutation-policy drift, duplicate contexts, incorrect
integration bindings, cross-workflow lineage, permissive cancellation, artifact
subject mismatch, an unbounded artifact expression, and premature inventory
completion. The test deliberately does not read workflow YAML or infer
job-to-context identity, workflow triggers, generated matrices, needs, or
artifact behavior.

## Ordered residual work

0. Evidence freeze and external benchmark: complete.
1. Governance contract and schema repair: this candidate.
2. Inventory generator.
3. Offline conformance verifier.
4. Measurement baseline under #2869.
5. Stable aggregate shadowing.
6. Ruleset projection and live audit.
7. Ruleset migration.
8. Cadence optimization.
9. Final reconciliation.
