# Random-order sharding revision — targeting removed

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Revise PR #2406 to drop changed-package targeting entirely, keep package-safe timing-balanced random-order sharding over the complete inventory, enforce cross-package test-reference declarations, and consolidate Composer installation into one exact-head run-scoped dependency artifact.

**Why the revision:** Targeting measured a 13% compute saving against a dependency graph that was **unsound** — `packages/*/tests` imports 53 undeclared cross-package edges. Declaring them honestly collapses the closure (median 4 → 64 of 76 packages) and moves mean selection from 89% to 96%. A ~4% saving is smaller than CI variance and does not earn a fail-closed selector's complexity or trust burden. The investigation is preserved as rejected-design evidence, not deleted history.

**Spec:** `docs/specs/ci-test-selection.md` (rewritten by Task 4)

## Global Constraints

- PHP 8.5+, `declare(strict_types=1)` in every new/changed PHP file.
- `php bin/check-pr-preflight` must pass before every commit; `--full` plus all three suites before the branch is offered.
- Run suites in the FOREGROUND, separately (Unit and Integration in one shell has OOMed): `php -d memory_limit=1G vendor/bin/phpunit --testsuite <Unit|Integration|Architecture> --no-coverage`. Never pass `-v`.
- PHP 8.5 is not on the non-interactive PATH: prefix every shell command with `export PATH="$HOME/.linuxbrew/bin:/home/linuxbrew/.linuxbrew/bin:$PATH"`.
- Internal Composer constraints must read exactly `^0.1.0-alpha.293` (the tracked `VERSION`), per policy CP-NEW.
- CP007: every internal `require`/`require-dev` entry must have a corresponding package-local `repositories` path entry, and vice versa.
- **`ci/random-order` is a required status context** in the `main-protection` ruleset. Its `name:` must not change.
- Never `git push`, never open or edit a PR, never merge. Task 5 handles publication under separate authorization.
- Work on branch `ci/2404-targeted-random-order` in `/home/fsd42/dev/waaseyaa`. Commit directly; do not create branches.

---

### Task 1: Enforce cross-package test-reference declarations

**Files:**
- Modify: `bin/check-package-layers` (new rule PL010)
- Modify: `packages/*/composer.json` (add missing `require-dev` + `repositories` entries)
- Test: `tests/Architecture/CheckPackageLayersGateTest.php` (extend)

**Requirement.** Every `Waaseyaa\…` reference in `packages/<p>/tests/**` and `packages/<p>/testing/**` that resolves to a package other than `<p>` must correspond to a declared internal `require` or `require-dev` edge in `packages/<p>/composer.json`. A reference that resolves to no known PSR-4 root is a failure too — unknown attribution fails closed, it is not skipped.

**Rule shape.** Add as **PL010**, fail-on-new with a baseline file `tools/package-layers-test-edge-baseline.txt` following the exact conventions of `tools/package-layers-undeclared-baseline.txt` (mandatory inline rationale per entry). **The baseline must ship empty** — the intent is to declare the real edges, not to baseline them. It exists only so a future genuinely-unfixable case has a reviewed escape hatch.

**Reference extraction** must cover plain `use`, `use function`, `use const`, grouped `use X\{A, B}`, and inline fully-qualified references. Note the last pattern over-matches FQCNs inside string literals and comments; a prior scan reported 390 raw references across 53 pairs, but the reference count is less trustworthy than the pair count. **Verify a sample by hand before mass-editing manifests**, and report what fraction of raw hits were real.

**Satisfy the rule by declaring**, not by refactoring imports — `require-dev` edges that point upward are permitted policy in this repo (`bin/audit-require-dev-layers` is warn-only and exists precisely for them). `bin/check-package-layers` reads only `$data['require']` for PL004/PL006/PL007, so `require-dev` additions cannot trip layer order or the same-layer cycle rule; confirm that remains true after your change.

The 53 pairs previously measured (verify independently, do not trust this list):

