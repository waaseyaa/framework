# Phase 2 — Packaged-Form CI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close Phase 2 of the Groups-extraction arc (`framework#1315` part B) by adding a Packagist-resolved consumer harness that exercises the kernel path as an installed downstream app sees it, then wiring that harness into the existing GitHub Actions CI substrate.

**Execution gate:** Do not start Phase 2 code work until Phase 1 is on `main`. This plan assumes PRs `#1321`–`#1325` have merged: the packaged consumer's own test harness must follow the Phase 1 real-kernel pattern (anonymous subclass + `publicBoot()`), and the top-level architectural guard now forbids named kernel subclasses anywhere under `tests/**`.

**Status snapshot (2026-04-20):** Phase 1 merged on 2026-04-20. The in-tree kernel-path assertion now exists in three layers: end-to-end kernel materialization, targeted `SqlSchemaHandler` registry-fallback guard, and an architectural rule blocking new named kernel subclasses in `tests/**`. What still does not exist is a consumer install that resolves published packages from Packagist rather than path repos from the monorepo checkout.

**Architecture:** The harness under test is a **minimal consumer application fixture**, not another in-tree framework test. It must install released packages through Composer, boot the kernel from consumer-owned config, register one bundle field through a consumer provider, force storage resolution, then save and read one entity to prove the `{base}__{bundle}` subtable exists and is actually used. The failure class is "published artifact or constraint floor differs from source-tree assumptions," not "framework internals are wrong in isolation."

**Tech Stack:** GitHub Actions (`.github/workflows/ci.yml`), Ubuntu runner, PHP 8.4 (matching the current CI substrate), Composer/Packagist, SQLite (`pdo_sqlite`, `sqlite3`), PHPUnit 10.x, consumer package set anchored on `waaseyaa/core` + `waaseyaa/groups`.

---

## File Structure

**Create (PR 1 — consumer skeleton + packaged-form tests):**
- `tests/PackagedForm/skeleton/` — minimal consumer app fixture, Composer-installed from Packagist.
- `tests/PackagedForm/skeleton/composer.json` — requires **exact published alpha tags** for `waaseyaa/core` and `waaseyaa/groups`; **does not** use path repositories.
- `tests/PackagedForm/skeleton/config/waaseyaa.php` — consumer-owned kernel config.
- `tests/PackagedForm/skeleton/config/entity-types.php` — declares the minimal `group` / `group_type` consumer-visible entity types needed for the harness.
- `tests/PackagedForm/skeleton/src/Provider/PackagedFormServiceProvider.php` — registers one bundle field on `group`.
- `tests/PackagedForm/skeleton/tests/Integration/PackagedKernelPathTest.php` — boots the consumer kernel via anonymous subclass + `publicBoot()`, asserts subtable materialization, then saves/reads one entity.
- `tests/PackagedForm/README.md` — short fixture-local operator notes: purpose, Packagist-only rule, and why this is distinct from `tests/Integration/`.

**Modify (PR 1 — local runner, if needed):**
- `phpunit.xml.dist` — only if a dedicated `PackagedForm` testsuite is needed for local execution.
- `composer.json` — only if a thin script alias such as `packaged-form-test` materially simplifies the workflow. Otherwise skip.

**Modify (PR 2 — CI job on existing substrate):**
- `.github/workflows/ci.yml` — add one `ci/packaged-form` job to the existing CI workflow; do not introduce a separate workflow unless the current substrate proves structurally incompatible.

**Does not touch:** production framework code, release workflow semantics, Packagist update workflow behavior, `#1313` duplicate-registration DX, or product repos.

---

## Why This Exists

Phase 1 proved the kernel path in-tree. It could not prove the **published artifact path** because every Phase 1 test ran against a monorepo checkout where sibling packages were path-resolved and always fresh. That is exactly what let alpha.148–152 ship a consumer-visible regression chain while in-tree tests stayed green.

