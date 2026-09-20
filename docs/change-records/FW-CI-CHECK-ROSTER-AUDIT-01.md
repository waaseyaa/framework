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

## Task 3: offline conformance verifier

`bin/check-ci-roster-conformance` compares the repaired policy with the
generated inventory and reports every mismatch. The library is
`bin/lib/ci-roster-conformance.php` (plain `crc_` functions, no Composer
dependency). Options are `--root=DIR`, `--policy=FILE`, `--inventory=FILE`,
`--ruleset=FILE` and `--json`; exit codes are 0 no errors, 1 one or more
errors, 2 usage or unreadable input. It never reads `.github/workflows/*.yml`:
the inventory is its only structural evidence, which is what lets it say
exactly what it checked and what it could not check offline.

### Three severities, and silence is never a pass

- **error** — the inventory positively contradicts a policy claim, or a claim
  that must have inventory evidence has none. Exit code 1.
- **notice** — the inventory carries structure the policy does not model. Never
  the reverse, never an exit code: the policy is a deliberate subset of the
  workflow graph and coverage gaps are reported, not punished.
- **not-verified-offline** — the check needs live GitHub state. Emitted as its
  own line so its absence is never read as a pass.

Output is one line per finding, carrying the severity, the rule id, the policy
locator, the inventory locator, the current value and the expected value —
the failure format `docs/specs/workflow.md` requires. `--json` emits the same
findings with per-severity counts.

### Rules

| ID | Rule | Severity |
|---|---|---|
| CRC001 | a `workflow_policies` binding names a workflow the inventory contains | error |
| CRC002 | a producer (or a `recovery_producer`) binding names an existing job in that workflow | error |
| CRC003 | a required-context binding names an existing job | error |
| CRC004 | the required context is a visible context of its bound job | error |
| CRC005 | the required context is visible in exactly one job repository-wide | error |
| CRC006 | the required context's workflow declares a `pull_request` trigger, so a required merge check is producible on a pull request | error |
| CRC007 | no `duplicate_contexts` entry collides with a required context | error |
| CRC008 | the producer's `expansion` is the bound job's `expansion` | error |
| CRC009 | a matrix producer's values equal the bound job's literal axis values; an unresolved matrix fails, because the policy claims a bounded width | error |
| CRC010 | a matrix producer's declared axis is an axis the bound job declares | error |
| CRC011 | `random_order_measurement.active_shards` equals the bound job's literal matrix values | error |
| CRC012 | an `unconditional` producer (every selector leaf unconditional, however composed) has a job with no job-level `if`, or one classified **exactly** as `always()`. Any other classification fails, including an empty one: the inventory has no classifier for `vars.*` or `env.*`, and `always()` is the only condition that cannot skip a job | error |
| CRC013 | each `event`/`label`/`actor`/`path` selector is evidenced by a trigger selector or the job's `if` classification | error |
| CRC014 | a trigger the inventory declares that no bound producer models | notice |
| CRC015 | disposition `expected-skip` implies a conditional job | error |
| CRC016 | disposition `required` implies an unconditional job | error |
| CRC017 | a policy aggregate's bound job is an inventory aggregate | error |
| CRC018 | that aggregate's `gate` is the policy's `condition_semantics` | error |
| CRC019 | every policy prerequisite is in the aggregate's `needs` **and** in its `result_checked_prerequisites` — an `always()` aggregate fails closed only on an explicit result check | error |
| CRC020 | prerequisite and aggregate live in the same workflow (`ownership_scope: workflow-local`) | error |
| CRC021 | a dependency edge between two bound jobs that the policy does not model | notice |
| CRC022 | the producer job uploads an artifact whose bounded expansion equals `bounded_expansion` | error |
| CRC023 | the consumer job downloads by `producer_pattern`, and that consumer's `matched_producers` contains the producer job | error |
| CRC024 | `producer_pattern` glob-matches every name in `bounded_expansion` | error |
| CRC025 | every declared cadence is producible by the triggers of the workflow that serves it | error (plus a notice naming a recovery split) |
| CRC026 | each required context's integration binding against a frozen `--ruleset` snapshot of shape `{"id": <int>, "strict": <bool>, "contexts": [{"context": <string>, "integration_id": <int\|null>}]}` | error, or not-verified-offline without one |
| CRC027 | a `ci/`-shaped visible context in neither the required projection nor a producer binding | notice |
| CRC028 | an invariant no producer owns | notice |
| CRC029 | a required context whose bound job has no recognised local command | notice |
| CRC030 | a policy field the verifier iterates is empty or unbound — an aggregate with no prerequisites, a measurement naming no bound producer, an empty required projection — each of which would silence a rule rather than prove it | error |

CRC025 honours the recovery split S3 recorded: when a producer declares a
`recovery_producer`, the recovery cadence is checked against the recovery
workflow and the remaining cadences against the primary workflow, and the
split is reported as a notice so it is never invisible. `release` is satisfied
by a `push` trigger with a non-empty `tags` filter, `manual` by
`workflow_dispatch`. CRC013 reads a qualified selector the same way:
`pull_request:labeled` needs a `pull_request` trigger whose `types` contains
`labeled`, `push:tags` a `push` trigger with a non-empty `tags` filter, and a
producer with a recovery producer may satisfy a selector from either workflow.

### What is out of offline scope, and why

