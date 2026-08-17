# CI test selection and random-order sharding

Status: LIVE. Anchor: #2404 slice 1 (final bullet) and slice 3.

This spec owns how blocking CI decides *which* PHPUnit files run in the
`ci/random-order` proof, how that selection is sharded, and what evidence the
decision must publish. It does not own the single-execution coverage proof
(`ci/unit-tests`, `ci/coverage`) landed by #2405 — that contract lives in
[governed-gates.md](governed-gates.md) §4 and `bin/build-phpunit-shards`.

## 1. Why selection exists

`ci/random-order` executed the complete configured inventory on every pull
request and, after #2405 removed suite triplication, became the measured
critical path at 4m50. Random order proves order-independence; it does not
prove coverage, which the shard matrix already establishes independently.
That asymmetry is what makes bounded selection admissible here and nowhere
else.

Selection is **fail-closed**: the selector's job is to identify what is
*provably* bounded. Everything it cannot prove runs the complete inventory.

### Measured value, recorded honestly

These figures are the **shipped selector** replayed against the 40 most
recent merge commits to `origin/main` as of 2026-08-16, weighted by
`tools/phpunit-timings.json` (1,812 of 1,840 inventory files timed; untimed
files contribute 0s, a negligible skew). Each merge is classified with
`ros_select($root, "<merge>^1", "<merge>", null)` using the **current**
manifest, package graph, and `phpunit.xml.dist` against that merge's real
`git diff`; this does not check out each historical commit, so it measures
"the selector as it exists today, given historical change shapes" rather than
a true historical replay:

| Quantity | Value |
|---|---|
| always-run floor | 68% of recorded inventory seconds |
| mean selected | 87% |
| median selected | 100% |
| full-suite fallback | 19 of 40 runs (48%) |

The floor is high because `tests/Architecture` (143.0s, 40% of the inventory)
is repo-state contract testing that shells out to `bin/` scripts and cannot be
attributed to any package, and because atomic-group expansion (§3.4) pins a
whole group when any one member is unattributable.

These replace the pre-implementation model's numbers (68% / 85% / 100% / 17
of 40), which this repository's own drift-prevention discipline forbids
publishing as measured once a real implementation exists to measure. The
model was close on floor and median but underestimated the full-suite
fallback rate. Before three manifest fixes — bounding the mandatory-per-PR
root docs (`CHANGELOG.md`, `README.md`, `AGENTS.md`, `CLAUDE.md`,
`MAINTAINERS.md`, `SECURITY.md`, `SUCCESSION.md`, `UPGRADING.md`), letting
`packages/<p>/**` fall back to a manifest prefix match when
`packages/<p>/composer.json` is absent by design instead of forcing `full`
outright (`packages/admin/`, the Nuxt admin SPA, has no `composer.json`), and
bounding `.symfony-import-allowlist.json` — the shipped selector actually
fell back to `full` on 36 of these same 40 merges — the model's rules and the
shipped manifest's rules were never the same rules. After the fixes, 19 of 40
remain `full`, all of them either genuine self-protection triggers
(`composer.json`, `composer.lock`, `phpunit.xml.dist`, `.github/workflows/ci.yml`,
a `packages/*/composer.json`) or a path this manifest genuinely does not
recognize (`scripts/deploy.sh`), not a manifest gap.

That residual characterization is scoped to the measured 40-merge window.
Replaying the same shipped selector over the 150 most recent merges to
`origin/main` surfaces a wider tail of genuinely unclassified paths the
40-merge window does not contain:

| Path | Occurrences (of 150) |
|---|---|
| `.gitignore` | 13 |
| `skeleton/**` (`skeleton/composer.lock`, `skeleton/.dockerignore`, `skeleton/.claude/rules/waaseyaa-framework.md`, `skeleton/tests/Integration/.gitkeep`) | 6 |
| `phpstan-baseline.neon` | 3 |
| `scripts/deploy.sh` | 1 |
| `scripts/release.sh` | 1 |
| `.spectral.yaml` | 1 |
| `lefthook.yml` | 1 |
| `.githooks/pre-push` | 1 |
| `ci/packagist/validate-composer.yml` | 1 |

This is a **yield** gap, not a safety gap: fail-closed means an unclassified
path costs a full run, never a correctness failure. Widening the manifest to
cover these paths is deliberately deferred rather than done inside this fix
loop, so each new bounded entry gets its own considered rationale (what does
and does not read it, whether `tests/Architecture` already proves it) instead
of being added reactively to shrink a residual list.

