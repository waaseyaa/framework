---
work_package_id: WP03
title: Booted-pipeline integration test
dependencies:
- WP01
requirement_refs:
- FR-010
- NFR-001
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T013
- T014
- T015
history: []
authoritative_surface: tests/Integration/PhaseN/Bimaaji/
execution_mode: code_change
owned_files:
- tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php
tags: []
---

## Objective

Create `ApplicationGraphIntegrationTest` — a booted-kernel integration test that proves
the `BimaajiServiceProvider` → container → `ApplicationGraphGenerator` pipeline works
end-to-end over a real (minimal) kernel. The test resolves `ApplicationGraphGenerator`
from the container, calls `generate()`, and asserts that all 6 default sections appear
with non-empty `version` strings and correct shape.

A soft NFR-001 timing assertion (≤ 100 ms median for `generate()` itself) is included as
a documented comment with a `hrtime()` measurement — implemented as a warning log, not a
hard PHPUnit failure, to avoid flakiness on slow CI runners.

## Context

WP01 wires `BimaajiServiceProvider` into `packages/bimaaji/composer.json`. WP03 proves
the wiring works by booting the same test kernel pattern used by `PhaseN/AgentRuntime/`
tests. The test namespace is `Waaseyaa\Tests\Integration\PhaseN\Bimaaji\` (plan §Test
strategy, R7). The test uses `#[CoversNothing]` is NOT appropriate here — it covers
`ApplicationGraphGenerator`. Use `#[CoversClass(ApplicationGraphGenerator::class)]`.

The preferred kernel boot strategy (plan WP03 section) is `ConsoleKernel` with
`APP_ENV=testing`. If that exceeds 200 ms boot time, fall back to a minimal provider
list. The plan documents the fallback but prefers the full approach for realism.

WP02's `GraphDumpCommandTest` will reuse the `buildTestKernel()` helper established in
this WP — WP03 is therefore a blocker for WP02's T011 to be fully wired up.

## Subtasks

### T013 — Establish minimal test kernel boot helper

**Purpose:** Create the `buildTestKernel()` helper (or a static factory method on the
test class) that WP03 and WP02 both rely on. This subtask is the research step — it
examines existing `PhaseN/` tests to find the established pattern before writing any code.

**Steps:**

1. Examine the existing `PhaseN/AgentRuntime/` tests to find the kernel boot pattern:
   ```
   find tests/Integration/PhaseN -name "*.php" | head -10
   grep -rn "ConsoleKernel\|buildKernel\|APP_ENV\|setUp" \
     tests/Integration/PhaseN/ | grep -v "vendor" | head -20
   ```

2. Identify the minimal required provider list for bimaaji:
   - `FoundationServiceProvider` (L0 — provides `SovereigntyConfigInterface`)
   - `EntityServiceProvider` (L1 — provides `EntityTypeManagerInterface`)
   - `RoutingServiceProvider` (L4 — provides `WaaseyaaRouter`)
   - `BimaajiServiceProvider` (L5 — the new provider)
   Find these:
   ```
   grep -rn "class FoundationServiceProvider\|class EntityServiceProvider\|class RoutingServiceProvider" \
     packages/*/src/ServiceProvider/ | head -10
   ```

3. Determine whether `ConsoleKernel` with `APP_ENV=testing` is fast enough:
   ```
   grep -rn "APP_ENV.*testing\|ConsoleKernel" tests/Integration/PhaseN/ | head -5
   ```
   If the existing pattern uses full `ConsoleKernel`, use it. If it uses a minimal
   `ContainerCompiler` bootstrap, match that pattern.

4. Write the `setUp()` or static helper — do not write the full test class yet (T014).
   The helper should return either a container or the kernel instance.

**Files touched:**
- `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` — create
  (stub with `setUp()` only)

**Validation:**
- `grep -n "setUp\|buildKernel" tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php`
  returns lines.
- PHP parse error check: `php -l tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php`

---

### T014 — Implement `ApplicationGraphIntegrationTest` assertions

**Purpose:** Implement the core FR-010 assertions: 6 default sections, non-empty version
strings, correct section key names.

**Steps:**