Packaged-form CI is therefore not "another kernel test." It is the missing downstream contract:

1. Composer resolves released package metadata and sibling version floors.
2. The consumer boots from its own files, not from framework-owned fixtures.
3. Bundle-field registration happens from a downstream provider, not from an in-tree test helper.
4. One save/read round-trip proves schema wiring and write-path routing both survive artifact publication.

The harness must stay minimal. If it starts mirroring the main `skeleton/` app or broad product behavior, it becomes drift-prone and stops being a crisp alarm for the packaged-form failure class.

---

## Consumer Skeleton Contract

**Package choice (do not blur this):**
- The consumer fixture should require `waaseyaa/core` and `waaseyaa/groups`, not the monorepo root `waaseyaa/framework` project package.
- Reason: Phase 2 is meant to exercise the split-packages as a downstream app consumes them from Packagist. Using the root project package would proxy the wrong contract and partially collapse the distinction Phase 2 is supposed to validate.

**Constraint stance (also load-bearing):**
- Pin the consumer fixture to the **exact alpha tag under verification** such as `0.1.0-alpha.153`.
- Do not use caret floors like `^0.1.0-alpha.153`; they silently float to newer alphas and erode reproducibility.
- Do not use `dev-main`; that collapses the Packagist contract back toward source-tree behavior.
- When the next release is cut, bump the exact tag in the fixture as part of the release-follow-up workflow. A red harness between "Phase 1 merged" and "next alpha published" is expected and should remain loud.

**Location recommendation:**
- Use `tests/PackagedForm/skeleton/`.
- Reason: it lives with other framework-owned test fixtures, but remains visibly separate from `tests/Integration/` and from the shipped application `skeleton/`.

**How it differs from `tests/Integration/`:**
- `tests/Integration/` runs against the monorepo checkout with path-resolved sibling packages.
- `tests/PackagedForm/skeleton/` installs published packages into a standalone consumer app with its own `composer.json`, autoload, config, provider list, and PHPUnit entrypoint.
- Its assertions are narrower: kernel boot, bundle registration, subtable materialization, and one save/read round-trip. It is not a second general-purpose integration suite.

**Harness rules:**
- No path repositories, no `../packages/*`, no local override repos.
- No named kernel subclasses anywhere under `tests/**`; use the Phase 1 anonymous-subclass + `publicBoot()` pattern.
- One consumer provider only. No product-like domain logic.
- SQLite only for Phase 2 initial scope.
- Assertions must be framed as the CI contract. Any deliberate mutation recipe, if added later, is human-debug guidance only.

---

## Recommended CI Posture

**Recommendation:** two-stage posture.

1. **Execution PR posture:** mergeable without requiring a pre-merge green Packagist run against unpublished changes.
   - Reason: the first meaningful green for this harness is release-gated. Requiring it as a merge blocker for the PR that introduces the harness is circular when the published alpha does not yet contain the Phase 1 fixes or the new fixture.
2. **Steady-state posture after first published-alpha verification:** make the packaged-form check merge-blocking in the existing `CI` workflow.
   - Reason: once one released alpha proves the harness itself is sound, packaged-form regressions are exactly the class we want to block before merge.

This mirrors the spec's release-gated note: the **first green run** is not a PR-merge blocker, but the harness should not remain informational forever.

**Objective gate-flip criterion:**
- Promote the packaged-form check to merge-blocking once it is green against the **first alpha tagged after Phase 1 landed on `main`**.
- Until that alpha exists on Packagist, a red packaged-form run is expected bring-up noise, not evidence that Phase 2 itself is misdesigned.

---

## Matrix Scope

**Phase 2 initial matrix:**
- PHP: `8.4` only.
- OS: `ubuntu-latest` only.
- DB: SQLite only.
- Test scope: packaged-form integration suite only.

