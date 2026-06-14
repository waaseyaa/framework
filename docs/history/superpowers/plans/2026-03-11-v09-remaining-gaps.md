# v0.9 Remaining Gaps Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the last two v0.9 issues (#210, #211) by filling three gaps: unit tests for TypeDisable/TypeEnable commands, an integration smoke test for the migration flow, and a migration guide doc.

**Architecture:** All three tasks are independent. Tests follow existing patterns (CommandTester + temp dir + EntityTypeLifecycleManager). The migration guide is a short markdown doc.

**Tech Stack:** PHP 8.3, PHPUnit 10.5, Symfony Console CommandTester

---

## Task 1: Unit tests for TypeDisableCommand

**Files:**
- Create: `packages/cli/tests/Unit/Command/TypeDisableCommandTest.php`

- [ ] **Step 1: Create test file with setup/teardown**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\TypeDisableCommand;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(TypeDisableCommand::class)]
final class TypeDisableCommandTest extends TestCase
{
    private string $tempDir;
    private EntityTypeLifecycleManager $lifecycle;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cli_disable_test_' . uniqid();
        mkdir($this->tempDir . '/storage/framework', 0755, true);

        $this->lifecycle = new EntityTypeLifecycleManager($this->tempDir);

        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/storage/framework/*') ?: []);
        @rmdir($this->tempDir . '/storage/framework');
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir);
    }

    #[Test]
    public function disablesTypeAndRecordsAuditEntry(): void
    {
        $tester = $this->runCommand(['type' => 'note', '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertTrue($this->lifecycle->isDisabled('note'));
        $this->assertStringContainsString('Disabled entity type "note"', $tester->getDisplay());

        $audit = $this->lifecycle->readAuditLog('note');
        $this->assertNotEmpty($audit);
        $this->assertSame('disabled', $audit[0]['action']);
    }

    #[Test]
    public function disablesTypeForSpecificTenant(): void
    {
        $tester = $this->runCommand(['type' => 'note', '--tenant' => 'acme', '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertTrue($this->lifecycle->isDisabled('note', 'acme'));
        $this->assertFalse($this->lifecycle->isDisabled('note'));

        $audit = $this->lifecycle->readAuditLog('note', 'acme');
        $this->assertNotEmpty($audit);
        $this->assertSame('acme', $audit[0]['tenant_id']);
    }

    #[Test]
    public function failsForUnknownType(): void
    {
        $tester = $this->runCommand(['type' => 'nonexistent', '--yes' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown entity type', $tester->getDisplay());
    }

    #[Test]
    public function alreadyDisabledIsIdempotent(): void
    {
        $this->lifecycle->disable('note', 'cli');

        $tester = $this->runCommand(['type' => 'note', '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already disabled', $tester->getDisplay());
    }

    #[Test]
    public function guardrailBlocksDisablingLastEnabledType(): void
    {
        $this->lifecycle->disable('article', 'cli');

        $tester = $this->runCommand(['type' => 'note', '--yes' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('DEFAULT_TYPE_DISABLED', $tester->getDisplay());
        $this->assertFalse($this->lifecycle->isDisabled('note'));
    }

    #[Test]
    public function forceOverridesGuardrail(): void
    {
        $this->lifecycle->disable('article', 'cli');

        $tester = $this->runCommand(['type' => 'note', '--yes' => true, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertTrue($this->lifecycle->isDisabled('note'));
        $this->assertStringContainsString('Disabling the last enabled type', $tester->getDisplay());
    }

    #[Test]
    public function customActorRecordedInAuditLog(): void
    {
        $tester = $this->runCommand(['type' => 'note', '--actor' => 'admin-42', '--yes' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $audit = $this->lifecycle->readAuditLog('note');
        $this->assertSame('admin-42', $audit[0]['actor_id']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $app = new Application();
        $app->add(new TypeDisableCommand($this->entityTypeManager, $this->lifecycle));

        $command = $app->find('type:disable');
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/cli/tests/Unit/Command/TypeDisableCommandTest.php`
Expected: 7 tests, 7 passed

- [ ] **Step 3: Commit**

```bash
git add packages/cli/tests/Unit/Command/TypeDisableCommandTest.php
git commit -m "test(#210): add unit tests for TypeDisableCommand"
```

---

## Task 2: Unit tests for TypeEnableCommand

**Files:**
- Create: `packages/cli/tests/Unit/Command/TypeEnableCommandTest.php`

- [ ] **Step 1: Create test file**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\TypeEnableCommand;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(TypeEnableCommand::class)]
final class TypeEnableCommandTest extends TestCase
{
    private string $tempDir;
    private EntityTypeLifecycleManager $lifecycle;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cli_enable_test_' . uniqid();
        mkdir($this->tempDir . '/storage/framework', 0755, true);

        $this->lifecycle = new EntityTypeLifecycleManager($this->tempDir);

        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'note',
            label: 'Note',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        ));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/storage/framework/*') ?: []);
        @rmdir($this->tempDir . '/storage/framework');
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir);
    }

    #[Test]
    public function enablesDisabledTypeAndRecordsAuditEntry(): void
    {
        $this->lifecycle->disable('note', 'cli');

        $tester = $this->runCommand(['type' => 'note']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFalse($this->lifecycle->isDisabled('note'));
        $this->assertStringContainsString('Enabled entity type "note"', $tester->getDisplay());

        $audit = $this->lifecycle->readAuditLog('note');
        $this->assertCount(2, $audit); // disable + enable
        $this->assertSame('enabled', $audit[1]['action']);
    }

    #[Test]
    public function enablesTypeForSpecificTenant(): void
    {
        $this->lifecycle->disable('note', 'cli', 'acme');

        $tester = $this->runCommand(['type' => 'note', '--tenant' => 'acme']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFalse($this->lifecycle->isDisabled('note', 'acme'));

        $audit = $this->lifecycle->readAuditLog('note', 'acme');
        $this->assertSame('acme', $audit[1]['tenant_id']);
    }

    #[Test]
    public function alreadyEnabledIsIdempotent(): void
    {
        $tester = $this->runCommand(['type' => 'note']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already enabled', $tester->getDisplay());
    }

    #[Test]
    public function failsForUnknownType(): void
    {
        $tester = $this->runCommand(['type' => 'nonexistent']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown entity type', $tester->getDisplay());
    }

    #[Test]
    public function customActorRecordedInAuditLog(): void
    {
        $this->lifecycle->disable('note', 'cli');

        $tester = $this->runCommand(['type' => 'note', '--actor' => 'migration-bot']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());

        $audit = $this->lifecycle->readAuditLog('note');
        $this->assertSame('migration-bot', $audit[1]['actor_id']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $app = new Application();
        $app->add(new TypeEnableCommand($this->entityTypeManager, $this->lifecycle));

        $command = $app->find('type:enable');
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/cli/tests/Unit/Command/TypeEnableCommandTest.php`
Expected: 5 tests, 5 passed

- [ ] **Step 3: Commit**

```bash
git add packages/cli/tests/Unit/Command/TypeEnableCommandTest.php
git commit -m "test(#210): add unit tests for TypeEnableCommand"
```

---

## Task 3: Integration smoke test for migration flow

**Files:**
- Modify: `tests/Integration/Phase10/EndToEndSmokeTest.php`

- [ ] **Step 1: Add migration lifecycle smoke test**

Add the following test method to `EndToEndSmokeTest.php` (after the last existing test):

```php
use Waaseyaa\CLI\Command\MigrateDefaultsCommand;
use Waaseyaa\CLI\Command\TypeDisableCommand;
use Waaseyaa\CLI\Command\TypeEnableCommand;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
```

```php
/**
 * Exercises the full migration lifecycle: disable types → detect tenants
 * with no enabled types → migrate:defaults → rollback.
 *
 * Exercises: waaseyaa/entity (EntityTypeLifecycleManager, EntityAuditLogger),
 * waaseyaa/cli (TypeDisableCommand, TypeEnableCommand, MigrateDefaultsCommand).
 */
#[Test]
public function testMigrationDefaultsLifecycle(): void
{
    $tempDir = sys_get_temp_dir() . '/waaseyaa_smoke_migrate_' . uniqid();
    mkdir($tempDir . '/storage/framework', 0755, true);

    $lifecycle = new EntityTypeLifecycleManager($tempDir);
    $auditLogger = new EntityAuditLogger($tempDir);

    $entityTypeManager = new EntityTypeManager(new EventDispatcher());
    $entityTypeManager->registerEntityType(new EntityType(
        id: 'note',
        label: 'Note',
        class: \stdClass::class,
        keys: ['id' => 'id'],
    ));
    $entityTypeManager->registerEntityType(new EntityType(
        id: 'article',
        label: 'Article',
        class: \stdClass::class,
        keys: ['id' => 'id'],
    ));

    // --- Step 1: Disable all types for tenant "acme" via CLI ---

    $app = new Application();
    $app->add(new TypeDisableCommand($entityTypeManager, $lifecycle));
    $app->add(new TypeEnableCommand($entityTypeManager, $lifecycle));
    $app->add(new MigrateDefaultsCommand(
        $entityTypeManager,
        $lifecycle,
        $auditLogger,
        $tempDir,
    ));

    $disableTester = new CommandTester($app->find('type:disable'));
    $disableTester->execute(['type' => 'note', '--tenant' => 'acme', '--yes' => true]);
    $this->assertSame(Command::SUCCESS, $disableTester->getStatusCode());

    $disableTester->execute(['type' => 'article', '--tenant' => 'acme', '--yes' => true, '--force' => true]);
    $this->assertSame(Command::SUCCESS, $disableTester->getStatusCode());

    // Both types disabled for tenant.
    $this->assertTrue($lifecycle->isDisabled('note', 'acme'));
    $this->assertTrue($lifecycle->isDisabled('article', 'acme'));

    // --- Step 2: migrate:defaults detects and fixes tenant ---

    $migrateTester = new CommandTester($app->find('migrate:defaults'));
    $migrateTester->execute([
        '--tenant' => ['acme'],
        '--enable' => 'note',
        '--yes' => true,
    ]);

    $this->assertSame(Command::SUCCESS, $migrateTester->getStatusCode());
    $this->assertFalse($lifecycle->isDisabled('note', 'acme'));
    $this->assertStringContainsString('Enabled "note" for tenant "acme"', $migrateTester->getDisplay());

    // --- Step 3: Rollback reverses the migration ---

    $rollbackTester = new CommandTester($app->find('migrate:defaults'));
    $rollbackTester->execute([
        '--tenant' => ['acme'],
        '--rollback' => true,
        '--yes' => true,
    ]);

    $this->assertSame(Command::SUCCESS, $rollbackTester->getStatusCode());
    $this->assertTrue($lifecycle->isDisabled('note', 'acme'));
    $this->assertStringContainsString('Disabled "note" for tenant "acme"', $rollbackTester->getDisplay());

    // --- Step 4: Audit log has all actions recorded ---

    $audit = $lifecycle->readAuditLog('note', 'acme');
    $actions = array_column($audit, 'action');
    $this->assertContains('disabled', $actions);
    $this->assertContains('enabled', $actions);

    // --- Cleanup ---
    array_map('unlink', glob($tempDir . '/storage/framework/*') ?: []);
    @rmdir($tempDir . '/storage/framework');
    @rmdir($tempDir . '/storage');
    @rmdir($tempDir);
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Integration/Phase10/EndToEndSmokeTest.php --filter testMigrationDefaultsLifecycle`
Expected: 1 test, 1 passed

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Phase10/EndToEndSmokeTest.php
git commit -m "test(#211): add migration defaults integration smoke test"
```

---

## Task 4: Migration guide documentation

**Files:**
- Create: `docs/migration-defaults.md`

- [ ] **Step 1: Write migration guide**

```markdown
# Migration Guide: Default Content Types

## Overview

Waaseyaa ships with `core.note` as the default content type. Existing tenants
that pre-date this default may have no enabled content types. The
`migrate:defaults` CLI command detects and fixes these tenants.

## Pre-v1 Notice

This migration is best-effort during the pre-v1 phase. Post-v1.0, a documented
migration path will be required for all breaking changes per the versioning
policy.

## Detecting Affected Tenants

Run a dry-run to see which tenants have no enabled content types:

    bin/waaseyaa migrate:defaults --dry-run

The command auto-discovers tenants from the lifecycle status file and entity
audit log. To target specific tenants:

    bin/waaseyaa migrate:defaults --tenant=acme --tenant=beta --dry-run

## Running the Migration

Enable `core.note` (or another type) for all affected tenants:

    bin/waaseyaa migrate:defaults --enable=note --yes

Interactive mode (omit `--yes`) prompts per-tenant with a choice of registered
types including a "skip" option.

## Rollback

If a migration was applied in error, roll it back:

    bin/waaseyaa migrate:defaults --rollback --yes

This re-disables any types that were enabled by a previous `migrate:defaults`
run. The migration log at `storage/framework/migrate-defaults.jsonl` tracks
all actions.

To rollback specific tenants only:

    bin/waaseyaa migrate:defaults --tenant=acme --rollback --yes

## Per-Tenant Feature Flags

Tenants can individually disable or re-enable content types:

    bin/waaseyaa type:disable note --tenant=acme
    bin/waaseyaa type:enable note --tenant=acme

A guardrail prevents disabling the last enabled type unless `--force` is used.
Every toggle records an audit entry in `storage/framework/entity-type-audit.jsonl`.

## Audit Log

View all lifecycle audit entries:

    bin/waaseyaa audit:log

All `type:disable`, `type:enable`, and `migrate:defaults` actions are logged
with actor ID, timestamp, and optional tenant ID.
```

- [ ] **Step 2: Commit**

```bash
git add docs/migration-defaults.md
git commit -m "docs(#211): add migration guide for default content types"
```

---

## Task 5: Close issues and milestone

- [ ] **Step 1: Run full test suite to verify no regressions**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass

- [ ] **Step 2: Close GitHub issues #210 and #211**

- [ ] **Step 3: Close v0.9 milestone if no remaining open issues**