1. Complete `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace Waaseyaa\Tests\Integration\PhaseN\Bimaaji;

   use PHPUnit\Framework\Attributes\CoversClass;
   use PHPUnit\Framework\Attributes\Test;
   use PHPUnit\Framework\TestCase;
   use Waaseyaa\Bimaaji\ApplicationGraphGenerator;
   use Waaseyaa\Bimaaji\ApplicationGraph;

   #[CoversClass(ApplicationGraphGenerator::class)]
   final class ApplicationGraphIntegrationTest extends TestCase
   {
       private ApplicationGraphGenerator $generator;

       protected function setUp(): void
       {
           // Boot minimal kernel and resolve generator.
           // Pattern from PhaseN/AgentRuntime — adjust to actual boot helper.
           $kernel = /* boot ConsoleKernel with APP_ENV=testing */;
           $this->generator = $kernel->getContainer()->get(ApplicationGraphGenerator::class);
       }

       #[Test]
       public function generatesAllSixDefaultSections(): void // FR-010
       {
           $graph    = $this->generator->generate();
           $sections = $graph->getSections();

           self::assertGreaterThanOrEqual(6, count($sections),
               'Expected at least 6 default sections from BimaajiServiceProvider.');

           $expectedKeys = ['entities', 'routing', 'jsonapi', 'admin', 'sovereignty', 'public_surface'];
           foreach ($expectedKeys as $key) {
               self::assertArrayHasKey($key, $sections,
                   "Expected section key \"{$key}\" to be present in ApplicationGraph.");
           }
       }

       #[Test]
       public function allSectionsHaveNonEmptyVersionStrings(): void // FR-010
       {
           $graph    = $this->generator->generate();
           $sections = $graph->getSections();

           foreach ($sections as $key => $section) {
               $version = $section->getVersion();  // adjust method name if different
               self::assertIsString($version, "Section \"{$key}\" version must be a string.");
               self::assertNotEmpty($version, "Section \"{$key}\" version string must not be empty.");
           }
       }

       #[Test]
       public function sectionShapesMatchProviderContract(): void // FR-010 smoke
       {
           $graph    = $this->generator->generate();
           $sections = $graph->getSections();

           // Smoke-level: each section converts to array without throwing.
           foreach ($sections as $key => $section) {
               $arr = $section->toArray();
               self::assertIsArray($arr,
                   "Section \"{$key}\" toArray() must return an array.");
               // Key 'version' must be present at top level of toArray output
               self::assertArrayHasKey('version', $arr,
                   "Section \"{$key}\" toArray() must include 'version' key.");
           }
       }
   }
   ```

2. Resolve the actual `GraphSection` API:
   ```
   grep -n "function getVersion\|function toArray\|function getSections" \
     packages/bimaaji/src/GraphSection.php \
     packages/bimaaji/src/ApplicationGraph.php 2>/dev/null
   ```
   Adjust method names in the test to match.

3. Resolve the actual `ApplicationGraphGenerator::generate()` return type:
   ```
   grep -n "function generate" packages/bimaaji/src/ApplicationGraphGenerator.php
   ```

**Files touched:**
- `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` — edit (add 3 test methods)

**Validation:**
- `./vendor/bin/phpunit tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php --no-coverage`
  — all 3 methods pass.

---

### T015 — NFR-001 timing assertion (soft)

**Purpose:** Document and measure the `generate()` performance budget of ≤ 100 ms median
on the test kernel, as a soft warning (not a hard PHPUnit failure).

**Steps:**

1. Add a timing method to `ApplicationGraphIntegrationTest`:

   ```php
   #[Test]
   public function generateCompletesWithinNfrBudget(): void // NFR-001
   {
       // NFR-001: ApplicationGraphGenerator::generate() should complete in ≤ 100 ms median
       // on a clean test kernel. This is a SOFT assertion — it logs a warning rather than
       // failing, to avoid flakiness on slow CI runners. Adjust the budget comment if the
       // median shifts significantly.
       $start = hrtime(true);
       $this->generator->generate();
       $elapsedMs = (hrtime(true) - $start) / 1_000_000;

       $budget = 100.0; // ms — NFR-001 threshold
       if ($elapsedMs > $budget) {
           fwrite(STDERR, sprintf(
               "\n[NFR-001 WARNING] ApplicationGraphGenerator::generate() took %.2f ms" .
               " (budget: %.0f ms). Investigate provider performance.\n",
               $elapsedMs,
               $budget,
           ));
       }

       // Hard assertion at 5x budget to catch catastrophic regressions.
       self::assertLessThan(
           $budget * 5,
           $elapsedMs,
           sprintf('NFR-001 HARD LIMIT: generate() took %.2f ms (limit: %.0f ms).', $elapsedMs, $budget * 5),
       );
   }
   ```

2. Run the test and check the actual elapsed time:
   ```
   ./vendor/bin/phpunit tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php \
     --filter generateCompletesWithinNfrBudget --no-coverage 2>&1
   ```