```
admin-surface => entity-storage, field, note, taxonomy, user
ai-agent => wayfinding            ai-tools => database-legacy
api => ai-tools, config, node, user
cache => entity                   cli => ai-agent, ai-tools
field => database-legacy
foundation => access, ai-tools, api, audit, auth, cli, entity-storage, field, graphql,
              groups, i18n, mcp, media, node, scheduler, seo, ssr, taxonomy, user, workflows
genealogy => seo                  graphql => database-legacy
groups => api, genealogy, routing listing => database-legacy
media => user                     messaging => field
migration => cli, node, taxonomy  node => workflows
relationship => ai-tools, api, genealogy, user
routing => oidc                   seo => user
ssr => entity-storage, node, oidc, taxonomy
state => entity                   taxonomy => entity-storage
testing => routing                user => database-legacy, entity-storage, field, ssr
wayfinding => database-legacy     workflows => user
```

**`foundation`'s 20 upward test edges are a genuine layering smell** — a layer-0 package's tests importing layer-4/6 packages. Declaring them is in scope; fixing the layering is not. Record the smell in your report so it can become a separate issue.

**Verification:** the new rule reports zero violations with an empty baseline; `php bin/check-pr-preflight` green; `composer validate` (or the repo's policy gate) green on every edited manifest; Architecture suite green.

---

### Task 2: Remove the selector subsystem

**Files:**
- Delete: `bin/select-random-order-scope`, `tools/random-order-scope-manifest.json`, `tests/Architecture/RandomOrderScopeSelectorTest.php`
- Modify: `bin/lib/random-order-scope.php` (reduce to what sharding still needs, or delete and relocate)
- Modify: `bin/build-phpunit-shards` (drop `--only`, drop the embedded `selection` document)
- Modify: `tests/Architecture/PhpUnitShardPlannerTest.php`

**What goes.** All changed-package classification, the scope manifest, consumer closure, attribution, the always-run set, the selection document, and `--only`.

**What STAYS — do not delete these, they are independent improvements that survived review:**
- **Total-and-unique PHPUnit suite assignment.** Every discovered path must resolve to exactly one testsuite; zero or two is fatal with exit 2 naming both suites. This guards a live hazard: `packages/analytics/tests` and `packages/oauth-provider/tests` are whole-tree `Unit` directories, so either gaining a `tests/Integration/` subdirectory would double-assign against `packages/*/tests/Integration`.
- **Explicit empty matrix legs** (`empty: true`, `paths: ''`, `test_files: 0`) so a leg is never silently dropped.
- **Plan provenance**: `seed`, `phpunit_version`, per-shard `suites`.
- **`bin/test-random-order --plan/--shard`** in full, including its malformed-plan hardening (a shard entry missing or malformed `suites` exits 2 rather than falling through to an unsharded run), the null/non-numeric seed rejection, and the plan-scoped replay hint.
- `bin/verify-random-order-vendor-archive` and its test.

`ros_inventory()` and `ros_group_of()` are still needed by the planner. Either keep a trimmed `bin/lib/random-order-scope.php` containing only those two (rename it if the name no longer fits, updating every reference), or inline them into `bin/build-phpunit-shards`. State which you chose and why. Whatever you choose, the `RosScopeFailure` fail-closed behaviour of `ros_inventory()` must survive.

**Verification:** no dangling references (`rg -n 'select-random-order-scope|random-order-scope-manifest|--only|selected_paths|always_run_groups'` returns only intentional historical mentions); Architecture suite green; `php bin/build-phpunit-shards --shards=2 --timings=tools/phpunit-timings.json --pretty` produces a valid 2-shard plan over the complete inventory.

---

### Task 3: One run-scoped dependency artifact, two shards

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `tests/Architecture/CiSingleExecutionProofTest.php`, `tests/Architecture/CiContractOrderingTest.php`

**Requirement — single Composer authority.** Three re-runs of #2406 failed on repeated `HTTP/2 429` from `codeload.github.com`, in `prepare-random-order-plan`, in `ci/test-shard-1`'s Composer subprocess, and in `ci/skeleton-create-project`. Stop relying on codeload recovery.

- **One job** performs the authoritative `composer install` for the exact head, tars `vendor/` preserving symlinks, records a SHA-256, and publishes a run-scoped artifact. Fold this into the existing planning/bootstrap job (`prepare-test-plan`) so there is exactly one installation authority per run.
- **Every downstream test job consumes that artifact** and verifies it with `bin/verify-random-order-vendor-archive` before use: the `ci-test-shards` matrix and the random-order shard matrix both. On verification failure a job falls back to a locked `composer install` — the fallback stays, it is the safety net.
- **`ci/skeleton-create-project`** stays a separate consumer (it builds a different project), but must use a Composer **download cache keyed exactly** on runner OS, PHP version, and `composer.lock` hash — no broad restore keys — plus bounded retry with backoff around its install.
- Composer-dependent subprocess tests should receive the prepared archive where they can.

**Requirement — two shards.** The random-order matrix starts at `id: [1, 2]`. Update the aggregator and every workflow-shape assertion accordingly. Three shards is a later decision gated on measurement (Task 4 reframes the gate).

**Preserve:** the `ci/random-order` aggregator name and its strict predicate (planning succeeded AND the matrix aggregate is `success`, under `if: always()`); `prepare-*` jobs gated on `[support-contract, spec-drift]`; action pins copied from existing jobs; `nightly.yml` untouched.

**Verification:** YAML parses (`python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))"`); job graph and `needs:` edges asserted by tests; walk every plan × shard result combination and confirm no combination publishes a green `ci/random-order` without both shards genuinely succeeding — report that walk.

---

### Task 4: Documentation, spec rewrite, rejected-design record

**Files:**
- Rewrite: `docs/specs/ci-test-selection.md`
- Modify: `CLAUDE.md` (orchestration row), `CHANGELOG.md`, `docs/ci/README.md`

**Spec rewrite.** Retitle to cover what now exists: package-safe timing-balanced random-order sharding, the single run-scoped dependency artifact and its integrity gate, the strict aggregator, and the nightly unsharded proof. Keep the file path — `CLAUDE.md` and other docs link it. Remove the selection contract (§3 classification, closure, attribution, always-run set, §4 selection document, §5 `--only`).

**Add a "Rejected design: changed-package selection" section** carrying the evidence, because this is architectural knowledge worth keeping:
- initial modelled saving 23%, revised to 13% once atomic group expansion was applied
- the always-run floor is 68% of inventory seconds because `tests/Architecture` (143.0s, 40%) is repo-state contract testing that cannot be attributed to any package
- the shipped selector measured mean 87–89% selected, median 100%, full fallback 19/40
- **the decisive finding**: `packages/*/tests` carried 53 undeclared cross-package edges, so that measurement rested on an unsound graph; declaring them moves median closure from 4 to 64 of 76 packages and mean selection to 96%, i.e. a real saving near 4%
- two grouping alternatives measured and rejected: file-level intersection without atomic expansion (splits a group's fixture state across processes), and reference-connected components (degenerates — `packages/` ↔ `tests/` edges collapse 1140 files into one component)
- conclusion: below CI variance, and not worth a fail-closed selector's complexity, its ongoing under-selection risk, or the trust burden

**Reframe the 10-run gate**: it now decides **two versus three shards** and measures **total compute**, not whether targeting works. State the criterion explicitly — three shards is adopted only if measured shared-artifact overhead shows it reduces both wall time and runner-minutes.

`docs/ci/README.md` is operator-facing: update the `ci/random-order` row, the artifacts table, and the nightly section to match reality.

`CHANGELOG.md`: replace the current `#2404` entry with one describing what actually ships — sharded complete-inventory random-order, the single dependency artifact, the test-edge enforcement rule, and the nightly proof. Do not describe targeting as shipping.

---

### Task 5: Gates, publication, issue evidence

**Requirement.** Run `php bin/check-pr-preflight --full` and all three suites in the foreground, separately, at the exact head. Report real counts.

Then, and only then: push the branch and update PR #2406's description to describe the revised design. Add a comment to issue **#2404** recording the selector investigation as rejected-design evidence, using the Task 4 spec section as its source, and stating plainly that #2404 stays open.

**Do not merge.** Report the new head SHA and the PR URL.

---

## Verification the branch must reach before merge

- all three random-order shards *(two, after this revision)*, the aggregator, and `main` green
- a manually dispatched nightly proof green
- only then, the 10-run measurement — for two-versus-three shards and total compute
- #2404 stays open