**Why this is the right starting cell:**
- Current `.github/workflows/ci.yml` already standardizes on PHP 8.4 for lint/unit/integration jobs.
- The alpha.148–152 defect class was about package resolution, kernel-path bundle wiring, and sibling-version floors — not DB-vendor variation.
- Expanding to a PHP or DB matrix before the Packagist contract exists would increase cost and blur the signal.

**Explicitly out of initial scope:**
- MySQL/PostgreSQL packaged-form jobs.
- PHP 8.5 matrix expansion.
- Browser or SSR coverage.
- Product-specific downstreams (for example Minoo).

Those can come later if a real defect points there; they are not required to close Phase 2's core class of risk.

---

## Ordering Rationale (Do Not Reshuffle)

1. **Consumer skeleton lands before CI wiring.** The fixture must be runnable locally and reviewable in isolation before it is embedded into `ci.yml`.
2. **CI wiring lands before branch-protection promotion.** We need one merged harness and one published-alpha verification before turning the job into a hard gate.
3. **Published-alpha verification is last and release-gated.** The first meaningful proof requires a released package set on Packagist; a PR cannot manufacture that condition pre-merge.

---

## Task 1 — PR 1: Add the packaged consumer skeleton and local packaged-form test

**Files:**
- Create: `tests/PackagedForm/skeleton/**`
- Optionally modify: `phpunit.xml.dist` or `composer.json` only if a dedicated local runner materially reduces operator error.

**Branch:** `test/1315-packaged-form-skeleton`