- **The live ruleset.** Ruleset `15181711`, its strict flag, its 22 bound
  contexts and GitHub Actions App `15368` are live state. CRC026 compares them
  only against a frozen `--ruleset` snapshot; with no snapshot it reports
  `not-verified-offline`. The live audit is Task 6.

  The snapshot schema CRC026 expects is:

  ```json
  {
    "id": 15181711,
    "strict": true,
    "contexts": [{"context": "ci/lint", "integration_id": 15368}]
  }
  ```

  **Task 6 must emit exactly that shape from the live ruleset**:
  `GET /repos/{owner}/{repo}/rulesets/{id}`, whose `required_status_checks`
  rule carries one entry per required check with its `context` and
  `integration_id`. A null `integration_id` is an intentionally name-only
  binding — `ci/mutation-pilot` is the one the policy records — and CRC026
  compares it as null rather than skipping it.
- **Actual check-run names.** The verifier compares the inventory's *derived*
  visible contexts. GitHub's own rendering is unobserved, in particular the
  object-matrix default naming that carries 77 of the 160 visible contexts
  (`split.yml#split`). The inventory records that extension as unverified and
  so does the verifier.
- **Attestation-subject profiles.** No offline artefact records which SHA a
  hosted run attested. The policy self-validates their shape in Task 1; the
  verifier does not re-assert it and cannot confirm it.
- **Expression evaluation.** Only the inventory's `if` *classification* is
  compared. No condition is ever evaluated, so a selector check proves that
  the expression *references* the predicate, not that it is true.
- **Policy `role` versus `structural_role`.** The inventory's own derivation
  rules call that label a structural heuristic that "is not the policy role
  vocabulary" and that "deliberately collides", so the verifier does not
  compare them.

### What the verifier assumes the Task 1 self-validator already proved

The verifier is a comparison tool, not a schema validator. It assumes
`tests/Architecture/CiCheckRosterManifestTest.php` has already proved the
policy's own shape: the controlled vocabularies, stable and unique ids with
valid internal references, complete cadence-specific and non-substitutable
subject profiles, the eight owned invariant decisions and the unique 22-context
projection onto them, the aggregate terminal-result and SHA-bound
not-applicable rules, the bounded shard artifact contract, the exact
integration bindings, and — since Task 3a — that every producer and every
required context carries a binding whose workflow and job exist in the
inventory. None of that is re-asserted here.

What the verifier cannot assume is that an iteration has anything to iterate.
CRC030 is the narrow backstop for that: an aggregate with no prerequisites, a
`random_order_measurement.producer_id` naming no bound producer, or an empty
`required_projection.contexts` would each make a rule report nothing and read
as a pass. Those three are errors in their own right.

### The real-tree result

On the tracked pair the verifier exits 0 with **zero errors**, 19 notices and
1 not-verified-offline line. The notices are: one `CRC014` (`ci.yml` declares a
`workflow_dispatch` trigger no bound producer models); three `CRC021`
dependency edges the aggregate contract does not cover
(`ci-test-shards`←`prepare-test-plan`, `ci-random-order-shard`←
`prepare-random-order-plan`, `ci-random-order-shard`←`prepare-test-plan`); one
`CRC025` recording that the `manual` cadence is served by
`github-release.yml#release`, not by `split.yml`; eight `CRC027` gate-shaped
`ci/` contexts outside the policy (`ci/cli-health-report`,
`ci/cli-io-consumer-contract`, `ci/cli-sync-rules`, `ci/local-operator-windows`,
`ci/site-init-profile-acceptance`, `ci/site-recipe-provider-activation`,
`ci/split-artifact-acceptance`, `ci/studio-alpha-acceptance`); four `CRC028`
invariants with no producer (`public-package-contracts`, `consumer-acceptance`,
`browser-acceptance`, `platform-runtime-acceptance` — each owns required
contexts but no modelled producer); and two `CRC029` required contexts whose
bound job has no recognised local command (`ci/random-order`, `ci/unit-tests`,
both aggregates that only read prerequisite results). None is a defect; each
names something a later task may choose to model.

### What the focused test proves

`tests/Architecture/CiRosterConformanceTest.php` builds a compact fixture
policy-and-inventory pair that conforms with zero errors, then proves each rule
fires on its own drift: one discriminating mutation per rule asserting the
exact rule id **and** policy locator, covering a missing workflow, a job
rename, a renamed required-context job, context drift, a context produced by
two jobs, trigger drift, a duplicate context, expansion drift, matrix drift, an
unresolved matrix, axis drift, random-order width drift, four conditional
"unconditional" producers (an actor gate, an unconditional leaf carrying an
extra key, two unconditional leaves, and a condition the inventory cannot
classify), a selector with no evidence, expected-skip drift, a
required producer on a conditional job, an aggregate that is not one,
cancellation-propagation drift, a missing dependency, an unchecked prerequisite
result, cross-workflow lineage, a missing artifact upload, a missing artifact
download, an unmatched producer, an unbounded pattern, cadence drift, and
integration-binding drift against a snapshot, and the three CRC030
vacuous-pass paths. It also proves the
integration-binding rule reports `not-verified-offline` without a snapshot and
passes with a matching one, that coverage notices never fail a run — naming CRC014, CRC021, CRC027,
CRC028 and CRC029 explicitly — and the CLI
cases: exit 2 on an unknown option, on an unreadable file and on malformed
JSON, exit 1 with an `ERROR [CRC009]` line on a drifted temp root, and `--json`
parsing. The repository case is the drift gate: the verifier run against the
tracked policy and inventory must exit 0 with zero errors, every finding a
notice or not-verified-offline, and at least one not-verified-offline line so
the live-ruleset gap stays visible.

