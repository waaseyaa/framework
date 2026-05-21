---
work_package_id: WP05
title: Cross-mission gate + full verify
dependencies:
- WP02
- WP03
- WP04
requirement_refs:
- C-005
- C-006
- C-007
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T021
- T022
history: []
authoritative_surface: kitty-specs/bimaaji-wakeup-01KS5VEY/
execution_mode: planning_artifact
owned_files:
- kitty-specs/bimaaji-wakeup-01KS5VEY/verification.md
tags: []
---

## Objective

Add the `crossMissionGateSc005` test method to `ApplicationGraphIntegrationTest`,
confirming that M2 (`ai-agent-bimaaji-tools`) can resolve `ApplicationGraphGenerator`
from the container without any additional changes to `packages/bimaaji/`. Then run the
full `composer verify` gauntlet, all CI gate scripts, and the full test suites to
produce a green merge commit.

## Context

SC-005 requires that after M1 merges, M2's first WP can call
`$container->get(ApplicationGraphGenerator::class)` without modifying `packages/bimaaji/`.
The cross-mission gate test is the CI proof of this contract. Plan §WP05 specifies it as
a `#[CoversNothing]` test method annotated with a comment explaining the SC-005 contract.

WP05 is the final WP and depends on all three preceding WPs (WP02, WP03, WP04). Its only
code change is the addition of one test method to a file that WP03 already created. The
bulk of this WP is the systematic verification run documented in T021.

C-005, C-006, C-007 are the hard gates: `composer verify` green, all check scripts green,
no CI hooks bypassed. WP05 owns verifying these on the final branch state before the PR
is opened.

## Subtasks

### T020 — Add `crossMissionGateSc005` test method

**Purpose:** Implement SC-005 as a first-class test that will fail CI if M1 ships in a
broken state that requires further bimaaji wiring before M2 can proceed.

**Steps:**

1. Open `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php`
   (created in WP03).

2. Add the following test method to the class body (after the existing test methods):

   ```php
   /**
    * SC-005: M2's first WP must be able to resolve ApplicationGraphGenerator from the
    * container without modifying packages/bimaaji/. This test is the CI proof.
    *
    * When M2 (ai-agent-bimaaji-tools) begins implementation, its first WP should be able
    * to do $container->get(ApplicationGraphGenerator::class) with only a `composer install`
    * and no additional service provider edits in packages/bimaaji/.
    */
   #[Test]
   #[CoversNothing]
   public function crossMissionGateSc005(): void
   {
       // Uses the same minimal kernel from setUp() — no additional wiring.
       $generator = $this->generator;

       self::assertInstanceOf(
           ApplicationGraphGenerator::class,
           $generator,
           'SC-005: ApplicationGraphGenerator must be resolvable from the container ' .
           'without any bimaaji changes in M2.',
       );

       $graph = $generator->generate();

       self::assertInstanceOf(
           ApplicationGraph::class,
           $graph,
           'SC-005: generate() must return an ApplicationGraph instance.',
       );

       self::assertGreaterThanOrEqual(
           1,
           count($graph->getSections()),
           'SC-005: ApplicationGraph must contain at least 1 section.',
       );
   }
   ```

3. Add `use PHPUnit\Framework\Attributes\CoversNothing;` to the import block if not
   already present.

4. Confirm `ApplicationGraph` is imported — it should already be imported from WP03's
   `allSectionsHaveNonEmptyVersionStrings` or similar.

5. Run the specific test to confirm it passes:
   ```
   ./vendor/bin/phpunit \
     --filter crossMissionGateSc005 \
     tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php \
     --no-coverage
   ```

**Files touched:**
- `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` — edit (add 1 method)

**Validation:**
- `crossMissionGateSc005` passes.
- No other test methods in the file are broken by the addition.

---

### T021 — Full `composer verify` and CI gates

**Purpose:** Confirm C-005, C-006, C-007 — every gate passes on the final branch state
before the PR is opened. This is a systematic checklist, not exploratory.

**Steps:**

Run each gate in sequence. If any fails, fix the issue and re-run from the beginning of
the checklist (do not continue past a failure).

**Gate 1 — Code style:**
```
composer cs-check
```
If it fails: run `composer cs-fix`, review the diff, re-run `composer cs-check`.

**Gate 2 — Static analysis:**
```
composer phpstan
```
If new findings appear: fix or add to the appropriate baseline per project conventions.
Do not suppress without understanding the finding.

