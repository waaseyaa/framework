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

## Task 2: generated workflow inventory

`bin/generate-ci-workflow-inventory` composes `.github/workflows/*.yml` into
the tracked `tools/ci-workflow-inventory.json`. The generator library is
`bin/lib/ci-workflow-inventory.php` (plain `cwi_` functions, Symfony Yaml).
Options are `--root=DIR`, `--write`, `--check`, and a default render to
stdout; exit codes are 0 success, 1 generation failure or `--check` drift, and
2 usage. It is fail-closed — any unparsable or structurally invalid workflow
aborts the whole generation — and `--write` writes to a temporary file and
renames, so a partial inventory is never published.

The inventory records, per workflow: source file and SHA-256 of the
LF-normalized bytes, trigger events with their selectors, workflow-level
permissions, concurrency, defaults, environment keys, every job, the
workflow's artifact flows, any duplicate visible context, and the list of
identity-affecting expressions the generator did not expand. Per job it
records the derived name and its visible contexts, structural role with the
evidence behind it, the `strategy` block and its matrix expansion, runner,
timeout, environment, `defaults`, the reusable-workflow reference,
permissions and effective permissions, deduplicated `needs` and the reverse
`needed_by`,
the `if` condition with its classification, the expected-skip record, the
aggregate predicate, `needs.<job>.result` references with their locations,
declared outputs, produced and consumed artifacts, action references, step
count, and the repository-local commands the job runs.

### The three fact kinds

Every derived value carries one of three labels, so a reader can tell what was
read and what was inferred:

- `literal` — read verbatim from the YAML.
- `bounded-expansion` — substituted from a literal matrix, with every expanded
  value enumerated (context names, artifact-name families).
- `unresolved-expression` — contains a `${{ ... }}` expression only the GitHub
  runtime can evaluate. It is preserved verbatim and never evaluated.

### Derivation rules

The document carries its own `derivation_rules` block so the inventory can be
audited without the generator source. In prose:

- **Ordering.** Workflows sort by file name, jobs by job key, and `needs`,
  `needed_by`, commands, signals and artifact flows sort bytewise. Matrix axis
  order, matrix value order, include/exclude order and step order are semantic
  and are preserved as written. Nothing depends on the order jobs appear in the
  YAML.
- **Line endings.** Workflow bytes are normalized to LF before hashing and
  parsing, so a CRLF checkout produces an identical inventory, hashes included.
- **Visible contexts.** A literal job name is the context. A name whose only
  expressions are `matrix.<path>` over a literal matrix yields one bounded
  context per combination. A job with no name yields its job key, and over a
  literal matrix GitHub's documented default `"<job key> (<value>, ...)"`.
  GitHub documents that rendering for **scalar** axis values only; flattening an
  **object** axis value into its own values is this generator's own extension,
  and the order is YAML key (declaration) order, not sorted. The extension is
  unverified against a live check-run name, and it is not a corner case: **77 of
  the 160 visible contexts** depend on it — every context of `split.yml#split`,
  whose single axis is a `{local, remote}` object. Any other expression leaves
  the context null and unresolved.
- **Matrices.** Combinations are the cartesian product of literal axes in
  declared order; `exclude` removes every combination an entry partially
  matches; `include` then applies GitHub's documented rule — an entry extends
  every original combination it can extend without overwriting an original axis
  value, otherwise it becomes a new combination.
- **Conditions.** A job-level `if` is preserved verbatim, normalized
  (whitespace collapsed, an enclosing `${{ }}` wrapper removed), and classified
  by the contexts and status functions it references. Classification names
  references; it never evaluates.
- **Condition tokens.** Two sets are read off the `if` classification.
  *Non-propagating* is `always`, `not-cancelled` (the classification of
  `!cancelled()`), and `failure` — a job carrying any of them still starts when
  a prerequisite does not succeed. *Aggregate-qualifying* is `always` and
  `not-cancelled` only: those observe both outcomes and can therefore gate on
  prerequisite results, whereas `failure()` runs solely because something
  failed and cannot gate a successful run. `!cancelled()` classifies as both
  `cancelled` and `not-cancelled`; a bare `cancelled()` carries only the
  former, which is what tells the two apart. No workflow currently uses either
  form as a **job-level** gate (`ci.yml` uses `if: failure()` on steps, which
  the inventory does not classify), so this path has fixture coverage only.