### Preflight roster

Two entries were added to `tools/preflight-gates.json`, both `default`
profile, because both are mechanical and neither needs a workflow edit —
`enforced_by` accepts an `architecture-test:` surface, and CI already runs the
Architecture suite inside `ci/unit-tests`:

- `check-ci-workflow-inventory` runs `php bin/generate-ci-workflow-inventory
  --check`, is enforced by `CiWorkflowInventoryGeneratorTest.php`, and carries
  an `auto` refresh (`--write`), so `bin/refresh-governance-artifacts` can
  repair inventory drift mechanically.
- `check-ci-roster-conformance` runs the verifier, is enforced by
  `CiRosterConformanceTest.php`, and carries a `manual` refresh: the policy is
  hand-authored governance with no write mode, so the instruction is to read
  the `CRCxxx` lines and decide whether the policy or the workflow is wrong.

Neither gate declares a composer `alias`, which the manifest allows, so no
`composer.json` change was needed. `bin/refresh-governance-artifacts` reports
both as `clean`.

No workflow, ruleset, branch rule, product code, release, or deployment
behaviour changed. `tools/ci-workflow-inventory.json` and
`bin/generate-ci-workflow-inventory` are untouched. The only policy edit in
this slice is `scope.offline_workflow_conformance`, now
`{status: "implemented", task: 3, verifier: "bin/check-ci-roster-conformance"}`.

## Task 4: measurement baseline

Tasks 1-3 produced a policy, a generated inventory, and an offline verifier.
None of them says what CI actually costs or what it actually catches. Task 4
adds the evidence layer: a frozen cohort of real runs, collected once,
classified offline, and reported deterministically, so the cadence decision in
Task 8 rests on numbers rather than impressions. It recommends nothing.

### Why a structural fingerprint, and not byte identity

The measurement contract on #2869 asks for a *comparable* cohort. The obvious
reading - runs of a byte-identical `ci.yml` - does not survive contact with the
repository: over the 80 most recent eligible pull-request runs there are **four
distinct `ci.yml` blobs**, the largest covering 61 runs and the smallest one.
Byte identity would admit nine runs, below even the ten-run floor
`docs/specs/ci-test-selection.md` section 9 already states.

Comparability is therefore **job structure**. For each run the workflow file is
fetched at that run's own head SHA
(`GET /contents/.github/workflows/<file>?ref=<sha>`), written into a throwaway
root holding only that file, composed by the **Task 2 generator**
(`bin/generate-ci-workflow-inventory --root=<temp>`), and reduced to a SHA-256
over the canonical JSON of every job's key, expansion, literal matrix
resolution/axes/expression, sorted `needs`, aggregate gate token, and sorted
visible contexts. Fingerprints are cached by the file's git blob SHA, so N
distinct blobs cost N generator runs however large the cohort.

Two consequences are recorded rather than assumed. A reformat, a comment, a
runner-image bump, a timeout or a step edit leaves a run **in** the cohort; a
renamed job, a wider matrix, a new dependency edge or an added job takes it
**out**. And the fingerprint bounds the job *graph* and nothing else - two runs
sharing a fingerprint may still differ in step content, runner image, test count
or dependency versions, which the report says plainly.

Reusing the Task 2 generator rather than writing a second workflow parser is
deliberate: a fingerprint derived from an independent parse would be a claim
about that parse, not about the graph the inventory and the verifier already
govern.

### The resolved cohort

Collected 2026-09-18T17:59:59Z in 447 GitHub requests, all GET, with zero
transient retries; classified at 18:09:06Z in a further 92 requests.

| Cohort | Workflow | Runs | Red | Scanned | Span (run created) |
|---|---|---|---|---|---|
| `pull_request` | `ci.yml` | 60 | 24 | 70 | 2026-09-07T23:16:09Z .. 2026-09-18T04:13:24Z |
| `main_push` | `ci.yml` | 20 | 0 | 20 | 2026-09-07T17:59:15Z .. 2026-09-17T16:50:06Z |
| `nightly` | `nightly.yml` | 33 | 0 | 33 | 2026-08-17T19:29:26Z .. 2026-09-18T09:27:53Z |

115 run attempt records and 3,642 job records. Ten runs were skipped and each is
recorded with its reason: five `action_required` (never executed) and five
cancelled - a superseded-run cancellation is not a defect and is counted as
none. **Red** counts first-attempt run records whose conclusion is `failure`,
computed from the collected records rather than from the walk's own tally, so
the column and the sections below describe one population. The fingerprint-enforced runs span **three distinct `ci.yml` blobs** -
five across both workflows, counting `nightly.yml`'s two - and all three reduce
to the one `ci.yml` reference fingerprint. Byte identity against the largest
blob alone would have kept about three quarters of the enforced cohort and
discarded the remaining quarter, which is below neither the ten-run floor nor
the fifteen-red floor on its own; what the structural definition actually buys
is that the cohort does not shrink every time `ci.yml` is edited, and it is a
property of this month's edit rate rather than a fixed ratio.