**Gate 3 — Dead code:**
```
bin/check-dead-code
```
If new entries: either add `@api` to the symbol or add a baseline entry with an inline
comment. See CLAUDE.md "Dead code audits" section for triage rules.

**Gate 4 — Unbound `getQuery()` chains:**
```
bin/check-getquery-bindings
```
Expected: no new findings. Bimaaji does not use `getQuery()`. If a finding appears, it
was introduced by a side effect of the kernel boot in integration tests — investigate.

**Gate 5 — Composer policy:**
```
composer check-composer-policy
```
Check all modified `composer.json` files: `packages/bimaaji/composer.json` (WP01, WP02),
`packages/routing/src/` (WP01 conditional). `sort-packages`, no `@dev`, no wildcards.

**Gate 6 — Package layers:**
```
bin/check-package-layers
```
Expected: green. Bimaaji is L5; it imports from L0–L4 only. The `WaaseyaaRouter` accessor
lives in L4. No upward imports.

**Gate 7 — Full verify composite:**
```
composer verify
```
This runs all gates that are part of the composite. Confirm exit code is 0.

**Gate 8 — Unit test suite:**
```
./vendor/bin/phpunit --testsuite Unit --no-coverage
```
Confirm `BimaajiServiceProviderTest` (WP01) passes alongside existing unit tests.

**Gate 9 — Integration test suite:**
```
./vendor/bin/phpunit --testsuite Integration --no-coverage
```
Confirm all three new integration tests pass:
- `ApplicationGraphIntegrationTest` (4 methods including `crossMissionGateSc005`)
- `GraphDumpCommandTest` (3 methods)

**Gate 10 — Manual smoke (local only, not CI):**
```
bin/waaseyaa graph:dump
bin/waaseyaa graph:dump --section=routing
bin/waaseyaa list | grep graph
```
Confirm `graph:dump` appears in `bin/waaseyaa list`, and the outputs are valid JSON with
the expected structure.

**Files touched:**
- None expected. If gate failures require fixes, those fixes are made to files already
  owned by WP01–WP03.

**Validation:**
- All 10 gates exit 0 (gates 1–9 are CI; gate 10 is local smoke).
- `composer verify` exit code 0 (C-005, C-006).
- No CI hooks bypassed at any point in this mission's PRs (C-007).

---

### T022 — Archive verification and PR preparation

**Purpose:** Document the verification run results and prepare the PR with correct
traceability signals.

**Steps:**

1. Confirm the branch is up to date with `main`:
   ```
   git fetch origin main
   git log --oneline main..HEAD | head -5
   ```

2. Confirm no `.env`, credentials, or secrets are staged:
   ```
   git diff --name-only HEAD | grep -iE "\.env|secret|credential|token|key" || echo "No secrets found"
   ```

3. Prepare the PR body with the required traceability signals per `docs/specs/workflow.md`:
   - Mission ID: `bimaaji-wakeup-01KS5VEY`
   - WP references: WP01–WP05
   - FR/NFR/SC/C coverage summary
   - SC-005 cross-mission gate note (M2 unblocked)
   - SC-006 `composer verify` green confirmation

   Minimum PR body structure (per `.github/pull_request_template.md`):
   ```
   ## Mission
   bimaaji-wakeup-01KS5VEY — M1 of ai-ecosystem-beta-tightening

   ## Work packages
   WP01 Audit + ServiceProvider scaffold
   WP02 CLI command — graph:dump
   WP03 Booted-pipeline integration test
   WP04 README + spec + docs refresh
   WP05 Cross-mission gate + full verify (this WP)

   ## Requirements covered
   FR-001–FR-013, NFR-001–NFR-004, C-001–C-007, SC-001–SC-006

   ## Cross-mission gate
   SC-005: `crossMissionGateSc005` passes — M2 can resolve `ApplicationGraphGenerator`
   without further bimaaji changes.

   ## Gates
   - [ ] composer verify (C-005, C-006)
   - [ ] bin/check-package-layers (C-001)
   - [ ] bin/check-composer-policy (C-006)
   - [ ] Unit tests green (FR-009)
   - [ ] Integration tests green (FR-010–013)
   - [ ] No CI hooks bypassed (C-007)
   ```

4. Open the PR using `gh pr create` or equivalent. Do not squash-merge until CI is green.

5. After CI passes, squash-merge per `docs/specs/workflow.md`. After merge:
   - Close any linked GitHub issues (`gh issue close <N>` if a tracking issue was filed).
   - Edit the GitHub Release notes if a release is cut alongside this mission.