- [ ] **Step 1: Create branch from main**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b test/1315-packaged-form-skeleton
```

- [ ] **Step 2: Scaffold the fixture as a real consumer app**

Create `tests/PackagedForm/skeleton/composer.json` with these invariants:

- Requires exact published tags such as `waaseyaa/core:0.1.0-alpha.153` and `waaseyaa/groups:0.1.0-alpha.153`.
- Includes `phpunit/phpunit` in `require-dev`.
- Registers one consumer provider under `extra.waaseyaa.providers`.
- Contains **no** `repositories` block pointing at local paths.

Expected outcome: Composer resolves only Packagist artifacts, and the exact alpha under test is unambiguous in both the fixture and CI logs.

- [ ] **Step 3: Add the minimal consumer-owned files**

Create:

- `config/waaseyaa.php`
- `config/entity-types.php`
- `src/Provider/PackagedFormServiceProvider.php`
- `tests/Integration/PackagedKernelPathTest.php`

The provider should register exactly one bundle field on `group`. The test should:

1. Boot the consumer kernel via anonymous subclass + `publicBoot()`.
2. Trigger storage resolution.
3. Assert `{base}__{bundle}` exists in `sqlite_master`.
4. Save and read one entity so write-path routing is exercised, not just schema creation.

Do **not** introduce a named `MinimalTestKernel`-style helper. Because this fixture lives under `tests/**`, Phase 1's architectural guard now covers it too.

- [ ] **Step 4: Make the Packagist-vs-path distinction explicit**

Add a short fixture-local README noting:

- why this harness exists,
- why it must not use path repos,
- why it is not a substitute for the in-tree `tests/Integration/` suite,
- why its kernel harness must use the anonymous-subclass + `publicBoot()` pattern.

- [ ] **Step 5: Run the fixture locally**

Use the fixture's own Composer install and PHPUnit entrypoint. Expected: the packaged-form test passes on a machine that can resolve the target alpha from Packagist.

If Packagist has not yet published the required alpha, stop and surface that the local run is release-gated rather than weakening the fixture into a path-resolved install. The correct outcome in that window is "waiting on the next alpha tag," not "relax the constraint."

- [ ] **Step 6: Commit and push**

PR title should make clear that this is the consumer harness itself, not the CI job wiring.

**Merge posture:** mergeable once the fixture reads coherently and the local harness design is sound, even if the first Packagist-backed green is waiting on release publication.

---

## Task 2 — PR 2: Wire the packaged-form harness into the existing CI workflow

**Files:**
- Modify: `.github/workflows/ci.yml`
- Optionally modify: `phpunit.xml.dist` only if the fixture run is cleaner through a dedicated testsuite.

**Branch:** `ci/1315-packaged-form-job`

- [ ] **Step 1: Create branch from main (after PR 1 is merged)**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b ci/1315-packaged-form-job
```

- [ ] **Step 2: Add one job to the existing CI workflow**

Modify `.github/workflows/ci.yml` to add a job with:

- job id `packaged-form`,
- displayed check name `ci/packaged-form`,

- runs on `ubuntu-latest`,
- uses `shivammathur/setup-php@v2` with PHP `8.4`,
- enables `pdo_sqlite`, `sqlite3`, `mbstring`, and `xml`,
- installs the fixture's Composer dependencies from inside `tests/PackagedForm/skeleton/`,
- runs only the packaged-form fixture tests.

Do **not** create a separate workflow file unless `ci.yml` proves structurally incompatible. Phase 2 should extend the existing CI substrate, not invent a parallel lane.

- [ ] **Step 3: Keep the matrix intentionally small**

Do not add PHP or DB matrices in this PR. The job should be one cell only.

Document in the PR body that this is deliberate: the variable under test is artifact resolution and consumer boot semantics, not runtime portability.

- [ ] **Step 4: Decide the initial enforcement mode**

Recommended initial implementation:

- job exists in `CI`,
- job is treated as informative for the bring-up window if the published alpha pinned by the fixture has not yet shipped,
- the PR description explicitly states that branch protection should promote it to required after the first successful run against the first alpha tagged after Phase 1 landed on `main`.

If the repo's branch-protection workflow cannot express "informative for bring-up, required after first green" in one PR, prefer documenting the promotion step rather than encoding a permanent `continue-on-error`.

- [ ] **Step 5: Verify the workflow shape**

Check the generated job id and displayed name for coherence with the existing `CI` workflow. The Phase 2 check should appear beside `ci/unit-tests`, not inside `release.yml`, `packagist-update.yml`, or `sync-skeleton.yml`.

- [ ] **Step 6: Commit and push**

PR title should make clear that this PR is CI wiring only; the consumer skeleton must already exist from Task 1.

**Merge posture:** mergeable once the workflow wiring is coherent. First Packagist-backed green is still release-gated.

---

## Task 3 — Release-Gated Verification: Prove the harness against a published alpha, then promote it

**Repo:** waaseyaa (operational step, not necessarily a code PR)

- [ ] **Step 1: Merge Task 2**

Only after Tasks 1 and 2 are on `main`.

- [ ] **Step 2: Cut a new alpha release containing the Phase 1 fixes and the Phase 2 harness**

This is the first moment Packagist can serve the package set the harness is intended to validate.

- [ ] **Step 3: Trigger Packagist update and wait for propagation**

Use the existing Packagist update flow; do not invent a second publication path.

- [ ] **Step 4: Observe the first meaningful packaged-form green**

Expected: the fixture installs the first alpha tagged after Phase 1 landed on `main`, boots, materializes the bundle subtable, and completes one save/read round-trip.

- [ ] **Step 5: Promote the job to merge-blocking**

Once that first post-Phase-1 published-alpha run is green, update branch protection so the packaged-form check becomes required for future PRs.

If the promotion itself needs a small repo-settings or docs PR, do that separately; do not fold it into the bring-up wiring PRs.

---

## Defect Classes This Catches

Phase 2 is specifically meant to catch these classes:

1. **Packaged artifact drift from source-tree assumptions.**
   - Example: in-tree tests pass because sibling packages are path-resolved and always fresh, but consumer installs pull a stale published sibling.
2. **Constraint-floor defects across split packages.**
   - Example: alpha.150's `quoteIdentifier()` chain, where consumer installs co-resolved a sibling version too old for methods callers now used.
3. **Kernel-path bundle wiring missing from published packages.**
   - Example: alpha.148's runtime `no such table: {base}__{bundle}` when `addBundleFields()` bundles never materialized subtables in an `AbstractKernel`-booted consumer.
4. **Consumer-provider registration drift.**
   - The harness proves a downstream provider can register bundle fields and have the published package set honor them.
5. **Release handoff gaps between merged source and published artifact.**
   - The exact-tag fixture makes the "merged on `main` but not yet published" window explicit instead of silently floating past it.

---

## What Phase 2 Does Not Catch

Future readers must not treat packaged-form CI as a universal safety net. It does **not** replace:

1. **In-tree kernel-path guards.**
   - Phase 1's `KernelBundleSubtableMaterializationTest`, `SqlSchemaHandlerRegistryFallbackTest`, and the architectural guard remain necessary. Phase 2 is downstream artifact coverage, not a substitute.
2. **Duplicate-registration and shadow-collision DX.**
   - Those are Phase 3 (`#1313`) concerns and require their own tests.
3. **Product-specific adoption semantics.**
   - A minimal consumer fixture will not catch Minoo-only shadow classes, migrations, or route behavior.
4. **DB-vendor or PHP-version portability beyond the chosen cell.**
   - SQLite on PHP 8.4 is enough to close the packaged-form contract class, not every portability axis.
5. **Release-propagation timing guarantees by itself.**
   - The first meaningful proof is still gated on a published alpha reaching Packagist.
6. **Anything hidden by relaxing the consumer constraint.**
   - If the fixture is allowed to float on caret ranges or `dev-main`, it stops proving the exact published artifact under review.

---

## Known Pitfalls

- **Do not reuse the shipped `skeleton/` app wholesale.** It currently requires `waaseyaa/framework` and includes local-development affordances that blur the Packagist-only contract. Phase 2 needs a purpose-built minimal fixture.
- **Do not let the fixture use path repositories "just for local convenience."** That would recreate the exact blind spot this phase exists to close.
- **Do not use caret floors or `dev-main` in the fixture's Composer constraints.** Phase 2 needs an exact published-alpha target so failures are loud, reproducible, and tied to one release.
- **Do not introduce a named kernel subclass under `tests/**`.** The Phase 1 architectural guard now makes that a red test by design, including inside `tests/PackagedForm/`.
- **Do not over-matrix the first implementation.** The risk being closed is package resolution and consumer boot semantics, not broad compatibility burn-down.
- **Do not mistake the mutation recipe concept for the CI contract.** If a future docblock includes a deliberate mutation recipe, it is a human-debug aid only; the CI contract is the test suite itself.
- **Do not treat the pre-release red window as a regression.** Between "Phase 1 merged" and "next alpha published," the packaged-form harness is expected to stay red if it is pinned to the not-yet-published target tag.

---

## Exit Criteria

1. A minimal Packagist-only consumer fixture exists under `tests/PackagedForm/skeleton/`.
2. The fixture boots via the anonymous-subclass + `publicBoot()` pattern and proves both subtable materialization and one save/read round-trip.
3. `.github/workflows/ci.yml` contains a `packaged-form` job whose displayed check name is `ci/packaged-form`, on the existing CI substrate.
4. The first alpha tagged after Phase 1 landed on `main` is observed green after release publication.
5. After that first green, the packaged-form check is promoted to merge-blocking for future PRs.

---

## Suggested PR Sequence

| Task | Scope | PR count | Merge blocker? |
|---|---|---|---|
| 1 | Consumer skeleton + packaged-form test harness | 1 | No — release-gated first green |
| 2 | Existing `ci.yml` wiring for `ci/packaged-form` | 1 | No — release-gated first green |
| 3 | Published-alpha verification + protection promotion | 0–1 | Yes, for steady-state after first green |

**Phase 2 total expected PRs:** 2 code/CI PRs, plus an operational promotion step (or tiny follow-up PR if repo settings/documentation need to change).