### Two runs GitHub listed no jobs for

Two attempts are *in* the cohort and could not be fully described. Building run
records from the jobs listing alone made both vanish from `runs[]` and `jobs[]`
while they stayed in `resolved.run_ids` - a resolved run silently absent from
its own evidence. They are now recorded from the run object with
`jobs_listed: 0`, null durations, and an entry in `cohort.incomplete_runs`.

| Run | Attempt | Attempt conclusion | Latest attempt | What GitHub reports |
|---|---|---|---|---|
| `34298553407` | 1 | `failure` | 1 | `completed`/`failure` with `total_count: 0` jobs |
| `34298520824` | 1 | `action_required` | 2 | no jobs for attempt 1; `filter=all` returns only attempt 2's 45 |

Two assumptions died here. A completed run does **not** necessarily have job
records, and `filter=all` does **not** mean every attempt - it returns what
GitHub still holds. And an earlier attempt's conclusion cannot be taken from the
run object, whose `conclusion` describes the **latest** attempt: doing so would
have labelled `34298520824` attempt 1 a `success`, when
`GET /actions/runs/{id}/attempts/1` reports `action_required` - it never
executed, which is precisely why it has no jobs. That conclusion is now read
from the per-attempt endpoint, so it stays observed rather than inferred.

A red run with no job records still counts as red: it concluded, and its
conclusion is evidence. It simply has no job that owns the failure, which the
classification records as `failure_ownership.note: no_jobs_listed`. Its
durations and cost proxy stay null rather than zero -- summing a cost over no
jobs would assert the attempt was free, which is not knowable for an attempt
whose jobs were merely unlisted.

### Headline numbers

Percentiles are nearest-rank, so every figure below is a value that occurred.

| Cohort | n | Queue (median / p95) | Run wall (median / p95) | Cost proxy (median / p95) |
|---|---|---|---|---|
| `pull_request` | 60 of 62 | 3 s / 29 s | 525 s / 1,243 s | 3,299 s / 3,524 s |
| `main_push` | 20 of 20 | 3 s / 3 s | 488 s / 540 s | 3,184 s / 3,419 s |
| `nightly` | 33 of 33 | 3 s / 4 s | 404 s / 621 s | 401 s / 634 s |

The two pull-request attempts missing from every distribution are the two with
no job records: no job timestamps exist to derive a duration from, and summing a
cost over no jobs would assert the attempt was free, which is not knowable. They
stay null and are counted in the report's `Missing` column rather than entering
the sample as zeroes.

The cost proxy is summed job-wall seconds weighted by GitHub's published runner
multipliers. It is **not** billed spend, and no billing figure appears anywhere
(see "What is observed" below). A pull-request run costs roughly eight times a
nightly run on that proxy while taking only about 1.3 times the wall clock -
the difference is parallel breadth, which is exactly why the contract asks for
the three quantities to be reported separately.

**Critical path length is not an independent number here.** Every job in these
workflows is created at run start, so a path measured from the first job's
creation to the terminal job's completion necessarily equals the run wall, and
the table above would repeat itself. What the path *composition* adds is which
jobs gate a run: `Prepare timing-balanced PHPUnit plan` sits on 64% of the 113
attempts, `ci/test-shard-1` and `support/s1-contract` on 50% each, and
`ci/coverage` is the terminal job of 50%. A width or cadence change can only
move the wall by moving something in that table.

First-attempt job classifications. These count the **first attempt only**, so
the 90 job records belonging to second attempts are excluded and the column
sums to 3,552 of the 3,642 collected records:

| Classification | Count |
|---|---|
| `success` | 3,427 |
| `root_execution_failure` | 60 |
| `derivative_aggregate_failure` | 55 |
| `unexpected_skip_or_missing_prerequisite` | 6 |
| `setup_or_infrastructure_failure` | 4 |
| `cancellation`, `expected_conditional_skip`, `publication_only`, `unclassified` | 0 each |

Counted from the collected records rather than from the walk's own tally, the
pull-request cohort's 60 first-attempt runs are 35 green, 24 red and one
`action_required` attempt that never executed. The 55 derivative aggregate
failures are deduplicated from their roots and each names the prerequisite that
caused it, so those 24 red runs are owned by 64 root or setup failures rather
than by the 125 red checks they produced. The
root owners are led by `ci/random-order-shard-2` (11), `ci/test-shard-3` (10),
`ci/random-order-shard-1` (9), `ci/test-shard-1` (9) and `ci/verify-gates` (9).
`ci/coverage` and `ci/unit-tests` are aggregates that nevertheless owned 4 and 1
*root* failures respectively - they failed on their own account, not because a
prerequisite did, which is precisely the distinction the classification exists
to make.

**Reruns: one**, in 60 pull-request runs. Run `34315787526` was rerun on the
same head and `Deterministic release evidence` flipped red to green, so it is
classified a flake. The head-SHA rule is stated and verified rather than
assumed.

**Random-order uniqueness: 20 failed `ci/random-order-shard-N` jobs, 19
corroborating, 1 unique first-pass detection, 0 not classifiable**, every one of
the 20 parsed at `high` confidence and every shard artifact still present. The
single unique detection is run `34374251430`,
`ci/random-order-shard-2`, which failed
`Waaseyaa\SiteContract\Tests\Unit\Generation\SiteVerifyPhpunitCacheIsolationTest::withoutTheCacheDirectoryOverrideTheTreeGenuinelyDiffersAcrossTwoRuns`
while no ordinary shard on that head failed at all.