3. If the elapsed time is > 100 ms on the first run, check whether the overhead is in
   kernel boot vs. `generate()`:
   - If kernel boot: the timing is measured only around `generate()`, so the test
     correctly isolates the budget to the generator.
   - If `generate()` itself: document the actual median in a comment and consider
     whether one of the 6 providers is doing expensive I/O (file reads, etc.) that
     should be mocked in tests.
4. If a provider is performing expensive I/O in tests, add a note in the test comment
   but do NOT modify the provider (C-003). Instead, note as a WP05 risk item.

**Files touched:**
- `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` — edit (add timing test)

**Validation:**
- `generateCompletesWithinNfrBudget` passes (hard assertion at 500 ms).
- Actual elapsed time logged — confirm it is < 100 ms or document if it is not.
- `./vendor/bin/phpunit --testsuite Integration` — no regressions in other tests.

---

## Test strategy

**Integration test** (`tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php`):
- `generatesAllSixDefaultSections` (FR-010): asserts ≥ 6 sections, each expected key present.
- `allSectionsHaveNonEmptyVersionStrings` (FR-010): iterates all sections, asserts non-empty string.
- `sectionShapesMatchProviderContract` (FR-010 smoke): `toArray()` returns array with `version` key.
- `generateCompletesWithinNfrBudget` (NFR-001): soft 100 ms budget, hard 500 ms limit.

Namespace: `Waaseyaa\Tests\Integration\PhaseN\Bimaaji\`
PHPUnit attributes: `#[CoversClass(ApplicationGraphGenerator::class)]`, `#[Test]`.
No `#[CoversNothing]` — this test covers `ApplicationGraphGenerator`.

## Definition of Done

- [ ] `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` exists
  with 4 test methods (FR-010, NFR-001).
- [ ] `generatesAllSixDefaultSections` passes — 6 section keys present (FR-010).
- [ ] `allSectionsHaveNonEmptyVersionStrings` passes (FR-010).
- [ ] `sectionShapesMatchProviderContract` passes — smoke-level shape check (FR-010).
- [ ] `generateCompletesWithinNfrBudget` passes — hard 500 ms limit (NFR-001).
- [ ] Actual measured `generate()` time is documented in a test comment.
- [ ] `buildTestKernel()` pattern is reusable by WP02's `GraphDumpCommandTest` (WP dependency).
- [ ] `./vendor/bin/phpunit --testsuite Integration` passes with no regressions.

## Risks and notes

- **R7 (PhaseN/ namespace):** No phase number assignment needed — `PhaseN/` is the
  conventional catch-all (confirmed in plan). Test namespace is
  `Waaseyaa\Tests\Integration\PhaseN\Bimaaji\`.
- **Kernel boot overhead vs. generator timing:** The timing assertion measures only
  `generate()` — kernel boot happens in `setUp()`. If `setUp()` is slow, it does not
  affect the NFR-001 measurement.
- **Provider I/O:** If any of the 6 default providers reads the filesystem or makes HTTP
  calls during `provide()`, the 100 ms budget may be exceeded in CI. C-003 prohibits
  changing providers. If this is found, document it in the test comment and add a follow-up
  issue.
- **WP02 dependency:** `GraphDumpCommandTest::buildTestKernel()` must reuse this WP's
  pattern. Ensure the helper is either a shared trait or a clearly documented `setUp()` that
  WP02 can copy verbatim.

## Reviewer guidance

The opus reviewer should check:

1. **Kernel reuse** — Confirm that the `setUp()` kernel boot pattern in this test is the
   same as (or refactored into) what `GraphDumpCommandTest` will use. Inconsistent boot
   patterns between the two tests indicate that WP02 did not properly depend on WP03.
2. **Timing isolation** — The NFR-001 measurement must wrap only `$this->generator->generate()`,
   not the full `setUp()`. If `hrtime()` is called before `setUp()`, the kernel boot is
   included in the measurement and the budget comparison is invalid.
3. **Section key names** — Confirm the 6 expected keys (`entities`, `routing`, `jsonapi`,
   `admin`, `sovereignty`, `public_surface`) exactly match the `getKey()` return values of
   the 6 providers. A mismatch between test expectation and actual key causes a false green
   if the test only checks `assertArrayHasKey` and the key is absent.
4. **`#[CoversClass]` attribute** — Must be `ApplicationGraphGenerator::class`, not
   `BimaajiServiceProvider::class` (WP01 covers the provider). Wrong covers class causes
   wrong coverage attribution.
5. **Hard vs. soft NFR assertion** — The hard limit (500 ms) must be a `self::assertLessThan`
   that will actually fail CI. The soft warning (100 ms) must be `fwrite(STDERR, ...)` only,
   never `self::fail()`.