- **Expected skips.** `own_condition` is true for a job with an `if` that does
  not contain `always()` — `always()` is the only condition that cannot skip
  the job, since `!cancelled()` still skips on cancellation and `failure()`
  skips a successful run. `inherited_from` lists transitive prerequisites that
  carry their own condition. `propagates_prerequisite_failure` is true for
  every job with `needs` whose `if` carries no non-propagating token.
- **Aggregates.** A job with `needs` whose `if` carries an aggregate-qualifying
  token is recorded with that token as its `gate` and its prerequisites split
  into those whose `result` it actually reads and those it does not.
- **Artifacts.** `actions/upload-artifact` and `actions/download-artifact`
  steps are recorded by name or pattern; matrix-derived names expand to a
  bounded family; a consumer selector is glob-matched against the bounded
  producer names in its workflow. A download carrying `run-id` is a cross-run
  consumer, and its producer candidates are matched across the whole
  repository and are candidates only.
- **Structural role.** A mechanical precedence over the job's own evidence:
  publication, orchestration, aggregate, setup, execution. It is not the
  policy role vocabulary, and it deliberately collides where the workflow
  itself is ambiguous — a test matrix whose artifact an aggregate downloads
  satisfies the setup rule, and a matrix that pushes satisfies the publication
  rule. `role_evidence` keeps every signal, so a consumer must read the
  evidence rather than the single label.
- **Local equivalent.** The `bin/`, `tools/` (`.sh`/`.php`), `tests/` and
  `scripts/` (through `bash`, `sh`, `php`, `node`, `./`, `$GITHUB_WORKSPACE/`,
  or standing alone on a run line), `composer`, `vendor/bin`, `npm` and `npx`
  command heads found in `run:` steps. A whole-line shell comment is replaced
  by a **blank line**, never deleted: deleting it would splice its neighbours
  together and let a multi-word pattern invent a command across the seam, so
  every pattern also separates its words with `[^\S\n]+` and can never cross a
  newline. Four statuses, and "no command found" is never conflated with
  "hosted infrastructure": `discoverable` (commands, no hosted signal),
  `partial` (commands alongside hosted signals), `hosted-signals-only` (no
  recognised command but hosted signals present), and `no-recognised-command`
  (neither — meaning the generator did not recognise the entry point, **not**
  that the job has none). The inventory never claims a local command reproduces
  the hosted job.
- **Permissions.** `effective_permissions` reports the job grant, else the
  workflow grant, else `repository-default` — the repository `GITHUB_TOKEN`
  default that workflow text cannot reveal.

### What stays unresolved, and why

Fourteen identity-affecting expressions in the current workflows cannot be
expanded offline, and the inventory names each one instead of guessing:

- `packagist-update.yml` `verify` and `split-main.yml` `split` take their whole
  matrix or one axis from `fromJSON(needs.<job>.outputs.*)`. Their combinations
  are `null`, their name derivation is `default-matrix-unresolved`, and their
  visible context is `null`.
- Three job names interpolate `inputs.*` (`packagist-recover.yml`,
  `packagist-register.yml`, `release-cut.yml`) — visible only at dispatch time.
- Seven artifact names interpolate `github.sha`, `inputs.sha`, a `needs`
  output, or a matrix over an unresolved matrix (three in `ci.yml`, three in
  `release.yml`, one in `split-main.yml`), so no bounded artifact family exists
  for them.

Two generator limits are recorded here rather than papered over. The default
name for an unnamed job over an object-valued matrix axis is GitHub's
documented scalar rendering extended by this generator — object values
flattened in YAML key (declaration) order — and that extension carries 77 of
the 160 visible contexts; no live check-run name has been observed for
`split.yml`. And publication through a third-party action leaves
no `run:` text, so the action reference itself is the evidence — the generator
recognises the two release-publishing actions this repository uses and would
miss a different one.

### What the focused test proves