**Selection therefore buys roughly 13% of compute. The 3-way shard matrix
(§5) buys the wall-clock reduction.** Both are specified here because #2404
slice 3 requires evidence-based selection regardless of its standalone yield,
and because the selection document is the input that later slices consume.

Do not re-litigate this by widening selection. Two alternatives were measured
and rejected:

- **File-level intersection without atomic expansion** — floor 45%, mean 77%.
  Rejected: a selected file can then land in a process without the group
  fixtures whose state it shares.
- **Atomic groups computed as reference-connected components** over
  test-support class references — viable within `tests/` (77 directories to 54
  components) but degenerate globally: including `packages/` ↔ `tests/` edges
  collapses 1140 files into one component. Rejected as unstable.

The atomic group is the group `bin/build-phpunit-shards` already uses:
`packages/<name>`, `tests/<TopDir>`, or `tests`.

## 2. Invariants

1. `main` pushes and the nightly proof always run the complete inventory.
2. The nightly proof runs **unsharded**, restoring the cross-shard interaction
   coverage that a matrix necessarily drops.
3. Any ambiguity, parse failure, unknown path, or absent evidence selects the
   complete inventory.
4. A change that can influence selection selects the complete inventory (§3.1).
5. `ci/random-order` remains a single required status context. Its name is
   fixed by the `main-protection` ruleset and must not change.
6. Coverage, security, architecture, governance, and spec-drift gates are
   untouched by this spec and remain unconditional.

## 3. Selector — `bin/select-random-order-scope`

```
php bin/select-random-order-scope --base=<sha> [--head=<sha>] [--root=<dir>]
php bin/select-random-order-scope --mode=full
```

Emits a selection document (§4) on stdout. Exit 0 on a decision of either
mode; exit 2 only on usage error. **An internal failure is never an error
exit — it is a `full` decision with a recorded reason.**

### 3.1 Self-protection

The selector must not be able to narrow the testing of a change that could
alter the selector. The following changed paths force `mode: full` before any
other classification runs:

- `bin/select-random-order-scope`, `bin/build-phpunit-shards`,
  `bin/test-random-order`
- `tools/random-order-scope-manifest.json`, `tools/phpunit-timings.json`
- `phpunit.xml.dist`, `phpunit.xml`
- root `composer.json`, `composer.lock`
- any `packages/*/composer.json`
- `.github/workflows/ci.yml`, `.github/workflows/nightly.yml`

This list is itself part of the manifest and covered by an architecture test,
so adding a selector input without adding it here fails CI.

### 3.2 Diff acquisition

Changed paths come from `git diff --name-status -z <base>...<head>`, parsed
as NUL-delimited records. Rename (`R<score>`) and copy (`C<score>`) records
carry two paths; **both the old and the new path are classified**, and the
union of their effects applies. A rename that moves a file across a group
boundary therefore seeds both groups.

Deletion (`D`) records classify the deleted path. A deleted
`packages/<p>/composer.json` forces `full` — the dependency graph the closure
would be computed from no longer describes the parent commit.

Forces `full`: absent or empty base, a base that is not an ancestor reachable
in the local clone, a non-zero `git diff` exit, unparsable `-z` output, or an
empty changed-path set.

### 3.3 Path classification

Applied in order; first match wins.

| Changed path | Effect |
|---|---|
| any §3.1 self-protection path | **full** |
| `packages/<p>/**` where `packages/<p>/composer.json` parses and declares a `name` | seed package `<p>` |
| `packages/<p>/**` where `packages/<p>/composer.json` is absent or unparsable | bounded if a manifest prefix matches, otherwise **full** — package absent from the graph |
| `tests/**` resolvable to packages by §3.5 import mapping | seed those packages |
| `tests/**` not resolvable | no seed; the file's group is already in the always-run set |
| a prefix declared in `tools/random-order-scope-manifest.json` | bounded; seeds whatever that entry declares |
| anything else | **full**, recording the offending path |

`tools/random-order-scope-manifest.json` declares bounded prefixes with a
mandatory `rationale` and an optional `seeds` list, following the discipline
of `tools/getquery-bindings-baseline.txt`. The selector forces `full` when the
manifest is absent, unparsable, declares a prefix that is a proper prefix of
another declared prefix (ambiguous), declares a seed package that does not
exist, or contains an entry without a rationale.