That one case matters for what it refutes, not for what it proves. The
preliminary seven-run sample recorded on #2869 observed that *every* random-order
failure co-occurred with an ordinary shard failure. Over 60 comparable runs that
is no longer true: the co-occurrence rate is 19 of 20, not 20 of 20. One
detection in 60 runs is a rate this baseline reports, not a cadence argument,
and Task 8 owns what to do about it.

### What is observed, and what is derived

Every dataset field is typed `observed` or `derived` per
`docs/specs/delivery-telemetry.md`, and the stronger invariant holds: nothing is
`derived` from a value that was not `observed` in the same dataset.

`observed` is what a GitHub API response said - run id, attempt, head SHA,
event, workflow, the run timestamps and conclusion; per job the id, name, runner
labels, created/started/completed instants, conclusion and step inventory.
`derived` is everything computed here: the fingerprint, the pull request number
and which endpoint answered it, queue and wall seconds, the job key, the runner
multiplier, every classification, the critical path, the cost proxy and the
failing-test identities.

The log and artifact parses are the sharp case. `ci/random-order-shard-N`
uploads **no JUnit** - it runs PHPUnit with `--no-coverage` and no `--log-junit`
and uploads nothing - so its failing-test identity exists only in the job
console log, while the ordinary side lives in the `php-test-shard-*` JUnit
artifacts. Both are `derived`, and each carries an `observed` fetch record (job
or artifact id, byte length, SHA-256, fetched-at) so the derivation has a
substrate inside the dataset: 20 log observations and 72 artifact observations.
The log parser records its own version, its matched raw lines, and a confidence
that is `high` only when the parsed identity count equals the failures and
errors PHPUnit's own summary lines report.

One bias is recorded rather than corrected: the artifacts API does not say which
attempt produced an artifact, so on a rerun the ordinary set may union both
attempts. A larger ordinary set can only make a unique verdict harder to reach,
so the bias is conservative and never inflates `unique_first_pass_detection`.

**Billed runner minutes are structurally unavailable.** On a public repository
`GET /actions/runs/{id}/timing` reports `duration_ms: 0` per job, and
organization billing needs an `admin:org` scope this tooling does not hold. As
the measurement contract directs, summed job-wall seconds weighted by GitHub's
published per-OS multipliers is recorded and reported as a **cost proxy**,
labelled at every appearance.

Missing evidence is reported rather than rounded away: 2 of 3,642 job records
could not be mapped to an inventory job, both because GitHub reports a matrix
job that is skipped *before it expands* under its unexpanded name
(`ci/test-shard-${{ matrix.id }}`), which is not a visible context the inventory
can bound. No job lacked a wall or queue time, no artifact had expired, and no
job fell outside the classification vocabulary.

### Classification, and what it refuses to conflate

Per job, first match wins, and the precedence plus the bounded infrastructure
step list travel inside the dataset so a label can be audited without this
source: `cancellation`, `expected_conditional_skip`,
`unexpected_skip_or_missing_prerequisite`, `derivative_aggregate_failure`,
`setup_or_infrastructure_failure`, `root_execution_failure`, `publication_only`,
`success`, `unclassified`.

Three refusals matter. A **superseded-run cancellation is not a defect**. A
**derivative red aggregate is deduped from its root** and names it. And
**`always()` never explains a skip** - the predicate is the inventory's own
`expected_skip.own_condition`, whose derivation rule already states that
`always()` is the one condition that cannot skip a job, rather than a second
opinion computed here.

Two vocabulary terms have **no instance** in this repository and are reported as
explicit zeros rather than omitted: `ci.yml` declares no skippable job-level
condition (three of its 41 jobs carry an `if`, and all three are `always()`
aggregates), and neither `ci.yml` nor `nightly.yml` contains a job the inventory
labels `publication`. An absent row would be indistinguishable from a term the
pass forgot to apply.

Per run: first-pass outcome, rerun outcome, critical path, cost proxy and pull
request latency, kept separate as the contract requires.

### Four things the design did not anticipate

Three of them were found by running the pipeline against real GitHub, not by
reasoning about it, and each is a correctness fix rather than a workaround.

1. **Cohorts have two shapes.** "Every nightly run" was first expressed as a
   numeric target larger than the number that exists, which read as an unmet
   floor and would have put a volatile count into a governance artifact. An
   `exhaustive` cohort now never stops early and is complete only when the
   *listing* ended the walk, never when the page budget did.

2. **A single `HTTP 502` aborted a 447-call collection.** Fail-closed was
   correct and left no partial file, but the tool was unusable. Only *transient*
   failures (5xx, or a transport error with no HTTP status) are retried, up to
   four attempts with backoff; a definite answer (404, 410, 403) is never
   retried, an exhausted rate limit is never transient, every attempt counts as
   a real API call, and the dataset records `retried_requests` so a collection
   never reads as cleaner than it was. A raw GET that keeps failing transiently
   now throws rather than returning null, because a 502 is not an expiry and
   must not be recorded as one.