`tests/Architecture/CiWorkflowInventoryGeneratorTest.php` builds throwaway
fixture roots and asserts each derivation rule: literal, template, object-axis,
default-job-key, default-matrix and unresolved name derivations; unresolved
whole-matrix and axis-expression matrices; GitHub's documented include/exclude
worked example combination for combination; scalar and list `needs`
normalization with reverse edges and duplicate-`needs` collapse; aggregate
prerequisite splitting under `always()`, `!cancelled()` and `failure()`; own,
inherited and propagated expected skips; pattern and cross-run artifact
matching; trigger selectors; `if` classification with its literals and
references; a matrix-less `strategy`, a literal and an expression
`timeout-minutes`, and a job-level `uses:` that stays off the unresolved list;
structural roles, permissions fallback and the four local-equivalent statuses.
One fixture is a dedicated splice probe: a comment between `echo composer` and
`install-something`, and a continued PowerShell line whose argument is a
`tests/` path, must yield exactly `['vendor/bin/phpunit']` — neither invention
happens. Ten negative fixtures prove the generator fails closed with a
distinguishing message, and the CLI cases prove `--check` drift exits 1, an
unknown option exits 2, and a missing workflow directory exits 1. Determinism
is proved twice: two runs are byte-identical, a CRLF fixture matches its LF
twin exactly, and a fixture whose jobs are written in reverse order differs
only in the source hashes. Finally the test regenerates the repository
inventory and asserts it is byte-identical to the tracked file, then pins a
handful of real workflow facts — 22 workflows, the four `ci/test-shard-*` and
two `ci/random-order-shard-*` bounded contexts, `split.yml`'s 77 literal
combinations and its 77-name `split-provenance-*` family, the `php-test-shard-*`
pattern consumer bound to `ci-test-shards`, the two `ci.yml` aggregates, the
conditional auto-merge orchestration job, and `packagist-update.yml`'s
unresolved matrix.

This slice makes no policy comparison. The generator never reads
`tools/ci-check-roster.json`, and the test asserts nothing about required
contexts, dispositions, cadences or invariants. No workflow, ruleset, branch
rule, product code, release, or deployment behaviour changed.
`tools/ci-check-roster.json` is intentionally untouched: its
`scope.generated_workflow_inventory` pointer still reads `deferred`, and
binding it to this generated artifact is Task 3's work together with the
offline conformance comparison.

## Task 3a: policy repair from inventory evidence

Binding the accepted Task 1 policy to the Task 2 inventory was attempted first
as part of Task 3. The binding derivation — every entry forced by an inventory
fact, nothing guessed — showed that three policy claims could not be bound at
all. Because `tools/ci-check-roster.json` is an accepted artifact, the repair is
recorded here as its own slice rather than folded into the verifier, and the
verifier is written against the repaired policy afterwards.

Derivation rule for every binding below: a required context binds to the unique
inventory job that lists it as a visible context; a producer binds to the unique
job in its bound workflow that a structural discriminator the policy itself
supplies forces — literal matrix values, the artifact contract's
producer/consumer roles, an aggregate's prerequisite edge, or being the
workflow's only job. Policy `role` is deliberately **not** compared with the
inventory's `structural_role`: the inventory's own derivation rules describe
that label as a structural heuristic that "is not the policy role vocabulary"
and that "deliberately collides" (`ci-test-shards` is structurally `setup` and
`execution` in policy; `split.yml#assemble-release-evidence` is structurally
`setup` and `publication` in policy).

### S1 — `ci-environment-setup` mapped to no job

*Evidence.* `ci.yml` has no CI-environment-setup job. 39 of its 41 jobs run
their own `actions/checkout` and 31 their own `shivammathur/setup-php`;
environment setup is a per-job step pattern, not a job. The only singleton jobs
the inventory labels `setup` are `prepare-test-plan` (uploads
`phpunit-shard-plan` and `vendor-archive`; needed by `ci-test-shards` and
`ci-random-order-shard`) and `prepare-random-order-plan` (uploads
`random-order-plan`; needed by `ci-random-order-shard` and `ci-random-order`).
Neither is an environment setup, and nothing in the inventory forces one over
the other.

*Decision.* The producer is removed. Two producers modelled on the real jobs
replace it — `phpunit-shard-plan` and `random-order-plan` — both `setup`,
singleton, unconditional, `diagnostic`/`informational`, cadence
`[pull-request, main]`, invariant `php-behavior-coverage`, with the same
pr-head/main + run-attempt profiles as the shard producers plus
`artifact_subject: artifact-source-sha`, because each uploads an artifact a
later job consumes.

### S2 — `source-integrity-policy` mapped to no unique job

*Evidence.* Applying every discriminator the policy supplies (workflow
`primary-ci` → `ci.yml`, singleton, not an aggregate, `disposition: required`
⇒ its visible context must be one of the 22) left 19 candidate jobs; narrowing
by the producer's own `source-repository-policy` invariant still left five
(`Manifest conformance`, `check-dead-code`, `ci/lint`, `ci/verify-gates`,
`composer-policy`).