### 3.4 Closure and expansion

1. **Consumer closure.** Transitively close the seed set over reversed
   internal `require` **and** `require-dev` edges from every
   `packages/*/composer.json`. `require-dev` is included because this
   repository permits upward dev edges (`bin/audit-require-dev-layers`), so a
   lower-layer package's tests can exercise a higher-layer fixture.
2. **Graph integrity.** Force `full` on any unparsable `packages/*/composer.json`,
   any duplicate package `name`, or an internal `waaseyaa/*` constraint naming
   a package absent from the monorepo. A package directory without a manifest
   is excluded from the dependency graph outright (it can seed and close
   nothing); at the classification layer (§3.3) a changed path under such a
   directory falls back to a manifest prefix match, and only forces `full`
   when no prefix bounds it — this is how `packages/admin/` (the Nuxt admin
   SPA, no `composer.json` by design) stays out of the always-`full` set.
   Cycles are permitted — the repository has five accepted same-layer
   2-cycles (`tools/package-layers-cycle-baseline.txt`) — and the traversal is
   visited-set bounded, so a cycle is not an error.
3. **File selection.** Select every inventory file in a closure package, every
   always-run file, and every mapped `tests/**` file whose packages intersect
   the closure.
4. **Atomic expansion.** Expand each selected file to its **complete group**
   (`packages/<name>`, `tests/<TopDir>`, `tests`). Selection is therefore
   group-granular, and a group's fixture state can never be split across
   processes. This is what raises the floor from 45% to 68%, and it is
   deliberate.

### 3.5 Always-run set and import mapping

The always-run set is **computed, never recorded**, so it cannot go stale: any
inventory group containing a file that cannot be attributed to a package is
always run.

Attribution of a `tests/**` file maps each `use Waaseyaa\…` import to the
longest matching PSR-4 prefix from `autoload` and `autoload-dev` across all
`packages/*/composer.json`. Mapping fails closed — the file is treated as
unattributable, pinning its group into the always-run set — when the file is
unreadable or when a `Waaseyaa\…` import matches no declared PSR-4 prefix.

`ros_attribute()` also carries a guard for two prefixes of equal length
matching the same import. The guard is defensive, not reachable: PSR-4
prefixes are unique PHP array keys (`$psr4[$namespace] = $package`), so two
*different* namespace strings can never both be same-length literal prefixes
of one import string — a longer or shorter match always exists, or the two
candidate namespaces are identical. The check costs nothing and fails safe,
so it stays, but it is not a condition this selector can currently produce.

## 4. Selection document

```jsonc
{
  "schema_version": 1,
  "mode": "full" | "targeted",
  "fallback_reason": null | "<path or condition that forced full>",
  "base_sha": "<40-hex>",
  "head_sha": "<40-hex>",
  "digests": {
    "manifest": "sha256:…",        // tools/random-order-scope-manifest.json
    "composer_graph": "sha256:…",  // root + every packages/*/composer.json, sorted
    "phpunit_config": "sha256:…",  // phpunit.xml.dist
    "selector": "sha256:…"         // bin/select-random-order-scope
  },
  "changed_paths": ["…"],
  "seed_packages": ["…"],
  "closure_packages": ["…"],
  "always_run_groups": ["…"],
  "selected_groups": ["…"],
  "selected_paths": ["…"],
  "selected_files": 1560,
  "inventory_files": 1839
}
```

Output is deterministic: every list sorted, byte-identical across repeated runs
on an unchanged tree.

## 5. Plan — `bin/build-phpunit-shards --only=<selection.json>`

Extends the #2405 planner. Behaviour with `--only`:

- Load the selection document; refuse (exit 2) on a schema mismatch, or on a
  `selected_paths` entry absent from the discovered inventory.
- Restrict the inventory to `selected_paths`, then **re-expand to complete
  groups** before balancing. The planner, not the selector, is the authority
  on group membership.
- **Suite assignment is total and unique.** Every retained path is resolved to
  exactly one `phpunit.xml.dist` testsuite. A path in zero suites, or in two,
  is fatal. This is currently satisfiable — 1839 configured files, 0 multiply
  assigned — and guards a live hazard: `packages/analytics/tests` and
  `packages/oauth-provider/tests` are whole-tree `Unit` directories, so either
  gaining a `tests/Integration/` subdirectory would double-assign against
  `packages/*/tests/Integration`.
