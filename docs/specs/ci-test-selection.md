# CI random-order sharding and the rejected selection design

Status: LIVE. Anchor: #2404. The issue stays open — its original changed-package
selection proposal was investigated, measured, and rejected; §3 preserves that
investigation as architectural evidence rather than deleting it.

This spec owns how blocking CI shards the `ci/random-order` proof, how the
single run-scoped Composer dependency artifact both PHPUnit matrices consume
is prepared and verified, and what the nightly unsharded proof restores. It
does not own the single-execution coverage proof (`ci/unit-tests`,
`ci/coverage`) — that contract lives in
[governed-gates.md](governed-gates.md) §4 and `bin/build-phpunit-shards`'s
`--shards=4` plan for `ci-test-shards`, which this file shares the planner
with but does not itself govern.

## 1. Why sharding exists

`ci/random-order` executes the **complete** configured PHPUnit inventory
(currently ~1,840 files across 74 package-safe groups, per
`phpunit.xml.dist`) split across shards so wall time stays bounded, then
replays each shard with `--order-by=random` under one shared, logged seed.
Random order proves order-independence; it does not prove coverage, which the
`ci-test-shards` matrix already establishes independently via JUnit/Clover
evidence.

An earlier design (#2404's original proposal) additionally tried to reduce
compute by selecting a *subset* of the inventory based on which packages a
pull request's changed paths touched. That selector was built, measured
against real merge history, and ultimately removed after the measurement
itself was found to rest on an unsound dependency graph. **§3** preserves the
investigation in full because it is real architectural evidence — a
considered, evidence-based "no" — not something to quietly delete. What ships
today shards the complete inventory unconditionally; there is no subset, no
selection document, and no changed-path classification anywhere in this
pipeline.

Two independent PHPUnit matrices exist in `ci.yml`, sharing one planner
(`bin/build-phpunit-shards`) and one dependency artifact (§7.3), but serving
different purposes:

- **`ci-test-shards`** (4 shards, deterministic order, JUnit + Clover
  evidence) — the single-execution coverage proof, owned by
  [governed-gates.md](governed-gates.md), not this file.
- **`ci-random-order-shard`** (2 shards, `--order-by=random`, one shared
  seed) — the order-independence proof this file specifies.

## 2. Invariants

1. `ci/random-order` executes the complete configured inventory on every run
   — pull request, `main` push, and dispatch alike. There is no narrower
   mode.
2. The nightly proof (§7.2) runs the same complete inventory **unsharded**,
   restoring the cross-shard interaction coverage a matrix necessarily drops.
3. `ci/random-order` remains a single required status context. Its name is
   fixed by the `main-protection` ruleset and must not change.
4. Package-safe grouping: no atomic group's files
   (`packages/<name>`, `tests/<TopDir>`, or `tests`) are ever split across
   shards or across the per-suite processes within a shard, so a group's
   shared fixture/setup state is never divided.
5. Suite assignment is total and unique: every discovered test file resolves
   to exactly one `phpunit.xml.dist` testsuite, or planning fails closed
   (exit 2) — see §5.
6. Coverage, security, architecture, governance, spec-drift, and the hosted
   FrankenPHP worker-runtime lane (`ci/frankenphp-worker`) are untouched by this
   spec and remain unconditional.

Skip classification is separately governed by
[`phpunit-skip-governance.md`](phpunit-skip-governance.md). Sharding never
turns a required-hosted test into an optional one: the hosted fast gate blocks
merge on the governed inventory, and a required transport proof contains no
skip call site.

## 3. Rejected design: changed-package selection

This section is not an apology; it is the record of a real investigation
that should inform anyone tempted to re-propose targeting.

**Modelled saving.** Before any selector existed, a pre-implementation model
predicted a 23% compute saving from changed-package targeting. Once atomic
group expansion was folded into the model — the rule that selecting one file
pulls its whole package/test-group along, so a group's shared fixture state
is never split across processes — the modelled saving revised down to 13%.
That 13% is also what the shipped selector then measured.

**The always-run floor was structurally high.** `tests/Architecture`
(143.0s, 40% of the inventory at the time of measurement) is repo-state
contract testing that shells out to `bin/` scripts and cannot be attributed
to any single package, so it — and every group atomic-expansion pinned
alongside an unattributable file — was always run regardless of what
changed. That alone put the always-run floor at 68% of recorded inventory
seconds.

**The shipped selector's measured yield.** Replayed against the 40 most
recent merge commits to `origin/main`, weighted by real PHPUnit timing
evidence: mean 87% of the inventory selected — this is the figure the
shipped selector itself recorded in the spec this section replaces; an
independent recomputation using a slightly different `tests/` attribution
method gave 89%, so treat the figure as accurate to about two points —
median 100% (most merges touched enough self-protection or unclassifiable
surface to force a full run), full-suite fallback on 19 of 40 runs (48%).

**The decisive finding.** `packages/*/tests` carried undeclared cross-package
test references that the selector's `require`/`require-dev` consumer-closure
computation never saw — a raw scan first estimated 53 such pairs; a
tokenizer-based re-derivation, hand-verified against real code before
touching any manifest, corrected that to **73 real pairs across 26
packages** (the enforcement that now keeps this honest, PL010, ships in this
same revision — `bin/check-package-layers`). The selector's measured yield
above rested on a dependency graph that did not contain these edges, which
means the closure it computed was smaller — and the "saving" it reported
larger — than the real reachability of a change through the *actual* test
suite.

**Measured, 2026-08-17.** Once the 73 edges were declared, the closure
effect was recomputed directly rather than modelled: build the reverse
dependency graph over internal `require` + `require-dev` edges, once from
`git show d69445f60:packages/*/composer.json` (the commit before PL010 —
the unsound graph the selector was actually measured against) and once from
the current worktree (the sound graph with the 73 edges declared), then
compute the transitive consumer closure for every one of the 76 packages:

| | mean closure | median closure | packages closing over >50% of the graph |
|---|---|---|---|
| before (pre-PL010, unsound) | 21.2 | 4 of 76 | 21 / 76 |
| after (73 edges declared) | 44.4 | **64 of 76** | 52 / 76 |

This is not that the hub packages got worse: `foundation` and `entity` were
already hubs, closing over 63 of 76 packages *before* the declarations, and
move only to 64 after. What changed is the **median** package — from 4 to
64 — meaning packages that looked bounded under the old, undeclared-edge
graph turn out to be hubs once their real test dependencies are visible.
`genealogy` staying at 1 both before and after shows genuine leaves remain
leaves; the shift is not indiscriminate, it is specifically the packages
whose tests reach outward that moved. `packages/foundation` alone accounts
for 20 of the 73 declared edges, all upward test-only references (to `api`,
`cli`, `graphql`, `mcp`, `ssr`, and others); because every package in the
framework depends on `foundation` (it is the Layer 0 base), any change
reaching a package `foundation`'s tests reference now pulls `foundation`
into the closure, which then pulls in essentially the whole consumer graph —
this is most of why the median moved so far. That 20-edge upward coupling is
recorded here as a known layering smell for a future issue, not fixed by
this revision: declaring it made CI honest about test coupling that already
existed, it did not endorse the coupling itself.

**What this implies for selection yield is modelled, and cannot be
re-measured.** The selector itself was deleted in this same revision (§2),
so there is no tool left to re-run against the 40-merge window with the
sound graph — the 87%/89% figures above are the last real measurements this
selector will ever produce. Applying the same closure-to-selection-fraction
relationship the pre-revision spec used, a median closure of 64 of 76
packages implies mean selection of roughly **96%** of the inventory — a real
saving near **4%**, not the 13% the unsound graph reported. That 96% is a
**modelled** projection from the measured closure numbers above, not an
independently measured selection fraction; it is an order-of-magnitude
estimate of what the selector would have yielded had it run against the
sound graph, not a source of truth on its own.

**Two grouping alternatives were also measured and rejected**, independent
of the graph-soundness problem above:

- **File-level intersection without atomic expansion** — floor 45%, mean
  77%. Rejected: a selected file can land in a process without the group
  fixtures whose state it shares.
- **Atomic groups computed as reference-connected components** over
  test-support class references — viable within `tests/` alone (77
  directories collapse to 54 components) but degenerate globally: including
  `packages/` ↔ `tests/` edges collapses 1,140 files into one component.
  Rejected as unstable.

**Conclusion.** A real saving near 4% is smaller than ordinary CI wall-clock
variance and does not earn a fail-closed selector's implementation
complexity, its ongoing risk of under-selecting as the graph drifts, or the
trust burden of a gate whose entire safety case depends on a dependency graph
staying exhaustively declared forever. `ci/random-order` now shards the
complete inventory unconditionally instead.

## 4. The measurement gate: two shards versus three

`ci-random-order-shard` ships at two shards (`id: [1, 2]`). A move to three
shards is a **later, separate decision**, not a pending default. It is
decided by the same ≥10-comparable-run measurement discipline this spec
already required for the (now-removed) selector, but the question it answers
has changed: it no longer validates whether targeting works — there is no
targeting — it decides shard count for the complete-inventory proof, and it
measures **total compute**, not selection yield.

The criterion: **three shards is adopted only if measurement shows the
shared-artifact overhead of a third leg (a third download, extraction, and
integrity-gate pass of the same `vendor-archive`) reduces *both* wall-clock
critical-path time *and* total runner-minutes** relative to two shards. A
third shard that improves wall time while increasing total runner-minutes
(or vice versa) does not clear the bar — both must move in the right
direction. See `.github/workflows/ci.yml`'s `ci-random-order` aggregator
comment and `tests/Architecture/CiSingleExecutionProofTest.php`'s
`randomOrderPreparesOnceAndFansOutToTwoShards()`, which pins the matrix at
`id: [1, 2]` until that measurement changes it.

## 5. Shard planner — `bin/build-phpunit-shards`

```
php bin/build-phpunit-shards --timings=<json> [--shards=N] [--seed=N] [--root=<dir>] [--pretty]
```

No `--only` and no embedded selection document — both were removed with the
selector. The planner always operates over the complete inventory.

1. **Discovery.** Parses `phpunit.xml.dist`'s `<testsuites>`, resolving each
   `<directory>` glob and `<file>` entry to real, non-symlinked `*Test.php`
   paths. An empty discovered inventory is fatal (exit 2).
2. **Suite assignment — total and unique.** `ros_inventory()`
   (`bin/lib/phpunit-inventory.php`) maps every discovered path to exactly
   one suite name; a path resolving to two suites is fatal (exit 2, naming
   both). This guards a live hazard: `packages/analytics/tests` and
   `packages/oauth-provider/tests` are whole-tree `Unit` directories, so
   either package gaining a `tests/Integration/` subdirectory would
   double-assign against `packages/*/tests/Integration`. The planner's own
   discovery loop cross-checks its file set against `ros_inventory()`'s —
   defensive, not currently reachable, since both parse the same config with
   the same filters.
3. **Atomic grouping.** `ros_group_of()` assigns each path to
   `packages/<name>`, `tests/<TopDir>`, or `tests`. Timing weight is summed
   per group from `tools/phpunit-timings.json` (missing entries fall back to
   the median of known timings in this run, or 1.0s if none are known).
4. **Greedy bin-packing.** Groups are sorted by descending total seconds and
   assigned whole to whichever shard currently holds the least expected
   time. A group's files are never split across shards — this is what keeps
   shared package/test-group fixture state together.
5. **Output.** A `schema_version: 1` document: `source`, `fallback_seconds`,
   `test_files`, `test_groups`, `timed_files`, `fallback_files`, `seed`
   (nullable), `phpunit_version` (soft-detected via `vendor/bin/phpunit
   --version` when `vendor/` exists), and `include[]` — one entry per shard
   with `id`, `paths` (newline-joined), `suites` (map of suite name to its
   paths within that shard), `empty` (explicit boolean), `test_files`,
   `expected_seconds`, `fallback_files`. A shard with no assigned paths is
   still emitted, with `empty: true`, so a matrix leg is never silently
   dropped.

The same command builds both matrices' plans — `--shards=4` for
`ci-test-shards`, `--shards=2 --seed=<run-derived>` for the random-order
matrix — differing only in shard count and the presence of a seed.

## 6. Runner — `bin/test-random-order`

```
bin/test-random-order --plan=<plan.json> --shard=<id> [-- <extra phpunit args>]
bin/test-random-order [--seed=<n>] [-- <extra phpunit args>]     # unsharded, plan-less
```

**Plan mode** (`--plan`/`--shard`, used by `ci-random-order-shard`) reads the
**saved plan**, never a freshly computed inventory, so a replay after the
working tree changes reproduces the original file set exactly:

- Refuses (exit 2) a plan that isn't `schema_version: 1`, a shard id absent
  from the plan, a missing/non-numeric/out-of-range seed, or a shard whose
  `empty` flag disagrees with its `suites` map (declared empty but carrying
  real suites, or declared non-empty with no valid suites — both are
  rejected rather than silently falling through to an unsharded run).
- An empty shard succeeds without invoking PHPUnit at all.
- Otherwise, for each suite with paths, runs one PHPUnit process with
  `--order-by=random --random-order-seed=<plan seed>`, passing that suite's
  explicit paths (unless the caller passed a list/discovery flag, which
  PHPUnit rejects alongside explicit file arguments).

**Plan-less mode** (used by `nightly.yml`) derives a seed from `--seed`,
`TEST_RANDOM_SEED`, or a random default, then runs three sequential PHPUnit
processes — `Unit`, `Integration`, `Architecture` — each a fresh-process
memory-isolation boundary, unless the caller passed an explicit selection
flag (then a single unsplit run).

Either mode prints the seed and a literal replay command before running
anything. The shared seed gives **deterministic shard replay**; it is
explicitly *not* equivalent to one global randomized ordering over the whole
inventory — no sharded run can be, since each shard only shuffles its own
subset. §7.2's nightly proof supplies that.

## 7. Workflows

### 7.1 `ci.yml`

```
prepare-test-plan          needs: [support-contract, spec-drift]
  composer install (single authority for this run, retry-wrapped)
  tar vendor/ -> build/ci/vendor.tar; sha256(tar) and sha256(installed.php)
  -> artifact "vendor-archive": vendor.tar, vendor.tar.sha256, installed.sha256
  build-phpunit-shards --shards=4 -> artifact "phpunit-shard-plan"

ci-test-shards (matrix id: [1,2,3,4])   needs: [support-contract, spec-drift, prepare-test-plan]
  download "vendor-archive"; verify-random-order-vendor-archive + check-platform-reqs;
  on failure, retried locked `composer install` instead
  download "phpunit-shard-plan"; run this shard's paths in one phpunit
  process, deterministic order, --coverage-clover + --log-junit
  -> artifact "php-test-shard-<id>"

ci-unit-tests / ci-coverage   needs: [ci-test-shards]   (owned by governed-gates.md)

prepare-random-order-plan   needs: [support-contract, spec-drift]
  no install of its own — consumes prepare-test-plan's archive downstream
  seed = (GITHUB_RUN_ID % 2147483647) + 1; logged as a workflow ::notice
  build-phpunit-shards --shards=2 --seed=<seed> -> artifact "random-order-plan"
  (JSON plan only; the dependency archive is the separate "vendor-archive"
  artifact above, not bundled with this one)

ci-random-order-shard (matrix id: [1,2])   needs: [prepare-random-order-plan, prepare-test-plan]
  download "random-order-plan" and "vendor-archive"
  verify-random-order-vendor-archive + check-platform-reqs; on failure,
  retried locked `composer install` instead
  bin/test-random-order --plan=… --shard=${{ matrix.id }}

ci/random-order    needs: [prepare-random-order-plan, ci-random-order-shard]
                   if: always()
```

### 7.2 `nightly.yml`

Daily `schedule` (05:00 UTC) plus `workflow_dispatch` with an optional
`seed` input for manual replay. One job, `nightly/random-order-full`, runs
the complete **unsharded** `composer test:random` (`bin/test-random-order`'s
plan-less path) against a fresh, independent `composer install` — this
workflow does not consume `ci.yml`'s `vendor-archive`, since it is a
separate scheduled run with no shared artifact to download. The seed is
date-derived when not supplied and always logged, both as a workflow notice
and as a self-contained banner written into the uploaded log itself, so a
failure remains replayable even if the run's own step log has expired.
`composer test:random`'s plan-less path runs three sequential PHPUnit
processes (Unit, then Integration, then Architecture); a single
`--log-junit` path would be silently overwritten by each process in turn, so
the job instead captures full console output to a plain-text log
(`build/logs/nightly-random-order.log`) via `tee` under `set -o pipefail`,
not JUnit XML. The workflow declares its own `concurrency` group so
overlapping nightlies do not stack, uploads that log as failure-only
evidence, and holds **no deployment, release, split, or external-state
authority** of any kind.

### 7.3 Dependency preparation — the single run-scoped artifact

Three re-runs of the predecessor PR (#2406) failed on repeated `HTTP/2 429`
from `codeload.github.com`, in three independent installs: the old
`prepare-random-order-plan`'s own install, `ci/test-shard-1`'s install, and
`ci/skeleton-create-project`'s install. Consolidating the first two into one
authority removes two of those three failure points outright.

`prepare-test-plan` is now that single authority:

- `composer install --no-interaction --prefer-dist --no-progress`
  (retry-wrapped via `.github/actions/composer-install-retry`) from the
  exact checkout, once per run.
- `tar` the `vendor/` tree (preserving symlinks — plain `tar` does not
  dereference by default); record the archive's SHA-256 to
  `build/ci/vendor.tar.sha256`, and `vendor/composer/installed.php`'s
  SHA-256 to `build/ci/installed.sha256`.
- Uploads both plus the tar itself as one artifact, `vendor-archive`.

Both `ci-test-shards` and `ci-random-order-shard` download that same
`vendor-archive` and run the identical integrity gate before use
(`bin/verify-random-order-vendor-archive <archive-dir> <work-dir>`, extracted
from an inline `ci.yml` step specifically so it is fixture-testable — see
`tests/Architecture/RandomOrderVendorArchiveIntegrityTest.php`):

- The digest file and archive both exist; `sha256sum --check` against
  `vendor.tar.sha256` passes (this check must run from the repo root, not
  after `cd`-ing into the archive directory — the recorded path in the
  digest file is relative to where `sha256sum` originally ran).
- `vendor/composer/installed.php` extracts and hashes to the value recorded
  in `installed.sha256`. This proves the extracted archive is internally
  self-consistent — not truncated or corrupted in transit — **not** that it
  matches this exact checkout; it is a self-consistency check of the archive
  against itself.
- Every `vendor/waaseyaa/*` symlink resolves to a directory containing a
  `composer.json` present in **this** checkout. This is the check that
  catches a *stale* archive whose relative symlinks point at packages this
  checkout no longer has — the exact failure mode a persistent cache
  produced historically (`admin-bridge`, `agent-output`, `northcloud` were
  all observed dangling against 75 linked entries for 77 packages).

`composer check-platform-reqs` runs as a separate step immediately after,
outside the script, because it depends on the real runner's PHP binary and
extension profile rather than on the archive's contents.

The real exact-checkout binding is **structural, not cryptographic**: GitHub
Actions artifacts are run-scoped — `vendor-archive` is uploaded within this
run by `prepare-test-plan` from the same `inputs.sha`/`github.sha` the shard
jobs themselves check out, and downloaded only within that same run. There
is no code path by which a shard could see an archive built from a different
commit; the checks above exist to catch corruption and staleness, not to
establish the binding itself.

On any check failure, the consuming job discards the archive, removes
`vendor/`, and falls back to its own retried, locked `composer install`
(same 3-attempt, 5s/10s-backoff pattern as the composite action) — the
fallback is the safety net, not a degraded mode CI treats as success.

**No persistent `vendor/` cache anywhere in this file.** Every Composer
dependency cache in `ci.yml` is keyed exactly on runner OS, PHP version, and
`composer.lock` hash (or a fixture-specific lockfile for `ci-package-isolation`
/ `packaged-form`), with **no broad `restore-keys`**: a prefix match can hand
a job a `vendor/` tree built from a *different* lock file, which was safe
only when every job then unconditionally reinstalled. Jobs that install
independently of `prepare-test-plan` — `composer-deps-audit`, `ci-lint`,
`check-dead-code`, `mutation-pilot`, `security-defaults`, `verify-gates`,
`ci-playwright-smoke`, `ci-package-isolation`, `core-only-boot`,
`packaged-form`, `skeleton-create-project` — get bounded retry with backoff
instead, via the shared `.github/actions/composer-install-retry` composite
action (or an equivalent hand-rolled loop where the job's `run:` shape
cannot cleanly call a composite action mid-script).

`ci/skeleton-create-project-windows` (#2644) is the one job in this file that
does not run on Linux. It proves the fresh-project lifecycle — create-project,
the pre-init verification refusal, `site:init`, `install:init`, and
`composer site-verify` — on a native Windows development host, where the
framework previously could not complete `site:init` at all. It cannot use the
shared `composer-install-retry` composite action (`shell: bash`) or the Linux
Composer cache path, so it caches `~\AppData\Local\Composer\files` under the
already-OS-scoped key and is written entirely in PowerShell and PHP. It
deliberately runs none of the PHPUnit suites: a documented set of those is
POSIX-only by design, so running them there would be a guaranteed red for
environmental reasons. It makes no serving claim, and `support/s1-v1.json`'s
`platform.framework_os` remains the ubuntu-24.04 serving runtime.

The `check-dead-code` job's PHPStan result cache has a separate, stricter
custody rule. Dead-code reachability is a global property of the analyzed PHP
universe: a source or PHPDoc change can alter the usage graph without changing
the entrypoint provider, PHPStan configuration, or baseline. Its restore and
save steps therefore share one exact key composed from those analyzer inputs
and `github.run_id`, with **no `restore-keys` prefix**. A rerun of the same
workflow run may reuse that exact cache; a distinct run must start cold even
when its analyzer-configuration hash matches. This prevents an older analyzed
head from deciding the current head while preserving exact-run retry reuse.

### 7.4 Aggregator contract

`ci/random-order` publishes the required status context. `if: always()`
alone is insufficient — it would publish success after skipped shards. The
job fails unless both of:

- `needs.prepare-random-order-plan.result == 'success'`
- `needs.ci-random-order-shard.result == 'success'` — GitHub's aggregate
  result for a `fail-fast: false` matrix job, which is `success` only when
  every leg that ran succeeded. Any `skipped`, `cancelled`, or `failure` leg
  makes the aggregate non-`success`, which fails the context. This also
  covers `ci-random-order-shard`'s own dependency on `prepare-test-plan`
  (the single install authority): if that job fails or is cancelled, the
  shard matrix is skipped rather than run, and the aggregate is non-`success`.

This proves "every leg that ran, succeeded." It does **not** prove "there
were exactly two legs" — GitHub's `needs.<matrix-job>.result` aggregate has
no way to express leg count, only leg outcome, so a runtime check for shard
count would be comparing the workflow to itself and could never fail. Matrix
width is pinned instead at the workflow-definition level: an Architecture
test (`CiSingleExecutionProofTest::randomOrderPreparesOnceAndFansOutToTwoShards`)
asserts `id: [1, 2]` on the `ci-random-order-shard` matrix declaration. If
that matrix is ever resized (§4), both the test and this spec must change
together — there is no automated cross-check between the declared width and
this text.

## 8. Test matrix

Planner (`tests/Architecture/PhpUnitShardPlannerTest.php`):

- deterministic, timing-balanced, no duplication across shards
- missing timing evidence uses a deterministic median fallback
- committed timing evidence is refreshed from retained JUnit by repository
  file
- every path resolves to exactly one suite; a multiply assigned path is
  fatal (the `packages/analytics`/`packages/oauth-provider` live hazard)
- an empty shard is declared explicitly, not dropped
- the plan records its own provenance (`seed`, `phpunit_version`)

Runner (`tests/Architecture/RandomOrderRunnerTest.php`):

- an invalid replay seed is rejected; a fixed seed is logged and PHPUnit
  arguments are forwarded
- `ci` derives and logs a replayable seed for a dedicated lane
- plan mode replays a saved plan shard per suite; an empty shard succeeds
  without invoking PHPUnit; an unknown shard id or malformed plan is
  rejected
- a shard missing `suites`, carrying a malformed suite entry, or declared
  empty with a non-empty `suites` map is rejected rather than silently
  falling through to an unsharded run
- a null or non-numeric plan seed is rejected; a missing plan file is
  rejected cleanly
- plan mode prints a plan-scoped replay hint instead of the `composer`
  shortcut

Vendor-archive integrity (`tests/Architecture/RandomOrderVendorArchiveIntegrityTest.php`):

- a good archive is verified and extracted
- a missing archive, a missing digest file, a corrupt digest, and a
  dangling wrong-checkout symlink each fall back (exit 1) rather than being
  trusted

Workflow shape (`tests/Architecture/CiSingleExecutionProofTest.php`,
`CiContractOrderingTest.php`, `NightlyRandomOrderProofTest.php`):

- `prepare-test-plan` is the single Composer install authority for the run;
  both shard matrices consume its `vendor-archive` instead of installing
  directly, with the integrity-gate-then-fallback shape present in both
- `ci-test-shards` fans out to `id: [1, 2, 3, 4]`; `ci-random-order-shard`
  fans out to `id: [1, 2]`; `prepare-random-order-plan` no longer installs
  dependencies itself
- the `ci/random-order` aggregator refuses on incomplete shard evidence and
  requires both `needs` results to be `success`
- fast-contract jobs run every S1 roster gate; the `spec-drift` job
  completes (rather than running) on non-pull-request events; test
  execution waits on the fast contracts, and both aggregators wait on their
  shards; both shard matrices wait for the single install authority
- nightly is unsharded, complete, seed-overridable, concurrency-guarded,
  uploads failure-only evidence, and holds no deployment authority

## 9. Evidence

`prepare-test-plan` and `prepare-random-order-plan` publish shard counts,
expected seconds, fallback-file counts, the random-order seed, and the plan
documents themselves as retained artifacts and workflow `::notice`s.

Acceptance for a future shard-count change (§4) requires median and p95
critical-path time plus runner-minutes from at least 10 comparable runs
showing both metrics improve at three shards, one green pull-request run,
one green post-merge `main` run, and one green complete nightly proof.

## 10. Non-goals

Reducing dependency-install redundancy further than the single run-scoped
artifact already shipped here (for example, reusing an archive across
separate workflow runs for the same exact head), immutable artifact
promotion, and any change to coverage thresholds or the required-check
roster are out of scope for this spec. No release, split, or deployment
behaviour is touched.