*Decision.* Keep the producer id and bind it to `ci.yml#verify-gates`. That job
is the codified source/repository policy gate: it runs the 22 permanent
verify-only boundary gates through their `composer check-*` aliases — the set
`composer verify` enforces and the set the local pre-push `bin/check-pr-preflight`
roster mirrors — plus the append-only delivery-agent event check. Cadence,
disposition, authority and invariant are unchanged.

Its pull-request profile keeps `merge-ref-sha`. `ci.yml#verify-gates` checks out
`ref: ${{ inputs.sha || github.sha }}`, and on a `pull_request` event
`github.sha` is the merge-ref SHA, so the declared subject matches what the job
attests. That last step rests on documented GitHub `pull_request` semantics and
on reading the checkout step in `ci.yml`, not on an inventory field — the
inventory does not record step `with:` values.

### S3 — `release-publish-evidence` declared a cadence no workflow can produce

*Evidence.* No workflow in the repository declares a `release` trigger event.
The complete trigger-event census over all 22 files is `issue_comment` (1),
`pull_request` (5), `pull_request_target` (1), `push` (7), `schedule` (2),
`workflow_dispatch` (14), `workflow_run` (2). The former selector
`release-or-manual-dispatch` is not a GitHub event name and cannot be matched
against any trigger selector.

*Decision.* `release` cadence is defined as a `v*` tag push. `split.yml`
(`push: tags: ['v*']`) is the release-publication workflow, and
`split.yml#assemble-release-evidence` is the bound producer: it runs
`bin/generate-release-evidence`, uploads `waaseyaa-release-evidence`, and is
needed by `publish-github-release`. The selector becomes
`any_of [event push:tags, event workflow_dispatch]`.

`release` keeps `main-sha` in `cadence_sha_subjects`: a release tag is cut from
main by `release-cut.yml`, so the attested SHA is a main SHA by construction.
The rationale is recorded in the new
`attestation_subject_contract.cadence_semantics` block rather than inside
`cadence_sha_subjects`, which stays byte-identical.

`split.yml` is tag-push only and cannot serve the `manual` cadence, so the
producer carries a `recovery_producer` record naming `github-release.yml#release`
— the `workflow_dispatch` workflow that republishes the same
`waaseyaa-release-evidence` artifact. The offline verifier must consult that
record when it checks `manual` against triggers; binding a producer to two jobs
is otherwise outside the current producer shape.

### Sub-findings

- **Matrix axis.** Both matrix producers declared `axis: "shard"`; `ci.yml`
  declares `id` (`ci-test-shards` axes `{"id":[1,2,3,4]}`,
  `ci-random-order-shard` axes `{"id":[1,2]}`). Widths and values were already
  correct. The axis is now `id` in both.
- **Random-order lineage.** `ci.yml#ci-random-order` result-checks both
  `ci-random-order-shard` and `prepare-random-order-plan`. The lineage entry now
  carries `random-order-plan` as a second prerequisite requiring `success`.
- **Shard-plan lineage.** Verified and deliberately not added: `ci-unit-tests`
  and `ci-coverage` both have `needs: [ci-test-shards]` and
  `result_checked_prerequisites: [ci-test-shards]` only, so no aggregate
  result-checks `prepare-test-plan`. `phpunit-shard-plan` is therefore a
  prerequisite of nothing in the lineage contract.
- **Artifact contracts are unchanged**, and the reason is recorded in the new
  `policy.artifact_contract_scope` field. The contract shape is one producer,
  one consumer, one bounded family; `prepare-test-plan` uploads two families
  (`phpunit-shard-plan` and `vendor-archive`) and `vendor-archive` has two
  consumer jobs (`ci-test-shards`, `ci-random-order-shard`). Modelling the plan
  and vendor flows needs a wider shape, so they are left unmodelled rather than
  half-modelled, and the offline verifier reports them as coverage notices.

### The `bindings` block

A new top-level `bindings` object names its source
(`tools/ci-workflow-inventory.json`), its derivation rule, the three
`workflow_policies` (`primary-ci` → `ci.yml`, `release-publication` →
`split.yml`, `auto-merge-control` → `auto-merge.yml`), all eleven producers, and
all 22 required contexts. Every required context resolves to exactly one `ci.yml`
job with a `literal` derivation; no required context is missing, duplicated or
unresolved, and `ci.yml` declares a `pull_request` trigger.