**Files touched:**
- None (PR preparation is a process step, not a file change).

**Validation:**
- PR body contains mission ID and "bimaaji-wakeup-01KS5VEY".
- CI checks pass before squash-merge.
- No `--no-verify` used on any commit in this mission's branch.

---

## Test strategy

**New test method** (`crossMissionGateSc005` in `ApplicationGraphIntegrationTest`):
- `#[CoversNothing]` — this is a contract/gate test, not a coverage test.
- 3 assertions: `assertInstanceOf(ApplicationGraphGenerator)`, `assertInstanceOf(ApplicationGraph)`,
  `assertGreaterThanOrEqual(1, sections count)`.
- Uses the same kernel and generator instance as WP03's tests (no new boot overhead).

**Full suite verification:**
- `--testsuite Unit` confirms WP01's unit tests still pass.
- `--testsuite Integration` confirms WP02's `GraphDumpCommandTest` + WP03's
  `ApplicationGraphIntegrationTest` (including the new gate method) all pass.

## Definition of Done

- [ ] `crossMissionGateSc005` test method added to `ApplicationGraphIntegrationTest` (SC-005).
- [ ] `crossMissionGateSc005` passes in CI.
- [ ] `composer verify` exits 0 on the final branch state (C-005, C-006, SC-006).
- [ ] `bin/check-package-layers` exits 0 (C-001, C-006).
- [ ] `bin/check-composer-policy` exits 0 (C-006).
- [ ] `./vendor/bin/phpunit --testsuite Unit` exits 0.
- [ ] `./vendor/bin/phpunit --testsuite Integration` exits 0 (all 7 new test methods pass).
- [ ] PR body contains mission ID `bimaaji-wakeup-01KS5VEY` and WP01–WP05 references.
- [ ] No CI hooks bypassed during any commit in this mission's branch (C-007).
- [ ] `bin/waaseyaa graph:dump` works on a clean `composer install` (SC-001, manual smoke).
- [ ] All 6 default sections visible in `graph:dump` output (SC-002, manual smoke).

## Risks and notes

- **C-007 (no hooks bypassed):** Every commit in the mission branch must have been made
  without `--no-verify`. If a pre-commit hook failed during WP01–WP04, the correct
  resolution was to fix the underlying issue and create a NEW commit. Do not amend under
  hook failure.
- **Gate ordering:** Run `composer verify` last in the sequence, not first — it is the
  composite that masks individual gate failures with a single exit code. Individual gates
  (phpstan, dead-code, etc.) give more actionable output when run separately first.
- **Cross-mission contract:** `BimaajiServiceProvider::TAG` is now public API from M1
  onward. Do not rename it in any follow-up without a deprecation cycle. The
  `crossMissionGateSc005` test does not verify the TAG constant directly — the WP01 unit
  test covers that. These two tests together form the full SC-005 proof.
- **Manual smoke is not optional:** Gate 10 in T021 is local-only but must be run before
  opening the PR. A green CI with a broken `bin/waaseyaa graph:dump` indicates a
  test-environment-only issue (e.g. mocked providers that always succeed) that would break
  real consumers.

## Reviewer guidance

The opus reviewer should check:

1. **`#[CoversNothing]` on `crossMissionGateSc005`** — This is correct. A gate test that
   verifies a contract property should not inflate coverage for `ApplicationGraphGenerator`.
   If the implementer used `#[CoversClass(...)]` instead, ask them to change it.
2. **Comment text in the test** — The SC-005 comment must clearly state "M2's first WP must
   be able to resolve ApplicationGraphGenerator from the container without modifying
   packages/bimaaji/." This is a cross-mission contract, not just a "nice to have" comment.
   Missing or vague comment text is a rejection criterion.
3. **All 10 T021 gates** — Ask the implementer to paste the exit codes for all gates in the
   PR description or in a comment. A PR that says "all gates green" without evidence is not
   sufficient for a mission that introduces a new service provider and CLI command.
4. **`bin/waaseyaa graph:dump` manual smoke** — Confirm gate 10 was run locally and the
   output is valid JSON with 6 section keys. The reviewer cannot run this themselves; the
   implementer must provide a snippet of the output in the PR.
5. **No `--no-verify` commits** — Check `git log --format="%H %s" HEAD~10..HEAD` for any
   commit messages that suggest a bypassed hook (common: "wip" commits, "fix lint" commits
   added after a failed hook run). If any `--no-verify` was used, the PR fails C-007.