3. **A `page=1` request returned page 2's content.** The `main_push` cohort
   scanned 500 runs, kept none, and silently lost its hundred most recent runs,
   while exit status, call count, page count and retry count all looked healthy;
   only the run ids exposed it (500 scanned, 400 distinct). The walk now asserts
   that a run listing strictly advances - page N+1's highest run id below page
   N's lowest, and no run id twice in one cohort - refetches a page that does
   not, and fails closed naming the page. This is the defect most worth carrying
   forward: any other paginated Actions-API walk in this repository has no such
   guard, and it is filed in Deferred observations.

4. **The skip predicate belonged to the inventory.** Testing the job's `if` for
   non-null would have labelled a skipped `always()` aggregate an expected
   conditional skip.

### What the focused test proves

`tests/Architecture/CiRunEvidenceTest.php` is fixture-driven and makes **no live
GitHub call**: the transport is injected, so collection, classification and
reporting run end to end against a fixture response map.

It proves that a reformat and a comment leave the fingerprint unchanged while a
renamed job, a wider matrix, a new `needs` edge and an added job each move it;
that the pull request number falls back to the commit-to-pulls endpoint and
stays null when no pull request exists; queue and wall arithmetic including the
never-negative clamp; the classification of a root execution failure, a setup
step failure, an expected conditional skip, a prerequisite-starved skip, a
cancellation and a derivative aggregate, each naming its root; that an
`always()` aggregate is never an expected conditional skip; the critical path
over a fixture DAG whose timestamps make the *later* of two sibling shards the
gating one; nearest-rank percentiles, including that every reported value
occurred in the sample; the log parser on a failure block, on interleaved
failure and error blocks that both number from one, on unrelated noise that must
not yield a false positive, and on a truncated log that must report low
confidence; that only transient failures are retried and that a persistent
transient failure is never recorded as an expiry; that a listing which serves
the wrong page fails closed rather than losing runs; report determinism, the
floor evaluation, and that a classification with no instance renders as an
explicit zero.

Four cases gate the tracked artifacts themselves: the dataset is classified
evidence meeting its own cohort floors with every enforced run carrying the
reference fingerprint; the cohort spec and the dataset name exactly the same
runs, so a `--refetch` replay cannot drift from the freeze; the committed report
is byte-identical to a fresh render of the committed dataset; and nothing in the
dataset claims a billing figure. The CLI cases cover unknown options, missing
and unreadable inputs, a refused unclassified dataset, a dry run that makes no
call, and a failed collection that leaves an existing dataset untouched with no
temp file beside it.

The fingerprint cases shell out to the real Task 2 generator, because a test
that reimplemented the parse would prove nothing about the comparability rule
the cohort actually used.

### Reproducibility and size

The dataset commits the exact run ids, attempt numbers, head SHAs, PR numbers,
workflow blob SHAs, the fingerprint, the query parameters and a `collected_at`.
`--refetch` re-collects the committed run-id list, and the observed projection
of the two collections is compared byte for byte, with time-dependent evidence
state (artifact expiry, log availability) reported as its own category rather
than as drift.

The proof was executed twice. First on 2026-09-18 at 17:32:39Z against the
dataset collected at 16:58:08Z (556 API calls, 0 transient retries): `runs`
(113) identical; `jobs` (3,642) identical once the three fields the classifier
adds afterwards - `key`, `key_reason`, `matrix` - are excluded; `cohort.resolved`
run ids identical; `provenance` identical. `evidence_state` differed in all 113
entries and only in `checked_at` and the log-probe state, which is the
time-dependent category, not drift.

That proof covered the observed fields, and they have not changed. It also
exposed a limit worth recording: a `--refetch` replays a committed run-id list
and never walks a listing, so it cannot re-derive what the walk passed over. The
first refetch therefore produced a dataset whose skip accounting was empty, and
the report rendered it as "No runs were skipped" - a false statement, and
exactly the silence-reads-as-a-pass failure this record's Task 3 rules against.
A dataset now records its `collection_mode`; a refetch carries the walk's
accounting forward from the dataset it refreshes; and an empty skip list from a
refetch renders as "Not available ... missing evidence, not an empty set".

Because that accounting survived only in prose, the tracked dataset was then
re-collected by a **fresh walk** at 17:59:59Z (447 calls, 0 retries) rather than
patched. That was safe to do only because no new completed run had appeared
since the freeze - the newest live run was still the newest committed one - and
it is verified rather than assumed: a focused test asserts that the tracked spec
and the tracked dataset name exactly the same run ids, and they do, for all
three cohorts.

That sequence produced a stronger proof than the original plan. The observed
projections of two **independent collections taken by different routes** - the
refetch of 17:49:02Z, which replayed the committed id list, and the walk of
17:59:59Z, which re-derived the cohort from the listings - are byte-identical,
SHA-256 `76be4b13293d6093c6cfb8685e3ee952e5972d262a9d8ea3bcfcc35f052b0f90` over
2,430,232 bytes, with zero evidence-state changes. Reproducibility therefore
does not rest on replaying a stored answer: two different ways of asking the
question returned the same frozen facts.

`tools/ci-measurement/dataset-2026-09.json` is about 5.05 MB pretty-printed. The
per-job step **summary** is kept rather than dropped for size: only 119 of 3,642
jobs have a failed step, so removing it would save around 250 KB, and the first
failed step is exactly what separates a setup or infrastructure failure from an
execution failure - dropping it would leave a classification `derived` from
evidence the dataset no longer holds.

### Boundary