### Scope pointers

`scope.task` is 3. `scope.generated_workflow_inventory` now reads
`{status: generated, task: 2, path, generator}`;
`scope.offline_workflow_conformance` reads `{status: in-progress, task: 3}` —
it becomes `implemented` only when the verifier exists. `residual_tasks` orders
0–2 are `complete` and order 3 is `current`.

### What the focused test proves

`tests/Architecture/CiCheckRosterManifestTest.php` keeps every Task 1
assertion and adds the Task 3a ones: the new scope pointers and residual
statuses, the governed producer roster in order, the `id` matrix axis on both
matrix producers, the two-prerequisite random-order lineage, the tag-push
release selector and its recovery producer, and the shape of the `bindings`
block. The binding check reads `tools/ci-workflow-inventory.json` and goes past
mere existence: every bound workflow file and job key must exist in it; every
producer and every required context must be bound; a producer's bound workflow
must agree with its workflow policy's; a required context's inventory owners
must be exactly the one job it binds, so binding it to another real job — in
the same workflow or a different one — fails; a producer's declared expansion
must be the bound job's; and a matrix producer's axis and values must be an
axis the bound job literally declares, which is what makes the axis assertion
inventory-derived rather than a literal in the test. The release producer's
`recovery_producer` job reference is checked the same way. It still reads no
workflow YAML and makes no conformance claim beyond those binding identities.
Fourteen negative fixtures were added and one retired (`premature-inventory`):
a drifted inventory pointer, a changed producer roster, the old `shard` axis, a
one-prerequisite random-order lineage, the old release selector, an unbound
producer, a renamed producer job, a renamed required-context job, a context
bound to another real job in the same workflow, a context bound to a real job
in another workflow, a matrix producer bound to a singleton job, a producer
bound outside its workflow policy, a workflow policy bound to a missing file,
and a reverted residual status. Two existing fixtures were re-aimed from the
removed `ci-environment-setup` onto `phpunit-shard-plan`, and four index-based
mutations were converted to id lookups so a future reorder cannot silently
un-aim them. The provider grew from 27 to 40 cases (28 → 41 tests, 36 → 58
assertions).

No workflow, ruleset, branch rule, product code, release, or deployment
behaviour changed. `tools/ci-workflow-inventory.json` and
`bin/generate-ci-workflow-inventory` are untouched, and
`php bin/generate-ci-workflow-inventory --check` still exits 0.

## Deferred observations

A ledger of things noticed while generating the inventory. None is acted on
here; each names its evidence and a suggested owner.

- ~~`tools/ci-check-roster.json` `residual_tasks[1].status` still reads
  `current` while this record reads `complete`, and
  `scope.generated_workflow_inventory` still reads `deferred` with `task: 2`.~~
  **Resolved by Task 3a.** The pointers now read `generated` (with the tracked
  path and generator) and `in-progress`/task 3, and residual orders 0–2 read
  `complete` with order 3 `current`. Manifest and prose agree in-tree.
- `Publish GitHub Release` is the visible context of **two** jobs —
  `github-release.yml#release` and `split.yml#publish-github-release` — so two
  workflows would produce check runs under one name. It is not a required
  context, and neither workflow has an internal `duplicate_contexts` entry
  (the collision is cross-workflow, which is why the inventory counts 160
  visible contexts over 159 distinct names). Whichever run reports last wins in
  any name-keyed projection. Owner: Task 6, then Task 7, before ruleset
  migration — the same place the ten unnamed jobs are already listed.
- `.github/workflows/split.yml` `publish-github-release` (job at line 488,
  `if: ${{ always() }}` at line 491) is a release-publishing job that starts
  regardless of prerequisite results; its first step fails closed on any
  prerequisite that is not `success`. `verify-packagist` (line 349, condition
  at line 352) deliberately proceeds when `publish-packagist` **failed**.
  Whether policy sanctions both shapes is a question for the aggregate-lineage
  contract. Owner: Task 3, then Task 5.
- The generator applies GitHub's documented default naming for unnamed matrix
  jobs — documented for scalar values only — and extends it by flattening
  object values in YAML key (declaration) order, to produce
  `split (packages/foundation, foundation)` and 76 siblings: 77 of the 160
  visible contexts rest on that unverified extension. No live check-run name
  has confirmed the rendering for an object-valued axis. Owner: Task 6
  (live audit).