- Set `mode: "targeted"`; without `--only`, `mode: "full"` as today.

The plan document gains `selection` (the embedded selection document),
`seed`, `phpunit_version`, and per-shard `suites` — the shard's paths grouped
by resolved suite. Shards are declared for every matrix leg; a leg with no
paths is emitted with an empty `paths` list and an explicit
`"empty": true`, so a matrix leg is never silently dropped.

## 6. Runner — `bin/test-random-order --plan=<plan.json> --shard=<id>`

Replay reads the **saved plan**, never a freshly computed selection, so a
replay after the working tree changes reproduces the original file set.

For the named shard, the runner executes one PHPUnit process per suite that
has paths, in fixed suite order, passing that suite's explicit paths with
`--order-by=random --random-order-seed=<plan seed>`. Per-suite processes are
retained for the memory reason documented in the existing runner. An empty
shard succeeds without invoking PHPUnit and says so.

The shared seed gives **deterministic shard replay**. It is explicitly *not*
equivalent to one global randomized ordering over the whole inventory — no
sharded run can be. The nightly unsharded proof (§7.2) supplies that.

## 7. Workflows

### 7.1 `ci.yml`

```
prepare-random-order-plan   needs: [support-contract, spec-drift]
  checkout fetch-depth: 0
  pull_request → --base=${{ github.event.pull_request.base.sha }}
  push / dispatch → --mode=full
  → bin/build-phpunit-shards --shards=3 --only=… --seed=…
  → composer install, tar vendor, record sha256
  → single artifact `random-order-plan` bundling scope, plan, vendor tar,
    and both sha256 files — one upload, not two, so plan and vendor
    cannot desync across independently-uploaded artifacts
  → ::notice mode, fallback_reason, selected_files/inventory_files

ci-random-order-shard (matrix id: [1,2,3])   needs: [prepare-random-order-plan]
  → download both artifacts, verify vendor tarball sha256, extract
  → integrity gate (§7.3); on failure, locked composer install instead
  → bin/test-random-order --plan=… --shard=${{ matrix.id }}

ci/random-order    needs: [prepare-random-order-plan, ci-random-order-shard]
                   if: always()
```

### 7.2 `nightly.yml`

Daily `schedule` plus `workflow_dispatch` with an optional `seed` input for
manual replay. One job runs the complete **unsharded** `composer test:random`.
The seed is date-derived when not supplied, and always logged. The workflow
declares its own `concurrency` group so overlapping nightlies do not stack,
uploads JUnit logs on failure, and holds **no deployment, release, split, or
external-state authority** of any kind.

### 7.3 Dependency preparation

A persistent `vendor/` cache is rejected. Evidence: `vendor/waaseyaa/*` is a
tree of relative symlinks into `packages/*`, and a working tree can accumulate
dangling entries for renamed or removed packages (`admin-bridge`,
`agent-output`, `northcloud` were all observed dangling against 75 linked
entries for 77 packages). Today's `ci-random-order` job compounds this with
`restore-keys: composer-v2-`, a broad prefix that can restore a `vendor/` tree
built from a *different* `composer.lock`; it is safe only because the job then
runs `composer install`. Dropping that install while keeping the prefix would
ship a stale autoload map.

Instead, dependencies are prepared **once per run** in
`prepare-random-order-plan` and passed as a run-scoped artifact:

- `composer install --no-interaction --prefer-dist` from the exact checkout.
- `tar` the `vendor/` tree preserving symlinks; record the archive SHA-256 in
  the plan document.
- Each shard verifies the SHA-256 before extraction and refuses a mismatch.

After extraction each shard runs an integrity gate, and on any failure
discards the artifact and performs a locked `composer install` instead:

- `composer check-platform-reqs` passes (PHP version and extension profile).
- `vendor/composer/installed.php` hashes to the value recorded by the
  preparing job.
- every `vendor/waaseyaa/*` symlink resolves to a `packages/*/composer.json`
  present in **this** checkout — the exact-checkout binding that a persistent
  cache cannot prove.

If a persistent cache is ever reintroduced, its key must pin runner image,
PHP version, extension profile, Composer version, and the hashes of both
`composer.json` and `composer.lock`, with **no broad restore keys**.