No workflow, ruleset, branch rule, product code, release or deployment behaviour
changed. `tools/ci-check-roster.json`, `tools/ci-workflow-inventory.json`,
`bin/generate-ci-workflow-inventory` and `bin/check-ci-roster-conformance` are
untouched, and both frozen gates still exit 0. Every GitHub request was a GET;
nothing was posted, labelled, rerun or cancelled.

The baseline makes **no cadence recommendation**. Whether random-order execution
should run on every pull request, at what shard width, or at all, is Task 8. A
`corroborating` verdict means only that the random-order failure also appeared
in an ordinary shard *on that head*; it is not evidence that random-order
execution has no unique detection value in general, and the one unique detection
in this cohort is the counterexample.

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
- ~~`tools/preflight-gates.json` carries no inventory-drift gate. Drift is
  caught today only by this candidate's architecture test (which CI runs inside
  `ci/unit-tests`). A roster entry would also give
  `bin/refresh-governance-artifacts` — which is driven entirely by that
  manifest's `refresh` metadata — a mechanical repair
  (`php bin/generate-ci-workflow-inventory --write`).~~ **Resolved by Task 3.**
  Both `check-ci-workflow-inventory` (auto refresh) and
  `check-ci-roster-conformance` (manual refresh) are on the roster at `default`
  profile, enforced through the `architecture-test:` surface, so no workflow
  edit was needed.
- **`ci/random-order-shard-N` leaves no machine-readable failure record.** It
  runs PHPUnit with `--no-coverage` and no `--log-junit` and uploads nothing,
  while `ci/test-shard-N` uploads `php-test-shard-N` (JUnit + Clover, 30-day
  retention). The failing-test identity of a random-order shard therefore
  exists only in the job console log, and answering "did random order catch
  something the ordinary shards did not?" requires a log fetch and a text
  parse, which Task 4 does with a versioned parser and a recorded confidence.
  A `--log-junit` on that job would turn the whole question into an artifact
  comparison and make the evidence survive log expiry. Owner: filed separately
  by the orchestrator; it is a workflow edit and this record's Task 4 candidate
  does not make one.
- **A replay cannot re-derive a walk, and an empty result read as a clean one.**
  `--refetch` re-collects a committed run-id list and never paginates a
  listing, so the dataset it produced carried an empty `skipped_runs` and the
  report rendered "No runs were skipped" - false, since the original walk
  passed over ten. The bug was not the empty list but the rendering of it: a
  missing derivation and an empty set are different facts. Datasets now record
  `collection_mode`, a refetch carries the walk's accounting forward from the
  dataset it refreshes, and an empty list from a refetch renders as "Not
  available ... missing evidence, not an empty set". The same shape is worth
  checking wherever a tool can produce a document by two different routes.
  Owner: none outstanding.