- Publication through a third-party action produces no `run:` text. The
  generator treats the action reference as the evidence and recognises
  `softprops/action-gh-release` and `actions/create-release`; a different
  publishing action would read as `execution`. Extend
  `CWI_PUBLICATION_SIGNAL_PATTERNS` when one is adopted. Owner: whoever adopts
  it.
- CI structure has no owning spec. `CLAUDE.md` routes only the sharding
  subsystem (`bin/build-phpunit-shards`, `.github/workflows/nightly.yml`, and
  four named `ci.yml` jobs) to `docs/specs/ci-test-selection.md` and
  `docs/specs/governed-gates.md`; `tools/drift-detector.sh` maps
  `packages/workflows/` (the PHP package) and nothing under `.github/workflows/`.
  Neither routes `tools/ci-*` or `bin/generate-ci-workflow-inventory`.
  Suggested: a new issue for a CI-structure spec and its routing entries.
- ~~`composer cs-check` fails on `tests/Architecture/CiCheckRosterManifestTest.php`
  at the two arrow functions on lines 126 and 138: PHP-CS-Fixer 3.95.1's
  `@PER-CS2.0` wants `fn(` and the file writes `fn (`. It is the only linted
  file the fixer rewrites; every other `fn (` in the repository sits inside a
  generated-code string or a non-PHP script, and the linted trees are otherwise
  uniform at 1178 `fn(`. This predates the inventory slice, is not caused by
  it, and will fail `ci/lint` until fixed. Repair: `composer cs-fix`.~~
  **Resolved** in commit `9d37bb238`, "style(tests): apply PER-CS closure
  spacing to the roster manifest test" — a style-only orchestrator commit, not
  the Task 1 candidate. The file now carries zero `fn (` and the fixer dry-run
  is clean.
- `tools/preflight-gates.json` carries no inventory-drift gate. Drift is caught
  today only by this candidate's architecture test (which CI runs inside
  `ci/unit-tests`). A roster entry would also give
  `bin/refresh-governance-artifacts` — which is driven entirely by that
  manifest's `refresh` metadata — a mechanical repair
  (`php bin/generate-ci-workflow-inventory --write`). Whether the gate belongs
  on the preflight roster is a governed-gates decision. Owner: Task 3.
- No workflow declares a `merge_group` trigger, confirmed against all 22 files.
  Merge-queue cadence remains an open design decision already recorded in
  #3087.
- `split.yml`'s package matrix has **77** literal entries, not the 79 an
  earlier working note assumed. The count is derived from the file and is now
  pinned by the focused test.
- Eight jobs report `no-recognised-command` — no repository command and no
  hosted signal: `ci.yml#ci-random-order` and `ci.yml#ci-unit-tests` (both
  aggregates that only read prerequisite results, so they have no local command
  by construction), `packagist-update.yml#discover`,
  `release-gate.yml#check-approval`, `release-gate.yml#check-manifests`,
  `release-gate.yml#detect-quarantine`, `release.yml#validate-readiness-request`,
  and `split.yml#publish-github-release`. Each runs inline shell rather than a
  tracked script. This is an observation about the generator's recognised entry
  points, not a claim that the job cannot be reproduced locally. Owner: Task 3,
  when disposition and authority are compared.
- The include/exclude expansion path has **no production coverage**: no
  workflow in the repository uses `include:` or `exclude:`, so the only
  evidence for that code path is the fixture reproducing GitHub's documented
  worked example. Task 3 must not assume the repository exercises it. Four
  further paths are likewise fixture-proved only, because no current workflow
  exhibits them: a job-level `!cancelled()` or `failure()` gate, a matrix-less
  `strategy:`, an expression `timeout-minutes`, and a job-level `uses:`
  reusable workflow. The matrix-less `strategy:` case previously aborted
  generation outright, so the repository was one legal edit away from a
  fail-closed generator.

### From the read-only CI/CD hygiene sweep

