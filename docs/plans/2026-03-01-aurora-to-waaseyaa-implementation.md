# Aurora → Waaseyaa Rename Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rename the project from "Aurora CMS" to "Waaseyaa" across all PHP namespaces, composer packages, CLI entry point, admin SPA, environment variables, and documentation.

**Architecture:** Scripted bulk `sed` replacements executed in a careful sequence to avoid partial-match corruption. Order matters: longer/more-specific strings first (e.g., `Aurora CMS` before `Aurora`), double-backslash strings before single-backslash, etc.

**Tech Stack:** `find`, `sed`, `mv` (GNU coreutils), `composer`, `phpunit`

---

### Task 1: Bulk rename PHP namespaces and use statements

**Files:** All `.php` files in `packages/*/src/`, `packages/*/tests/`, `tests/`, `bin/aurora`, `public/index.php`

**Step 1: Replace double-backslash Aurora references (string literals in PHP)**

These appear in test assertions and controller references like `'Aurora\\Entity\\ContentEntityBase'`. Must be done BEFORE single-backslash to avoid double-processing.

```bash
find packages tests bin public -name '*.php' -exec sed -i 's/Aurora\\\\/Waaseyaa\\\\/g' {} +
```

**Step 2: Replace single-backslash namespace declarations and use statements**

```bash
find packages tests bin public -name '*.php' -exec sed -i 's/Aurora\\/Waaseyaa\\/g' {} +
```

**Step 3: Replace `[Aurora]` log prefix in public/index.php**

```bash
sed -i 's/\[Aurora\]/[Waaseyaa]/g' public/index.php
```