- **GitHub can return a completed, failed run with zero job records, and
  `filter=all` does not guarantee every attempt.** Two instances in the Task 4
  pull-request cohort. Run `34298553407` is `completed`/`failure` with
  `total_count: 0` jobs on its only attempt. Run `34298520824` has
  `run_attempt: 2`, and `filter=all` returns only attempt 2's 45 jobs because
  GitHub lists none for attempt 1 - whose own conclusion, read from
  `GET /actions/runs/{id}/attempts/1`, is `action_required`: it never executed,
  which is why it has no jobs. Building run records from the jobs listing alone
  made both runs vanish from `runs[]` and `jobs[]` while staying in
  `resolved.run_ids`, so a resolved run left the evidence silently. The
  collector now emits a run record from the run object itself with
  `jobs_listed: 0` and null durations, names the gap in
  `cohort.incomplete_runs` with a reason, and reads an earlier attempt's
  conclusion from the per-attempt endpoint rather than copying the run-level
  one - which describes the **latest** attempt and would have labelled that
  never-executed attempt a success. A focused test asserts that every resolved
  id has a first-attempt record or an `incomplete_runs` entry. Owner: none
  outstanding; recorded because the same assumption ("a completed run has
  jobs", "`filter=all` means every attempt") is easy to make again.
- **A GitHub run-listing `page=1` request was observed returning page 2's
  content.** During Task 4 collection the `main_push` cohort scanned 500 runs
  across five pages, kept none, and silently lost its hundred most recent runs;
  exit status, call count and page count all looked healthy, and only the run
  ids exposed it (500 scanned, 400 distinct — one page served twice). The
  collector now asserts that a run listing strictly advances — page N+1's
  highest run id below page N's lowest, and no run id twice in one cohort —
  refetches a page that does not, and fails closed naming the page rather than
  freezing a cohort with a hole in it. Anything else that paginates the Actions
  API in this repository is exposed to the same behaviour and has no such
  guard. Owner: whoever next writes a paginated Actions-API walk.
- **Billed runner minutes are structurally unavailable to this tooling.**
  `GET /actions/runs/{id}/timing` reports `duration_ms: 0` per job on a public
  repository, and organization billing needs an `admin:org` scope the
  authenticated token does not hold. Every cost figure in the Task 4 baseline
  is therefore summed job-wall seconds weighted by GitHub's published per-OS
  multipliers, labelled a **cost proxy**. Any future claim about runner spend
  needs a different evidence source, not a re-reading of these numbers. Owner:
  Task 8, before any cadence decision that rests on cost.
- **The Windows development host lacks `ext-fileinfo` and `ext-zip`.** `vendor/`
  installs only with `--ignore-platform-req=ext-fileinfo
  --ignore-platform-req=ext-zip`, and the Task 4 classifier consequently opens
  artifact zips through an external extractor — the Windows system `tar.exe`
  (bsdtar, under `%SystemRoot%/System32`) first and `unzip` second, recording
  which one worked. Git Bash's GNU `tar` cannot read a zip and is skipped by
  the probe order. This is a host observation, not a framework requirement;
  nothing in `composer.json` changed.
  Owner: whoever next provisions a Windows contributor host.
- **`ci.yml` declares no skippable job-level condition.** Three of its 41 jobs
  carry an `if`, and all three are `always()` aggregates — which, by the
  inventory's own derivation rule, is the one condition that *cannot* skip a
  job. Every skip observed in the Task 4 cohort is therefore a
  prerequisite-starved skip, never an expected conditional one, and the
  `expected_conditional_skip` term of the measurement vocabulary has no
  instance in this repository's `ci.yml`. The same holds for
  `publication_only`: `ci.yml` and `nightly.yml` contain no job the inventory
  labels `publication`. Both terms are reported as explicit zeros rather than
  omitted. Owner: nobody yet; it is a fact about the workflow, recorded so a
  later reader does not mistake a zero for a gap in the pass.
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
- ~~**Packagist publish/verify logic is duplicated four ways** —
  `split.yml:563-651`, `packagist-register.yml:31-75`,
  `packagist-recover.yml:46-196`, `sync-skeleton.yml:72-98` — and the P2
  polling loop three ways: `split.yml:359-420`,
  `packagist-update.yml:118-148`, `packagist-recover.yml:114-162`. Owner: new
  issue; consolidate before any new CI machinery depends on it.~~ **Resolved by
  #3091.** Submission and exact-tag P2 verification now have one implementation
  each behind local composite actions. The release, registration, recovery, and
  skeleton call sites retain their distinct create and retry policies through
  explicit inputs, and both actions have a no-network dry-run contract.
- ~~**`packagist-update.yml` documents itself as ad-hoc** (`:11-14`) but triggers
  on `push: tags: ['v*']` (`:38-40`), duplicating `split.yml`'s
  `verify-packagist` on every tag. Either the prose or the trigger is wrong.
  Owner: new issue.~~ **Resolved by #3091.** The standalone verifier is
  `workflow_dispatch` only; tag-push verification remains ordered inside
  `split.yml`.
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
- **An empty `selection.selectors` list silences CRC012 and CRC013.** The
  verifier's unconditional guard requires at least one leaf and its leaf loop
  has nothing to iterate, so a producer with `selectors: []` (or no `selection`
  key) produces no selection finding. Today `CiCheckRosterManifestTest`
  rejects an empty composition ("selector composition must not be empty"), so
  the pair of gates is sound, but that clause has no dedicated negative fixture
  and CRC030 does not cover this shape. Found in the Task 3 delta re-review.
  Owner: the next slice that touches CRC030 — add a fourth clause and a roster
  fixture.

### Filed as GitHub issues (2026-09-18)

The items above that stand on their own, and the ones the hygiene sweep
flagged as "clean before adding hosted CI machinery", were mirrored as
issues after Task 3. The ledger remains the portable authority; the issues
are the forge adapter.

- waaseyaa/framework#3090 — `timeout-minutes` on every job, release pipeline first.
- waaseyaa/framework#3091 — Packagist submit/verify consolidation and the
  standalone-verifier trigger correction; delivered as the final ordered
  clean-first prerequisite before Task 5.
- waaseyaa/framework#3092 — explicit least-privilege `permissions:` in the nine
  default-token workflows; narrow `admin-dist.yml` / `auto-merge.yml`; review
  the `sync-skeleton.yml` force-push.
- waaseyaa/framework#3093 — pin floating `ubuntu-latest` runners; reconcile
  action version comments.
- waaseyaa/framework#3094 — name the ten unnamed jobs; resolve the duplicate
  `Publish GitHub Release` context; one `ci.yml` naming convention — before
  ruleset migration (Tasks 6–7).
- waaseyaa/framework#3095 — an owning spec for CI structure and its routing
  in `CLAUDE.md` and `tools/drift-detector.sh`.
- waaseyaa/framework#3096 — host-aware preflight gates on Windows (the five
  POSIX-only gates that block pre-push on this host).

Not filed, by design: items owned by a numbered #3087 task (object-matrix
naming → Task 6; publish-on-`always()` shapes and the two gates that cannot
fail → Task 3/5 disposition; `merge_group` → the recorded design decision),
the fixture-only generator paths, the third-party publishing-action note,
the retention-policy note, and the empty-`selectors` verifier gap, which
stays with the next CRC030 change.

## Ordered residual work

0. Evidence freeze and external benchmark: complete.
1. Governance contract and schema repair: complete.
2. Inventory generator: complete.
3. Offline conformance verifier: complete. Task 3a (policy repair from
   inventory evidence) landed first as its own reviewed slice; the verifier was
   written against the repaired policy afterwards.
4. Measurement baseline under #2869: this candidate.
5. Stable aggregate shadowing.
6. Ruleset projection and live audit.
7. Ruleset migration.
8. Cadence optimization.
9. Final reconciliation.