### 7.4 Aggregator contract

`ci/random-order` publishes the required context. `if: always()` alone is
insufficient — it would publish success after skipped shards. The job fails
unless both of:

- `needs.prepare-random-order-plan.result == 'success'`
- `needs.ci-random-order-shard.result == 'success'` — GitHub's aggregate
  result for a `fail-fast: false` matrix job, which is `success` only when
  every leg that ran succeeded. Any `skipped`, `cancelled`, or `failure` leg
  makes the aggregate non-`success`, which fails the context.

This proves "every leg that ran, succeeded." It does **not** prove "there
were exactly three legs" — GitHub's `needs.<matrix-job>.result` aggregate has
no way to express leg count, only leg outcome, so a runtime check for shard
count would be comparing the workflow to itself and could never fail. Matrix
width is pinned instead at the workflow-definition level: an Architecture
test (`CiSingleExecutionProofTest::randomOrderPreparesOnceAndFansOutToThreeShards`)
asserts `id: [1, 2, 3]` on the `ci-random-order-shard` matrix declaration. If
that matrix is ever resized, both the test and this note must change
together — there is no automated cross-check between the declared width and
this text.

## 8. Test matrix

Selector (`tests/Architecture/RandomOrderScopeSelectorTest.php`):

- selector, manifest, shard planner, runner, `phpunit.xml.dist`, root and
  package `composer.json`, `composer.lock`, `ci.yml`, `nightly.yml` — each
  forces `full`
- rename within a package; rename across package boundary; rename between a
  package and the repository root — both paths classified
- deleted file; deleted `packages/*/composer.json`
- unknown root path; empty diff; absent base; unreachable base
- docs-only change
- hub closure (`entity` → 63 packages) and leaf closure (`genealogy` → 1)
- malformed `composer.json`; duplicate package `name`; internal constraint on
  an absent package; accepted 2-cycle traverses without error
- ambiguous manifest prefix; manifest entry lacking a rationale; manifest
  seed naming an absent package
- `tests/**` import mapping: attributed, unattributable, equal-length
  ambiguous prefixes
- atomic expansion pulls a group's shared-fixture members in with a selected
  member
- byte-identical output on repeated runs

Planner (extend `PhpUnitShardPlannerTest`):

- `--only` restricts, then re-expands to complete groups
- refusal on a selected path absent from the inventory
- multiply assigned path is fatal
- the zero-suite guard is defensive, not reachable: the planner's own
  discovery loop and `ros_inventory()` parse the same `phpunit.xml.dist`
  with the same `Test.php`-suffix and skip-link filters, so their key sets
  are identical by construction. The check costs nothing and fails safe, so
  it stays, but it is not a condition the planner can currently produce,
  and it carries no test.
- empty matrix leg is emitted explicitly, not dropped
- determinism; `mode: targeted`

Runner (extend `RandomOrderRunnerTest`):

- `--plan`/`--shard` partitions by suite and propagates the plan seed
- replay from a saved plan after the working tree changes reproduces the
  original file set
- empty shard succeeds without invoking PHPUnit
- refusal on unknown shard id or malformed plan

Workflow (extend `CiSingleExecutionProofTest`, `CiContractOrderingTest`; new
`NightlyRandomOrderProofTest`):

- job graph and `needs:` ordering; `ci/random-order` name preserved
- aggregator fails on skipped, cancelled, absent, and failed shards
- vendor artifact: missing, corrupt digest, and wrong-checkout symlink each
  fall back to a locked install
- nightly is unsharded, complete, seed-overridable, concurrency-guarded,
  uploads failure artifacts, and holds no deployment authority

## 9. Evidence

`prepare-random-order-plan` publishes mode, fallback reason, seed packages,
closure size, selected-vs-inventory counts, shard membership, timing inputs,
and the plan digest as job notices and a retained artifact — satisfying
#2404 slice 3's evidence requirement.

Acceptance for the slice requires median and p95 critical-path time plus
runner-minutes from at least 10 comparable runs, one green pull-request run,
one green post-merge `main` run, and one green complete nightly proof.

## 10. Non-goals

Exact-head archive reuse (#2404 slice 2), immutable artifact promotion
(slice 5), and any change to coverage thresholds or the required-check roster
are out of scope. No release, split, or deployment behaviour is touched.