**Step 4: Verify no remaining `Aurora\` in PHP files (excluding docs/plans/)**

```bash
grep -r 'Aurora\\' packages/ tests/ bin/ public/ --include='*.php' | head -20
```

Expected: No output (0 matches).

**Step 5: Commit**

```bash
git add -A && git commit -m "refactor: rename Aurora\ namespace to Waaseyaa\ across all PHP files"
```

---

### Task 2: Rename PHP class files with "Aurora" in the filename

**Files:** 9 PHP files + corresponding content updates already done in Task 1.

**Step 1: Rename all Aurora-prefixed class files**

```bash
mv packages/cli/src/AuroraApplication.php packages/cli/src/WaaseyaaApplication.php
mv packages/cli/tests/Unit/AuroraApplicationTest.php packages/cli/tests/Unit/WaaseyaaApplicationTest.php
mv packages/testing/src/AuroraTestCase.php packages/testing/src/WaaseyaaTestCase.php
mv packages/testing/tests/Unit/AuroraTestCaseTest.php packages/testing/tests/Unit/WaaseyaaTestCaseTest.php
mv packages/plugin/src/Attribute/AuroraPlugin.php packages/plugin/src/Attribute/WaaseyaaPlugin.php
mv packages/routing/src/AuroraRouter.php packages/routing/src/WaaseyaaRouter.php
mv packages/routing/tests/Unit/AuroraRouterTest.php packages/routing/tests/Unit/WaaseyaaRouterTest.php
mv packages/foundation/src/Exception/AuroraException.php packages/foundation/src/Exception/WaaseyaaException.php
mv packages/foundation/tests/Unit/Exception/AuroraExceptionTest.php packages/foundation/tests/Unit/Exception/WaaseyaaExceptionTest.php
```

**Step 2: Replace class name references inside the files (already partially done by namespace sed, but class names like `AuroraApplication` need explicit replacement)**

```bash
find packages tests bin public -name '*.php' -exec sed -i 's/AuroraApplication/WaaseyaaApplication/g' {} +
find packages tests bin public -name '*.php' -exec sed -i 's/AuroraTestCase/WaaseyaaTestCase/g' {} +
find packages tests bin public -name '*.php' -exec sed -i 's/AuroraPlugin/WaaseyaaPlugin/g' {} +
find packages tests bin public -name '*.php' -exec sed -i 's/AuroraRouter/WaaseyaaRouter/g' {} +
find packages tests bin public -name '*.php' -exec sed -i 's/AuroraException/WaaseyaaException/g' {} +
```

**Step 3: Verify no remaining Aurora class names**

```bash
grep -r 'Aurora[A-Z]' packages/ tests/ bin/ public/ --include='*.php' | head -20
```

Expected: No output.

**Step 4: Commit**

```bash
git add -A && git commit -m "refactor: rename Aurora-prefixed PHP class files to Waaseyaa"
```

---

### Task 3: Update all composer.json files

**Files:** Root `composer.json` + 36 `packages/*/composer.json`

**Step 1: Replace package names and namespace mappings in all composer.json files**

```bash
find . -name 'composer.json' -not -path './vendor/*' -exec sed -i 's/"aurora\//"waaseyaa\//g' {} +
find . -name 'composer.json' -not -path './vendor/*' -exec sed -i 's/Aurora\\\\/Waaseyaa\\\\/g' {} +
```

**Step 2: Replace "Aurora CMS" display text in composer.json descriptions**

```bash
find . -name 'composer.json' -not -path './vendor/*' -exec sed -i 's/Aurora CMS/Waaseyaa/g' {} +
```

**Step 3: Verify root composer.json looks correct**

```bash
head -5 composer.json
# Expected: "name": "waaseyaa/waaseyaa"
grep 'aurora' composer.json
# Expected: No output
```

**Step 4: Verify a package composer.json looks correct**

```bash
cat packages/entity/composer.json
# Expected: "name": "waaseyaa/entity", "Waaseyaa\\Entity\\": "src/"
```

**Step 5: Commit**

```bash
git add -A && git commit -m "refactor: rename aurora/* composer packages to waaseyaa/*"
```

---

### Task 4: Rename CLI binary and update environment variables

**Files:** `bin/aurora`, `public/index.php`, `packages/cli/src/Command/AboutCommand.php`

**Step 1: Replace environment variable names across all PHP files**

```bash
find packages tests bin public -name '*.php' -exec sed -i 's/AURORA_DB/WAASEYAA_DB/g' {} +
find packages tests bin public -name '*.php' -exec sed -i 's/AURORA_CONFIG_DIR/WAASEYAA_CONFIG_DIR/g' {} +
```

**Step 2: Replace default database filename**

```bash
find packages tests bin public -name '*.php' -exec sed -i 's/aurora\.sqlite/waaseyaa.sqlite/g' {} +
```

**Step 3: Replace remaining "Aurora CMS" and "Aurora" display strings in PHP files**

```bash
find packages tests bin public -name '*.php' -exec sed -i "s/Aurora CMS/Waaseyaa/g" {} +
find packages tests bin public -name '*.php' -exec sed -i "s/'Aurora'/'Waaseyaa'/g" {} +
find packages tests bin public -name '*.php' -exec sed -i 's/"Aurora"/"Waaseyaa"/g' {} +
```

**Step 4: Replace "Aurora" in PHP comments/docblocks (remaining bare-word references)**

```bash
find packages tests bin public -name '*.php' -exec sed -i 's/ Aurora / Waaseyaa /g' {} +
find packages tests bin public -name '*.php' -exec sed -i 's/^Aurora /Waaseyaa /g' {} +
```

**Step 5: Rename the CLI binary**

```bash
mv bin/aurora bin/waaseyaa
chmod +x bin/waaseyaa
```

**Step 6: Update any references to `bin/aurora` in the codebase**

```bash
grep -r 'bin/aurora' . --include='*.php' --include='*.json' --include='*.md' --include='*.xml' -l | grep -v docs/plans/ | grep -v vendor/
```

If any hits, fix them:
```bash
find . -name '*.php' -not -path './vendor/*' -not -path './docs/plans/*' -exec sed -i 's|bin/aurora|bin/waaseyaa|g' {} +
```

**Step 7: Verify no remaining Aurora references in PHP files (excluding docs/plans/)**

```bash
grep -ri 'aurora' packages/ tests/ bin/ public/ --include='*.php' | grep -v 'docs/plans' | head -20
```

Expected: No output.

**Step 8: Commit**

```bash
git add -A && git commit -m "refactor: rename CLI binary, env vars, and display strings to Waaseyaa"
```

---

### Task 5: Update the admin SPA (JS/TS/Vue files)

**Files:** `packages/admin/package.json`, `packages/admin/nuxt.config.ts`, `packages/admin/app/i18n/en.json`, plus 4 Vue/TS files with `[Aurora]` console log prefixes

**Step 1: Update package.json**

Replace `"@aurora/admin"` → `"@waaseyaa/admin"` and `"Aurora CMS"` → `"Waaseyaa"`:

```bash
sed -i 's/@aurora\/admin/@waaseyaa\/admin/g' packages/admin/package.json
sed -i 's/Aurora CMS/Waaseyaa/g' packages/admin/package.json
```

**Step 2: Update nuxt.config.ts**

```bash
sed -i 's/Aurora CMS/Waaseyaa/g' packages/admin/nuxt.config.ts
```

**Step 3: Update i18n/en.json**

```bash
sed -i 's/Aurora CMS/Waaseyaa/g' packages/admin/app/i18n/en.json
```

**Step 4: Update `[Aurora]` console log prefixes in Vue/TS files**

```bash
find packages/admin -type f \( -name '*.vue' -o -name '*.ts' -o -name '*.js' \) -exec sed -i 's/\[Aurora\]/[Waaseyaa]/g' {} +
```

**Step 5: Verify no remaining Aurora references in admin package**

```bash
grep -ri 'aurora' packages/admin/ --include='*.vue' --include='*.ts' --include='*.js' --include='*.json' | grep -v node_modules | grep -v package-lock
```

Expected: No output.

**Step 6: Commit**

```bash
git add -A && git commit -m "refactor: rename Aurora to Waaseyaa in admin SPA"
```

---

### Task 6: Update documentation (README.md, CLAUDE.md)

**Files:** `README.md`, `CLAUDE.md`

**Step 1: Update README.md**

Replace all `Aurora CMS` → `Waaseyaa`, `Aurora` → `Waaseyaa`, `aurora/` → `waaseyaa/`:

```bash
sed -i 's/Aurora CMS/Waaseyaa/g' README.md
sed -i 's/aurora\//waaseyaa\//g' README.md
sed -i 's/Aurora /Waaseyaa /g' README.md
sed -i "s/Aurora's/Waaseyaa's/g" README.md
```

**Step 2: Update CLAUDE.md**

```bash
sed -i 's/Aurora CMS/Waaseyaa/g' CLAUDE.md
sed -i 's/aurora\//waaseyaa\//g' CLAUDE.md
sed -i 's/bin\/aurora/bin\/waaseyaa/g' CLAUDE.md
sed -i 's/Aurora\\/Waaseyaa\\/g' CLAUDE.md
sed -i 's/aurora\.sqlite/waaseyaa.sqlite/g' CLAUDE.md
sed -i 's/AURORA_DB/WAASEYAA_DB/g' CLAUDE.md
sed -i 's/AURORA_CONFIG_DIR/WAASEYAA_CONFIG_DIR/g' CLAUDE.md
sed -i 's/Aurora /Waaseyaa /g' CLAUDE.md
```

**Step 3: Verify**

```bash
grep -i 'aurora' README.md CLAUDE.md | grep -v 'docs/plans'
```

Expected: No output (or only references inside the design doc path which is fine).

**Step 4: Commit**

```bash
git add -A && git commit -m "docs: update README.md and CLAUDE.md for Waaseyaa rename"
```

---

### Task 7: Rename the database file and regenerate autoloader

**Step 1: Rename existing database file (if present)**

```bash
if [ -f aurora.sqlite ]; then mv aurora.sqlite waaseyaa.sqlite; fi
```

**Step 2: Remove vendor directory and regenerate**

The vendor/aurora/ symlinks will be stale. Composer needs to regenerate them as vendor/waaseyaa/.

```bash
rm -rf vendor/aurora
composer dump-autoload
```

**Step 3: Verify autoloader recognizes new namespace**

```bash
php -r "require 'vendor/autoload.php'; echo class_exists('Waaseyaa\Entity\EntityType') ? 'OK' : 'FAIL';"
```

Expected: `OK`

**Step 4: Commit**

```bash
git add -A && git commit -m "chore: regenerate autoloader for waaseyaa namespace"
```

---

### Task 8: Run full test suite and fix any remaining issues

**Step 1: Run the full test suite**

```bash
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

Expected: All tests pass (2,162 tests, 5,429 assertions or similar).

**Step 2: If failures, grep for remaining Aurora references in failing test files and fix**

```bash
# If needed, find any stragglers:
grep -ri 'aurora' packages/ tests/ bin/ public/ --include='*.php' --include='*.json' --include='*.ts' --include='*.vue' | grep -v vendor/ | grep -v docs/plans/ | grep -v node_modules | grep -v package-lock
```

Fix any remaining references and re-run tests.

**Step 3: Final comprehensive sweep**

```bash
grep -ri 'aurora' . --include='*.php' --include='*.json' --include='*.xml' --include='*.md' --include='*.ts' --include='*.vue' | grep -v vendor/ | grep -v docs/plans/ | grep -v node_modules | grep -v package-lock | grep -v '.git/'
```

Only the design docs in `docs/plans/` and this implementation plan should contain "Aurora".

**Step 4: Commit any final fixes**

```bash
git add -A && git commit -m "fix: resolve remaining Aurora references after rename"
```

---

### Task 9: Update auto-memory

**Step 1: Update Claude's persistent memory file to reflect the new project name**

Update `/home/fsd42/.claude/projects/-home-fsd42-dev-drupal-11-2-10/memory/MEMORY.md` to reference "Waaseyaa" instead of "Aurora CMS".