Carried over from an independent read-only sweep and re-verified against the
current files (counts below are derived from the generated inventory, which
supersedes the sweep's estimates). None is acted on here.

- **Missing job timeouts: 75 of the 85 jobs declare no `timeout-minutes`.** The
  sharpest case is `.github/workflows/release-cut.yml:79` — the
  highest-privilege job in the repository, sitting behind
  `cancel-in-progress: false` (`release-cut.yml:76`), so a hung run blocks
  every later release cut. All nine `split.yml` jobs are untimed too, including
  the `verify-packagist` poll (`split.yml:349`). Owner: new issue, release
  machinery first.
- **Runner drift: 38 jobs on floating `ubuntu-latest`, 45 on pinned
  `ubuntu-24.04`, 2 on `windows-2025`.** A GitHub image rollover moves the
  floating third without a commit. Owner: new issue.
- **Nine workflows declare no top-level `permissions`** and so inherit the
  repository `GITHUB_TOKEN` default: `admin.yml`, `changelog-discipline.yml`,
  `ci.yml`, `packagist-update.yml`, `release-gate.yml`, `skeleton-smoke.yml`,
  `split.yml`, `surface-parity.yml`, `sync-skeleton.yml`. At the other end,
  `admin-dist.yml:11-14` and `auto-merge.yml:25-28` grant
  contents + pull-requests + actions `write` workflow-wide;
  `dependabot-admin-dist.yml` is the least-privilege exemplar. Owner: new
  issue.
- **Action pinning drift.** `ci.yml:1968` pins `actions/upload-artifact` at a
  SHA commented `# v4.6.2` while the other 21 uses say `# v7.0.1`;
  `manual-claude-review.yml:50` pins `actions/checkout` at `# v4` against
  `# v7.0.1` everywhere else; `actions/github-script` is `v7` in
  `manual-claude-review.yml:30,107` and `v9.0.0` in
  `discord-release.yml:38,141`. Owner: new issue.
- **Packagist publish/verify logic is duplicated four ways** —
  `split.yml:563-651`, `packagist-register.yml:31-75`,
  `packagist-recover.yml:46-196`, `sync-skeleton.yml:72-98` — and the P2
  polling loop three ways: `split.yml:359-420`,
  `packagist-update.yml:118-148`, `packagist-recover.yml:114-162`. Owner: new
  issue; consolidate before any new CI machinery depends on it.
- **`packagist-update.yml` documents itself as ad-hoc** (`:11-14`) but triggers
  on `push: tags: ['v*']` (`:38-40`), duplicating `split.yml`'s
  `verify-packagist` on every tag. Either the prose or the trigger is wrong.
  Owner: new issue.
- **Ten jobs are unnamed**, so their check name is the bare job id: seven
  singletons (`manual-claude-review.yml#review`,
  `packagist-update.yml#discover`, `skeleton-smoke.yml#smoke`,
  `split-main.yml#prepare`, `split.yml#verify-tag-parity`,
  `split.yml#verify-require-parity`, `sync-skeleton.yml#sync`) and three matrix
  jobs (`packagist-update.yml#verify`, `split-main.yml#split`,
  `split.yml#split`). `docs/specs/workflow.md:229` names `verify-tag-parity`
  (unnamed here) as primary release enforcement. Naming them before a
  roster is built from names avoids a rename churning the policy. `ci.yml` also
  mixes `ci/<slug>`, bare names, and one `support/s1-contract` (`ci.yml:56`).
  Owner: Task 6/7, before ruleset migration.
- **Two gates cannot fail.** `ci.yml:175-176` `composer-deps-audit` is labelled
  warn-only and `bin/audit-composer-deps` ends `exit 0`;
  `release-gate.yml:69-85` `detect-quarantine` emits only `::warning::` and has
  no failing path. Both read like gates from their names. Owner: Task 3, when
  disposition is assigned.
- **`sync-skeleton.yml:70` runs `git push origin main --tags --force`** to a
  second repository on every tag, in a workflow with no top-level
  `permissions` and no timeout. Owner: new issue.
- **Artifact retention is unpoliced**: 25 of 27 upload steps set an explicit
  retention, spread across 1, 7, 14, 30 and 90 days with no documented policy (`dependabot-admin-dist.yml:62` at 1
  day; `github-release.yml:146`, `release.yml:143,224`, `split.yml:476` at 90).
  Owner: new issue.

## Ordered residual work

0. Evidence freeze and external benchmark: complete.
1. Governance contract and schema repair: complete.
2. Inventory generator: complete.
3. Offline conformance verifier: this candidate. Task 3a (policy repair from
   inventory evidence) lands first as its own reviewed slice; the verifier is
   written against the repaired policy afterwards.
4. Measurement baseline under #2869.
5. Stable aggregate shadowing.
6. Ruleset projection and live audit.
7. Ruleset migration.
8. Cadence optimization.
9. Final reconciliation.
