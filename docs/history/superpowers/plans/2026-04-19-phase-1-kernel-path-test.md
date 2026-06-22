# Phase 1 — Kernel-Path Integration Test Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close Phase 1 of the Groups-extraction arc (framework#1315 part A) by aligning the arc-close spec with the already-landed kernel-path test, draining the one remaining bootstrap-variant (`MinimalTestKernel`), pinning the alpha.148 regression shape with a deliberate-mutation guard, and forbidding new kernel subclasses in `tests/**` via architectural assertion.

**Status snapshot (2026-04-20):** Task 1 shipped as PR [#1316](https://github.com/jonesrussell/waaseyaa/pull/1316) (merged 2026-04-19 via commit `9026a3a9`, then corrected in follow-up commit `2190c589`). Tasks 2–4 are open.

**Architecture:** The kernel-path materialization test already exists at `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php` (commit `070970de`, 2026-04-19). It uses the real-kernel pattern: anonymous subclass exposing `publicBoot()`, real `projectRoot` under `sys_get_temp_dir()` with written `config/waaseyaa.php` + `config/entity-types.php`, and asserts `sqlite_master` after calling `boot()`. Phase 1 is not greenfield — it is a cluster of cleanups and guards around that landed test. Four PRs, strictly ordered: spec amendment → `MinimalTestKernel` drain → deliberate-mutation regression guard → architectural test forbidding new kernel subclasses.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Symfony 7, Doctrine DBAL (SQLite in-memory via `DBALDatabase::createSqlite(':memory:')`), Waaseyaa entity system (`EntityTypeManager::addBundleFields()`, `FieldDefinitionRegistry::bundleNamesFor()`, `SqlSchemaHandler::shouldProcessBundles()`).

---

## File Structure

**Modify (PR 1 — spec amendment, docs-only):**
- `docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md` — correct Phase 1 scope/path, update "Why first" + "Code smells" + "Exit criterion", update Phase → Issue mapping footer.

**Modify (PR 2 — MinimalTestKernel drain):**
- `tests/Integration/Phase17/KernelBootValidationTest.php` — delete the `MinimalTestKernel` class, convert the six tests to the real-kernel pattern used in `KernelBundleSubtableMaterializationTest`.

**Create (PR 3 — deliberate-mutation regression guard):**
- `packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php` — pins the alpha.148 regression: when `bundleEnumerator` is null, `shouldProcessBundles()` + `registeredBundlesFor()` must fall back to `FieldDefinitionRegistry::bundleNamesFor()`.

**Modify (PR 3 — documented mutation recipe):**
- `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php` — extend the class docblock with a "Deliberate mutation recipe" section that names the two guarded branches and how to observe the primary test failing.

**Create (PR 4 — architectural guard):**
- `tests/Architecture/NoKernelSubclassesInTestsTest.php` — scans `tests/**` for classes extending `AbstractKernel | HttpKernel | ConsoleKernel`; anonymous classes are excluded by design (the real-kernel pattern).

**Does not touch:** production code, alpha-release tags, packaged-form CI (that is Phase 2), `#1313` guard (that is Phase 3), `waaseyaa/groups` or Minoo.

---

### Ordering rationale (do not reshuffle)

1. **Spec amendment is first** so Plans 2–7 reference the corrected Phase 1 scope. A docs-only PR is cheap to land and unblocks downstream plan drafting.
2. **`MinimalTestKernel` drain precedes the arch-test** — with it drained, PR 4's guard has nothing to allowlist. Refactor is small/surgical (~25 lines of helper class, 6 tests in one file, pattern already codified in the sibling materialization test), so option **(ii) prereq PR** beats option **(i) baseline/allowlist + drain ticket**.
3. **Deliberate-mutation guard lands before the arch-test** so the guard-protecting-the-test and the arch-rule-guarding-both land in a stable order; if the arch-test surfaces a false positive it does not block the mutation guard.

---

## Task 1 — PR 1: Amend arc-close spec to reference the landed test ✅ SHIPPED as [#1316](https://github.com/jonesrussell/waaseyaa/pull/1316)

**Files:**
- Modify: `docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md` — Phase 1 section (lines ~65–80), ordering flags paragraph (~215), footer Phase → Issue table (~237–238).

**Branch:** `docs/1315-phase-1-spec-amendment`

**Shipped:** Merged 2026-04-19 as commit `9026a3a9`; follow-up commit `2190c589` corrected the alpha-chain range from 148–151 to 148–152 in the Phase 1 code-smells paragraph. Checkboxes retained below as an audit trail.

- [x] **Step 1: Create branch from main**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b docs/1315-phase-1-spec-amendment
```

- [x] **Step 2: Replace Phase 1 section**

Open `docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md`. Replace the block from `## Phase 1 — framework#1315 part A: in-tree kernel-path integration test` through the separator before `## Phase 2` with:

```markdown
## Phase 1 — framework#1315 part A: in-tree kernel-path integration test

**Repo:** waaseyaa.
**Status:** core assertion already landed as `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php` (commit `070970de`, 2026-04-19). Phase 1 is the cluster of cleanups and guards around that landed test, not a greenfield test write.

**Scope:** Four artefacts, strictly ordered:
1. **This spec amendment** (docs-only PR, first) — aligns the spec with the already-landed path.
2. **Drain `MinimalTestKernel`** in `tests/Integration/Phase17/KernelBootValidationTest.php` to the real-kernel pattern (anonymous subclass + real `projectRoot` + `config/entity-types.php`), so the architectural guard in artefact 4 has nothing to allowlist.
3. **Deliberate-mutation regression guard** — a targeted unit test for `SqlSchemaHandler`'s registry-fallback branch (`shouldProcessBundles()` + `registeredBundlesFor()`), plus a docblock mutation recipe on the primary materialization test.
4. **Architectural test** rejecting new `extends AbstractKernel | HttpKernel | ConsoleKernel` under `tests/**`. Anonymous classes (the real-kernel pattern) are excluded by design.

The primary materialization test boots via anonymous subclass of `AbstractKernel` that exposes `publicBoot()`, constructs a real on-disk `projectRoot` under `sys_get_temp_dir()` with written `config/waaseyaa.php` + `config/entity-types.php`, registers a test bundle field through `EntityTypeManager::addBundleFields()`, triggers storage resolution, and asserts `sqlite_master` contains `{base}__{bundle}` with the declared columns. A companion test asserts that registering no bundles creates no subtables.

**Why first:** every correctness claim downstream rides on this harness. Without the guards in artefacts 2–4, the five-release bootstrap-variant failure mode can re-enter the suite undetected.

**Code smells called out:**
- **Bootstrap-variant proliferation** was the root smell — parallel "kernel-ish" helpers (mocked kernel, partial boot, in-memory manifest stubs) masked the alpha.148–152 chain. Artefact 4 makes re-introduction a hard error; artefact 2 drains the one pre-existing variant so the guard starts with no allowlist.
- **Registry-fallback branch is implicit.** `SqlSchemaHandler::shouldProcessBundles()` + `registeredBundlesFor()` falls back to `FieldDefinitionRegistry::bundleNamesFor()` only when `bundleEnumerator` is null — the exact shape alpha.148 got wrong. Artefact 3 pins it.

**Exit criteria (merge gates):**
1. `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php` runs green.
2. Deliberately swapping `bundleNamesFor()` for an always-empty fallback (per the docblock recipe added in artefact 3) makes the primary assertion fail with `kernel_test_widget__gizmo must be materialized` — proves the assertion is load-bearing.
3. `tests/Integration/Phase17/KernelBootValidationTest.php` runs green after the `MinimalTestKernel` drain (artefact 2).
4. The architectural test (artefact 4) runs green with zero allowlisted entries.

**Standalone PR?** No — four PRs, in the order above.
```

- [x] **Step 3: Update the ordering-flags paragraph and Phase → Issue mapping**

In the same file, find the paragraph under **Ordering flags** / cross-cutting notes that reads `Phase 1 must not add a fourth variant` (around line 215). Replace it with:

```markdown
4. **Bootstrap-variant proliferation** in the test suite (root cause of the five-release chain). Phase 1 drains the one pre-existing variant (`MinimalTestKernel` in `tests/Integration/Phase17/KernelBootValidationTest.php`) and installs an architectural assertion that rejects new `extends AbstractKernel | HttpKernel | ConsoleKernel` under `tests/**`. The arch-test starts with an empty allowlist; any future "just one more variant" lands a red test.
```

Then find the Phase → Issue mapping table footer and update Phase 1's PR count (around line 237). Change the Phase 1 row from `| 1 | waaseyaa | framework#1315 | 1 |` to:

```markdown
| 1 | waaseyaa | framework#1315 | 4 (spec amendment, MinimalTestKernel drain, deliberate-mutation guard, arch-test) |
```

- [x] **Step 4: Verify the amendment is self-consistent**

Run the following greps and confirm each produces exactly the expected matches.

```bash
grep -n "tests/Integration/Kernel/KernelBundleFieldTest" docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md
```

Expected: no matches (the old placeholder path is gone).

```bash
grep -n "packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php" docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md
```

Expected: at least two matches (Phase 1 scope, Phase 1 exit criterion 1).

```bash
grep -n "070970de" docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md
```

Expected: one match (the commit reference in Phase 1 status).

- [x] **Step 5: Commit and push**

```bash
git add docs/history/superpowers/specs/2026-04-19-groups-extraction-arc-close-design.md
git commit -m "$(cat <<'EOF'
docs(#1315): amend arc-close spec to reference landed kernel-path test

Phase 1 was drafted assuming the kernel-path integration test was
greenfield work; commit 070970de landed the core assertion as
packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php
on 2026-04-19, before this spec was written. Amend Phase 1 to reflect
what actually needs to happen: drain the one pre-existing bootstrap
variant (MinimalTestKernel), add a deliberate-mutation regression
guard for SqlSchemaHandler's registry-fallback branch, and install
an architectural test rejecting new `extends AbstractKernel | HttpKernel
| ConsoleKernel` under tests/**. Update exit criteria and Phase → Issue
mapping accordingly.

Lands first so Plans 2-7 reference the corrected spec.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
git push -u origin docs/1315-phase-1-spec-amendment
gh pr create --title "docs(#1315): amend arc-close spec to reference landed kernel-path test" --body "$(cat <<'EOF'
## Summary
- Phase 1 scope realigned with the already-landed kernel-path test (commit 070970de).
- Exit criteria reframed as four merge gates matching the four artefacts.
- Phase → Issue mapping updated from 1 PR → 4 PRs for Phase 1.

## Test plan
- [ ] Spec reads coherently end-to-end.
- [ ] No references to the old placeholder path `tests/Integration/Kernel/KernelBundleFieldTest`.
- [ ] Ordering flags paragraph reflects the arch-test + drain sequencing.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR opened on GitHub. Copy its URL.

- [x] **Step 6: Wait for review + merge**

Do **not** proceed to Task 2 until this PR is merged to `main`. Downstream tasks amend code referenced by the spec; if the spec is still under review, wording changes can ripple.

_Merged 2026-04-19. Task 2 is now unblocked._

---

## Task 2 — PR 2: Drain `MinimalTestKernel` to the real-kernel pattern

**Files:**
- Modify: `tests/Integration/Phase17/KernelBootValidationTest.php` — delete the `MinimalTestKernel` class (lines 134–158), rewrite all six test methods to use the real-kernel pattern from `KernelBundleSubtableMaterializationTest`.

**Branch:** `refactor/1315-drain-minimal-test-kernel`

**Refactor target pattern (canonical, from `KernelBundleSubtableMaterializationTest` lines 89–95):**

```php
$kernel = new class($this->projectRoot) extends AbstractKernel {
    public function publicBoot(): void
    {
        $this->boot();
    }
};
$kernel->publicBoot();
```

- [ ] **Step 1: Create branch from main**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b refactor/1315-drain-minimal-test-kernel
```

- [ ] **Step 2: Rewrite the test file in one pass**

Overwrite `tests/Integration/Phase17/KernelBootValidationTest.php` with the following content (existing MinimalTestKernel class deleted, tests rewritten around on-disk config files + real boot).

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase17;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

/**
 * Validates the content-type guard in AbstractKernel::boot() via the real
 * kernel path. Each test writes a real `config/entity-types.php` under a
 * temp projectRoot, instantiates an anonymous subclass of AbstractKernel
 * exposing publicBoot(), and calls boot() — no MinimalTestKernel, no
 * partial boot(), no hand-wired EntityTypeManager.
 *
 * Bootstrap-variant policy: this file must use the anonymous-subclass +
 * real-projectRoot pattern codified in KernelBundleSubtableMaterializationTest.
 * See tests/Architecture/NoKernelSubclassesInTestsTest for enforcement.
 */
#[CoversClass(AbstractKernel::class)]
final class KernelBootValidationTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_boot_validation_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage/framework', 0755, true);

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:'];",
        );
    }

    protected function tearDown(): void
    {
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);

        if (!is_dir($this->projectRoot)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    private function writeEntityTypes(string $body): void
    {
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn {$body};\n");
    }

    private function newKernel(): AbstractKernel
    {
        return new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
    }

    #[Test]
    public function bootHaltsWithDefaultTypeMissingWhenNoTypesRegistered(): void
    {
        $this->writeEntityTypes('[]');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_TYPE_MISSING/');

        $this->newKernel()->publicBoot();
    }

    #[Test]
    public function exceptionIncludesRemediationMessage(): void
    {
        $this->writeEntityTypes('[]');

        try {
            $this->newKernel()->publicBoot();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('DEFAULT_TYPE_MISSING', $e->getMessage());
            self::assertStringContainsString('content type', $e->getMessage());
        }
    }

    #[Test]
    public function bootSucceedsWithOneRegisteredContentType(): void
    {
        $this->writeEntityTypes(<<<'PHP'
[
    new \Waaseyaa\Entity\EntityType(
        id: 'note',
        label: 'Note',
        class: \Waaseyaa\Note\Note::class,
        keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
    ),
]
PHP);

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        self::assertTrue($kernel->getEntityTypeManager()->hasDefinition('note'));
    }

    #[Test]
    public function bootSucceedsWithMultipleContentTypes(): void
    {
        $this->writeEntityTypes(<<<'PHP'
[
    new \Waaseyaa\Entity\EntityType(id: 'note', label: 'Note', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id']),
    new \Waaseyaa\Entity\EntityType(id: 'article', label: 'Article', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id']),
]
PHP);

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        self::assertCount(2, $kernel->getEntityTypeManager()->getDefinitions());
    }

    #[Test]
    public function bootHaltsWithDefaultTypeDisabledWhenAllTypesDisabled(): void
    {
        $this->writeEntityTypes(<<<'PHP'
[
    new \Waaseyaa\Entity\EntityType(id: 'note', label: 'Note', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id']),
]
PHP);

        (new EntityTypeLifecycleManager($this->projectRoot))->disable('note', 'test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEFAULT_TYPE_DISABLED/');

        $this->newKernel()->publicBoot();
    }

    #[Test]
    public function bootSucceedsWhenOnlyOneOfTwoTypesIsDisabled(): void
    {
        $this->writeEntityTypes(<<<'PHP'
[
    new \Waaseyaa\Entity\EntityType(id: 'note', label: 'Note', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id']),
    new \Waaseyaa\Entity\EntityType(id: 'article', label: 'Article', class: \Waaseyaa\Note\Note::class, keys: ['id' => 'id']),
]
PHP);

        (new EntityTypeLifecycleManager($this->projectRoot))->disable('article', 'test');

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        self::assertTrue($kernel->getEntityTypeManager()->hasDefinition('note'));
    }
}
```

- [ ] **Step 3: Run the rewritten test file — must pass**

```bash
./vendor/bin/phpunit tests/Integration/Phase17/KernelBootValidationTest.php
```

Expected: `OK (6 tests, N assertions)`. Every test exercises the real `boot()` path.

If any test fails: DO NOT adapt the test to pass by partial-booting. The point of the drain is that every test uses `publicBoot()`. If a test genuinely cannot be expressed under full boot, stop and surface it — that is a signal the test was asserting something `boot()` does not expose, and the plan needs revision.

- [ ] **Step 4: Confirm `MinimalTestKernel` is gone**

```bash
grep -rnE 'class [A-Za-z_][A-Za-z0-9_]*[[:space:]]+extends[[:space:]]+(AbstractKernel|HttpKernel|ConsoleKernel)\b' tests/
```

Expected: zero matches. This is the same anchored named-class pattern used in Task 4 Step 2, so anonymous subclasses remain intentionally out of scope.

- [ ] **Step 5: Run the full Phase17 suite to catch collateral damage**

```bash
./vendor/bin/phpunit --testsuite Integration
```

Expected: all integration tests green. If anything else still references `MinimalTestKernel`, the autoload failure surfaces here.

- [ ] **Step 6: Commit and push**

```bash
git add tests/Integration/Phase17/KernelBootValidationTest.php
git commit -m "$(cat <<'EOF'
refactor(#1315): drain MinimalTestKernel to the real-kernel pattern

MinimalTestKernel was a named subclass of AbstractKernel exposing a
partial bootForTest() that skipped database, manifest, providers, and
access policies. It was the last remaining bootstrap-variant helper
in tests/ — the root smell that masked the alpha.148-152 adoption
chain failure. Drain it now so the architectural guard landing next
starts with an empty allowlist.

Each test is rewritten to the canonical pattern codified in
KernelBundleSubtableMaterializationTest: anonymous subclass of
AbstractKernel exposing publicBoot(), real projectRoot under
sys_get_temp_dir(), real config/entity-types.php written per test.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
git push -u origin refactor/1315-drain-minimal-test-kernel
gh pr create --title "refactor(#1315): drain MinimalTestKernel to real-kernel pattern" --body "$(cat <<'EOF'
## Summary
- Deletes the named MinimalTestKernel helper class.
- Rewrites all six KernelBootValidationTest tests to boot via anonymous subclass + real projectRoot + real config/entity-types.php.
- Prerequisite to the architectural guard in the next PR — starts its allowlist at zero.

## Test plan
- [ ] `./vendor/bin/phpunit tests/Integration/Phase17/KernelBootValidationTest.php` passes all six tests.
- [ ] `./vendor/bin/phpunit --testsuite Integration` passes.
- [ ] `grep -rnE 'class [A-Za-z_][A-Za-z0-9_]*[[:space:]]+extends[[:space:]]+(AbstractKernel|HttpKernel|ConsoleKernel)\b' tests/` returns zero matches.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: PR opened. Wait for merge before Task 3.

---

## Task 3 — PR 3: Deliberate-mutation regression guard for the registry-fallback branch

**Files:**
- Create: `packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php` — pins the two-part fallback: `shouldProcessBundles()` returns true only when both `fieldRegistry` and `bundleEntityType` are non-null, and `registeredBundlesFor()` uses `FieldDefinitionRegistry::bundleNamesFor()` when `bundleEnumerator` is null.
- Modify: `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php` — extend the class docblock with a "Deliberate mutation recipe" section.

**Branch:** `test/1315-sqlschemahandler-registry-fallback-guard`

**Rationale recap (do not re-derive):** In alpha.148, `SqlSchemaHandler` was changed so the bundle-subtable loop ran only when a `bundleEnumerator` was supplied. `FieldDefinitionRegistry::bundleNamesFor()` was the default source before that change; after, it was orphaned. Consumers registering bundle fields through `addBundleFields()` (registry-only, no enumerator) silently stopped materializing subtables. The fix (in a later alpha) re-wired the registry as the default source. This test pins that shape so a future refactor cannot re-orphan it.

- [ ] **Step 1: Create branch from main (after PR 2 is merged)**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b test/1315-sqlschemahandler-registry-fallback-guard
```

- [ ] **Step 2: Write the failing unit test**

Create `packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

/**
 * Pins the alpha.148 regression shape.
 *
 * The bundle-subtable materialization loop has two wire-ups that must
 * agree:
 *   1. shouldProcessBundles() — fieldRegistry non-null AND bundleEntityType non-null.
 *   2. registeredBundlesFor() — falls back to FieldDefinitionRegistry::bundleNamesFor()
 *      when the explicit bundleEnumerator is null.
 *
 * In alpha.148, (2) was inverted: the loop ran only when an explicit
 * bundleEnumerator was supplied. addBundleFields()-registered bundles
 * (which populate the registry but do not supply an enumerator) silently
 * stopped producing subtables. This test asserts the post-fix behavior
 * at unit level so a refactor cannot re-orphan the registry fallback
 * without turning this test red.
 */
#[CoversClass(SqlSchemaHandler::class)]
final class SqlSchemaHandlerRegistryFallbackTest extends TestCase
{
    #[Test]
    public function registryPopulationDrivesSubtableMaterializationWithoutEnumerator(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $entityType = new EntityType(
            id: 'widget',
            label: 'Widget',
            class: \stdClass::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name'],
            bundleEntityType: 'widget_type',
        );

        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('widget', 'gizmo', [
            'gizmo_code' => new FieldDefinition(
                name: 'gizmo_code',
                type: 'string',
                targetEntityTypeId: 'widget',
                targetBundle: 'gizmo',
            ),
        ]);

        $handler = new SqlSchemaHandler(
            entityType: $entityType,
            database: $database,
            fieldRegistry: $registry,
            bundleEnumerator: null, // explicit — this is the branch under test
        );
        $handler->ensureTable();

        $connection = $database->getConnection();
        $subtableExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'widget__gizmo'",
        );

        self::assertSame(
            1,
            $subtableExists,
            'SqlSchemaHandler must materialize bundle subtables using FieldDefinitionRegistry::bundleNamesFor() when no explicit bundleEnumerator is supplied. This is the registry-fallback branch that alpha.148 orphaned.',
        );
    }

    #[Test]
    public function noBundleEntityTypeSkipsBundleLoopEvenWithPopulatedRegistry(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $entityType = new EntityType(
            id: 'flat_thing',
            label: 'Flat Thing',
            class: \stdClass::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
            // No bundleEntityType — shouldProcessBundles() must return false.
        );

        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('flat_thing', 'phantom', [
            'ghost_field' => new FieldDefinition(
                name: 'ghost_field',
                type: 'string',
                targetEntityTypeId: 'flat_thing',
                targetBundle: 'phantom',
            ),
        ]);

        $handler = new SqlSchemaHandler(
            entityType: $entityType,
            database: $database,
            fieldRegistry: $registry,
            bundleEnumerator: null,
        );
        $handler->ensureTable();

        $connection = $database->getConnection();
        $unwanted = $connection->fetchAllAssociative(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'flat_thing__%'",
        );

        self::assertSame(
            [],
            $unwanted,
            'shouldProcessBundles() must return false when the entity type has no bundleEntityType, regardless of registry contents.',
        );
    }

    #[Test]
    public function nullRegistrySkipsBundleLoopEvenWithBundleEntityType(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $entityType = new EntityType(
            id: 'orphan',
            label: 'Orphan',
            class: \stdClass::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'type'],
            bundleEntityType: 'orphan_type',
        );

        $handler = new SqlSchemaHandler(
            entityType: $entityType,
            database: $database,
            fieldRegistry: null,
            bundleEnumerator: null,
        );
        $handler->ensureTable();

        $connection = $database->getConnection();
        $baseExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'orphan'",
        );
        $unwanted = $connection->fetchAllAssociative(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'orphan__%'",
        );

        self::assertSame(1, $baseExists);
        self::assertSame(
            [],
            $unwanted,
            'shouldProcessBundles() must return false when fieldRegistry is null, matching pre-bundle-scoped behavior.',
        );
    }
}
```

- [ ] **Step 3: Run the test — must pass on current codebase**

```bash
./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php
```

Expected: `OK (3 tests, N assertions)`. If the first test fails here, the regression has already re-entered — stop and surface.

- [ ] **Step 4: Verify the mutation — flip the fallback to always-empty, confirm failure**

This step is a human-debug aid to prove the test is load-bearing; it is not the functional guard. The CI contract is the test suite itself (`SqlSchemaHandlerRegistryFallbackTest` plus `KernelBundleSubtableMaterializationTest`). Temporarily edit `packages/entity-storage/src/SqlSchemaHandler.php` so that `registeredBundlesFor()` returns an empty array when `bundleEnumerator` is null:

```php
private function registeredBundlesFor(EntityTypeInterface $type): iterable
{
    if ($this->bundleEnumerator !== null) {
        return ($this->bundleEnumerator)($type);
    }

    return []; // MUTATION: pretend the registry fallback is gone.
}
```

Run:

```bash
./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php::registryPopulationDrivesSubtableMaterializationWithoutEnumerator
```

Expected: FAIL with `SqlSchemaHandler must materialize bundle subtables using FieldDefinitionRegistry::bundleNamesFor()...`.

Then **revert the mutation** with:

```bash
git checkout -- packages/entity-storage/src/SqlSchemaHandler.php
```

Re-run the test — must pass again.

- [ ] **Step 5: Add the deliberate-mutation recipe to the primary test's docblock**

Edit `packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php`. Append the following block to the class docblock, before the closing `*/` on line 29:

```
 *
 * Deliberate mutation recipe (Phase 1 exit criterion 2). To prove the
 * assertion is load-bearing, temporarily edit
 * packages/entity-storage/src/SqlSchemaHandler.php::registeredBundlesFor()
 * so its null-bundleEnumerator branch returns `[]` instead of calling
 * `$this->fieldRegistry->bundleNamesFor($type->id())`. Re-run this
 * test: `registeredBundleFieldsMaterializeSubtableViaKernelPath` must
 * fail with "kernel_test_widget__gizmo must be materialized". This is
 * the alpha.148 shape; a unit-level companion
 * (SqlSchemaHandlerRegistryFallbackTest) pins the same branch so the
 * kernel-path test is not the sole guard.
```

- [ ] **Step 6: Re-run all affected tests**

```bash
./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php \
  packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php
```

Expected: both files pass. Total: 3 + 2 = 5 tests green.

- [ ] **Step 7: Commit and push**

```bash
git add packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php \
        packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php
git commit -m "$(cat <<'EOF'
test(#1315): pin SqlSchemaHandler registry-fallback branch

alpha.148 shipped a SqlSchemaHandler where the bundle-subtable loop
ran only when an explicit bundleEnumerator was supplied; consumers
registering bundle fields through addBundleFields() (registry-only)
silently stopped producing subtables. The fix in a later alpha wired
FieldDefinitionRegistry::bundleNamesFor() as the default source when
bundleEnumerator is null. This test pins that shape at unit level so
a future refactor cannot re-orphan the registry fallback without
turning a test red.

Complements KernelBundleSubtableMaterializationTest (the kernel-path
end-to-end assertion) with a targeted branch-level guard, and extends
that test's docblock with a deliberate-mutation recipe — Phase 1 exit
criterion 2.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
git push -u origin test/1315-sqlschemahandler-registry-fallback-guard
gh pr create --title "test(#1315): pin SqlSchemaHandler registry-fallback branch" --body "$(cat <<'EOF'
## Summary
- New unit test SqlSchemaHandlerRegistryFallbackTest pinning the three cells of shouldProcessBundles() + registeredBundlesFor().
- Docblock on the primary materialization test extended with a deliberate-mutation recipe (Phase 1 exit criterion 2).

## Test plan
- [ ] `./vendor/bin/phpunit packages/entity-storage/tests/Unit/SqlSchemaHandlerRegistryFallbackTest.php` passes (3 tests).
- [ ] The tests themselves are green by default; the optional mutation recipe is a human verification aid and produces a red test when applied, green again after revert.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Wait for merge before Task 4.

---

## Task 4 — PR 4: Architectural test forbidding kernel subclasses under `tests/**`

**Files:**
- Create: `tests/Architecture/NoKernelSubclassesInTestsTest.php` — scans `tests/**` for `class … extends AbstractKernel | HttpKernel | ConsoleKernel`, fails if any match. Anonymous classes are excluded (they do not appear as `class Foo extends Bar` in the source).

**Branch:** `test/1315-arch-no-kernel-subclasses`

- [ ] **Step 1: Create branch from main (after PR 3 is merged)**

```bash
cd /home/fsd42/dev/waaseyaa
git checkout main
git pull --ff-only
git checkout -b test/1315-arch-no-kernel-subclasses
```

- [ ] **Step 2: Confirm the baseline is already empty**

```bash
grep -rnE 'class [A-Za-z_][A-Za-z0-9_]*[[:space:]]+extends[[:space:]]+(AbstractKernel|HttpKernel|ConsoleKernel)\b' tests/
```

Expected: zero matches. (If this returns any lines, Task 2 did not drain everything — stop and file a follow-up; do not proceed by allowlisting.)

- [ ] **Step 3: Write the failing architectural test**

Create `tests/Architecture/NoKernelSubclassesInTestsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Forbids named kernel subclasses under tests/**.
 *
 * Bootstrap-variant proliferation — parallel "kernel-ish" helpers
 * (mocked kernel, partial boot, in-memory manifest stubs) — was the
 * root smell that masked the alpha.148–152 adoption-chain failure.
 * The canonical pattern for kernel-path tests is the anonymous
 * subclass exposing publicBoot():
 *
 *     $kernel = new class($projectRoot) extends AbstractKernel {
 *         public function publicBoot(): void { $this->boot(); }
 *     };
 *     $kernel->publicBoot();
 *
 * Anonymous classes are NOT matched by this guard (they appear as
 * `new class(...) extends Foo` in source, not `class X extends Foo`).
 * Named subclasses are — they are the drift shape this rule prevents.
 *
 * If you genuinely need a named subclass (e.g. a shared KernelTestCase
 * base), place it in a non-tests/ location (packages/testing/src/ or a
 * dedicated testing/ directory with composer autoload-dev registration)
 * and import it. Then this guard still holds.
 */
#[CoversNothing]
final class NoKernelSubclassesInTestsTest extends TestCase
{
    private const TESTS_ROOT = __DIR__ . '/..';

    private const FORBIDDEN_PARENTS = [
        'AbstractKernel',
        'HttpKernel',
        'ConsoleKernel',
    ];

    #[Test]
    public function noNamedKernelSubclassesExistUnderTests(): void
    {
        $pattern = '/^\s*(?:final\s+|abstract\s+)?class\s+[A-Za-z_][A-Za-z0-9_]*\s+extends\s+(' . implode('|', self::FORBIDDEN_PARENTS) . ')\b/m';

        $offenders = [];
        foreach ($this->phpFilesUnder(self::TESTS_ROOT) as $file) {
            // Skip this file — its literal FORBIDDEN_PARENTS array would match itself.
            if (realpath($file) === realpath(__FILE__)) {
                continue;
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            if (preg_match($pattern, $contents, $m) === 1) {
                $offenders[] = sprintf('%s (extends %s)', $file, $m[1]);
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "Found %d named kernel subclass(es) under tests/. Use the anonymous-subclass + publicBoot() pattern from KernelBundleSubtableMaterializationTest instead.\n  - %s",
                count($offenders),
                implode("\n  - ", $offenders),
            ),
        );
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesUnder(string $dir): iterable
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
```

- [ ] **Step 4: Run the architectural test — must pass with zero allowlist**

```bash
./vendor/bin/phpunit tests/Architecture/NoKernelSubclassesInTestsTest.php
```

Expected: `OK (1 test, 1 assertion)` — no offenders because Task 2 drained the last named subclass.

- [ ] **Step 5: Verify the test is load-bearing by planting a named subclass**

Add this scratch file at `tests/_scratch/ScratchKernel.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\_scratch;

use Waaseyaa\Foundation\Kernel\AbstractKernel;

final class ScratchKernel extends AbstractKernel
{
}
```

Re-run the architectural test:

```bash
./vendor/bin/phpunit tests/Architecture/NoKernelSubclassesInTestsTest.php
```

Expected: FAIL with `Found 1 named kernel subclass(es) under tests/. ... tests/_scratch/ScratchKernel.php (extends AbstractKernel)`.

Delete the scratch file:

```bash
rm tests/_scratch/ScratchKernel.php
rmdir tests/_scratch
```

Re-run: must pass again.

- [ ] **Step 6: Wire the Architecture suite into `phpunit.xml.dist` (only if not already present)**

```bash
grep -n 'Architecture' phpunit.xml.dist
```

If there is no existing `<testsuite name="Architecture">` block, add one. If one exists with a different `<directory>` entry, ensure `tests/Architecture` is included. Otherwise skip this step.

To add (only if needed), edit `phpunit.xml.dist` and append inside the `<testsuites>` element:

```xml
<testsuite name="Architecture">
    <directory>tests/Architecture</directory>
</testsuite>
```

Then verify:

```bash
./vendor/bin/phpunit --testsuite Architecture
```

Expected: `OK (1 test, 1 assertion)`.

- [ ] **Step 7: Run the full suite as a last check**

```bash
./vendor/bin/phpunit
```

Expected: all tests green. Covers the possibility that the Architecture suite inclusion changed execution order or surfaced a collateral failure.

- [ ] **Step 8: Commit and push**

```bash
git add tests/Architecture/NoKernelSubclassesInTestsTest.php
# Only add phpunit.xml.dist if Step 6 modified it:
git add phpunit.xml.dist 2>/dev/null || true

git commit -m "$(cat <<'EOF'
test(#1315): forbid named kernel subclasses under tests/**

The bootstrap-variant proliferation root smell — parallel "kernel-ish"
helpers with partial boot paths — was what masked the alpha.148-152
adoption-chain failure for five releases. With MinimalTestKernel
drained, tests/** contains zero named kernel subclasses. This
architectural test keeps it that way.

The canonical pattern for kernel-path tests is the anonymous-subclass
+ publicBoot() form codified in KernelBundleSubtableMaterializationTest.
Anonymous classes are not matched by this guard; named subclasses are.
If a genuine shared base is ever needed, it belongs outside tests/
(packages/testing/ or a dedicated testing/ directory wired via
composer autoload-dev).

Closes the fourth artefact of Phase 1 (framework#1315 part A).

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
git push -u origin test/1315-arch-no-kernel-subclasses
gh pr create --title "test(#1315): forbid named kernel subclasses under tests/**" --body "$(cat <<'EOF'
## Summary
- New architectural test rejecting any new `class X extends AbstractKernel | HttpKernel | ConsoleKernel` under tests/**.
- Starts with zero allowlist (MinimalTestKernel drained in PR 2).
- Fourth and final artefact of Phase 1.

## Test plan
- [ ] `./vendor/bin/phpunit tests/Architecture/NoKernelSubclassesInTestsTest.php` passes.
- [ ] Planting a scratch named subclass makes it fail.
- [ ] `./vendor/bin/phpunit` (full suite) green.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

When this PR merges, Phase 1 of the arc is closed. Plan 2 (Phase 2 — packaged-form CI) can begin.

---

## Phase 1 exit verification (after all four PRs merge)

Run once on `main` to confirm all four exit criteria from the amended spec:

```bash
git checkout main && git pull --ff-only

# Criterion 1
./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/KernelBundleSubtableMaterializationTest.php

# Criterion 2 — follow the mutation recipe in the class docblock; confirm RED then revert.

# Criterion 3
./vendor/bin/phpunit tests/Integration/Phase17/KernelBootValidationTest.php

# Criterion 4
./vendor/bin/phpunit tests/Architecture/NoKernelSubclassesInTestsTest.php
```

All four green (with criterion 2 hand-verified) → Phase 1 closed → open Plan 2.
