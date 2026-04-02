# Issue 748 SQLite Production Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent production kernel boot from silently creating a new SQLite database when the resolved database path is missing.

**Architecture:** Add the guard in `DatabaseBootstrapper`, which already owns resolved SQLite path selection before `DBALDatabase::createSqlite()` is called by `AbstractKernel`. Detect production using the existing environment resolution contract and fail fast only for file-backed SQLite paths that do not exist; keep non-production and in-memory SQLite behavior unchanged.

**Tech Stack:** PHP 8.4, PHPUnit 10, Waaseyaa Foundation kernel/bootstrap layer, DBAL SQLite bootstrap

---

### Task 1: Add the production-path regression tests first

**Files:**
- Modify: `packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php`
- Modify: `packages/foundation/tests/Unit/Kernel/DebugModeTest.php`

- [ ] **Step 1: Write the failing bootstrapper test**

Add a unit test covering a missing file-backed SQLite path in production:

```php
#[Test]
public function bootRefusesMissingSqliteDatabaseInProduction(): void
{
    $dbPath = $this->tempDir . '/missing/prod.sqlite';

    $bootstrapper = new DatabaseBootstrapper();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        sprintf('Database not found at %s. In production, the database must already exist.', $dbPath),
    );

    $bootstrapper->boot($this->tempDir, ['database' => $dbPath, 'environment' => 'production']);
}
```

- [ ] **Step 2: Write the failing kernel boot test**

Add a kernel-level test proving the guard triggers through `AbstractKernel::boot()`:

```php
#[Test]
public function boot_refuses_missing_sqlite_database_in_production(): void
{
    putenv('APP_ENV=production');
    $missingPath = $this->projectRoot . '/storage/missing.sqlite';
    $this->writeConfig(['database' => $missingPath]);

    $kernel = new class($this->projectRoot) extends AbstractKernel {
        public function publicBoot(): void { $this->boot(); }
    };

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
        sprintf('Database not found at %s. In production, the database must already exist.', $missingPath),
    );

    $kernel->publicBoot();
}
```

- [ ] **Step 3: Run the new tests to verify RED**

Run:

```bash
./vendor/bin/phpunit \
  packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php \
  packages/foundation/tests/Unit/Kernel/DebugModeTest.php
```

Expected: the new production-path assertions fail because bootstrap still creates the SQLite file.

### Task 2: Implement the bootstrap guard minimally

**Files:**
- Modify: `packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php`

- [ ] **Step 1: Add explicit environment-aware path validation**

Implement a small pre-boot guard before `DBALDatabase::createSqlite()`:

```php
public function boot(string $projectRoot, array $config): DatabaseInterface
{
    $path = $this->resolvePath($projectRoot, $config);
    $this->guardMissingProductionSqliteDatabase($path, $config);

    return DBALDatabase::createSqlite($path);
}
```

Add a helper that:

```php
private function guardMissingProductionSqliteDatabase(string $path, array $config): void
{
    if (!$this->isProductionEnvironment($config)) {
        return;
    }

    if ($path === ':memory:') {
        return;
    }

    if (file_exists($path)) {
        return;
    }

    throw new \RuntimeException(
        sprintf('Database not found at %s. In production, the database must already exist.', $path),
    );
}
```

Use a private environment resolver consistent with the kernel contract:

```php
private function resolveEnvironment(array $config): string
{
    $env = $config['environment'] ?? getenv('APP_ENV') ?: 'production';

    return is_string($env) && $env !== '' ? $env : 'production';
}
```

- [ ] **Step 2: Preserve current non-production behavior**

Keep `resolvePath()` creating the parent directory so local/dev boot still allows SQLite file creation.

- [ ] **Step 3: Run the targeted tests to verify GREEN**

Run:

```bash
./vendor/bin/phpunit \
  packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php \
  packages/foundation/tests/Unit/Kernel/DebugModeTest.php
```

Expected: PASS, aside from the known no-coverage warning.

### Task 3: Cover allowed paths and prevent regressions

**Files:**
- Modify: `packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php`

- [ ] **Step 1: Add a non-production allow test**

Add a test proving development still creates the missing file:

```php
#[Test]
public function bootAllowsMissingSqliteDatabaseOutsideProduction(): void
{
    $dbPath = $this->tempDir . '/dev/dev.sqlite';

    $bootstrapper = new DatabaseBootstrapper();
    $database = $bootstrapper->boot($this->tempDir, ['database' => $dbPath, 'environment' => 'local']);

    $this->assertInstanceOf(DatabaseInterface::class, $database);
    $this->assertFileExists($dbPath);
}
```

- [ ] **Step 2: Add an existing-production-file allow test**

Add a test proving production still boots when the file exists:

```php
#[Test]
public function bootAllowsExistingSqliteDatabaseInProduction(): void
{
    $dbPath = $this->tempDir . '/prod/existing.sqlite';
    mkdir(dirname($dbPath), 0o755, true);
    touch($dbPath);

    $bootstrapper = new DatabaseBootstrapper();
    $database = $bootstrapper->boot($this->tempDir, ['database' => $dbPath, 'environment' => 'production']);

    $this->assertInstanceOf(DatabaseInterface::class, $database);
    $this->assertFileExists($dbPath);
}
```

- [ ] **Step 3: Run the bootstrapper suite**

Run:

```bash
./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php
```

Expected: PASS, aside from the known no-coverage warning.

### Task 4: Full verification and PR prep

**Files:**
- Modify: `packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php`
- Modify: `packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php`
- Modify: `packages/foundation/tests/Unit/Kernel/DebugModeTest.php`
- Create: `docs/superpowers/plans/2026-04-02-issue-748-sqlite-production-guard.md`

- [ ] **Step 1: Run focused verification**

Run:

```bash
./vendor/bin/phpunit \
  packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php \
  packages/foundation/tests/Unit/Kernel/AbstractKernelTest.php \
  packages/foundation/tests/Unit/Kernel/DebugModeTest.php \
  tests/Integration/Phase17/KernelBootValidationTest.php
```

Expected: PASS, aside from the known no-coverage warning.

- [ ] **Step 2: Run repo-level verification required for a safe PR**

Run:

```bash
./vendor/bin/phpunit
composer cs-check
```

Expected: all green, with only any pre-existing non-blocking coverage warning if it appears.

- [ ] **Step 3: Commit and push**

Run:

```bash
git add \
  packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php \
  packages/foundation/tests/Unit/Kernel/Bootstrap/DatabaseBootstrapperTest.php \
  packages/foundation/tests/Unit/Kernel/DebugModeTest.php \
  docs/superpowers/plans/2026-04-02-issue-748-sqlite-production-guard.md
git commit -m "fix(#748): guard missing sqlite databases in production"
git push -u origin issue-748-sqlite-production-guard
```

- [ ] **Step 4: Open the PR and wait for CI**

Create:

```bash
gh pr create \
  --repo waaseyaa/framework \
  --base main \
  --head issue-748-sqlite-production-guard \
  --title "fix(#748): guard missing sqlite databases in production"
```

Then monitor CI until it is green, address any failures, and stop before merge for human review.
