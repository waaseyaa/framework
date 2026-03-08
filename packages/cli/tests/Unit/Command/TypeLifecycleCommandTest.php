<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\AuditLogCommand;
use Waaseyaa\CLI\Command\TypeDisableCommand;
use Waaseyaa\CLI\Command\TypeEnableCommand;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(TypeDisableCommand::class)]
#[CoversClass(TypeEnableCommand::class)]
#[CoversClass(AuditLogCommand::class)]
final class TypeLifecycleCommandTest extends TestCase
{
    private string $tempDir;
    private EntityTypeLifecycleManager $lifecycle;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cli_lifecycle_test_' . uniqid();
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

    // -----------------------------------------------------------------------
    // type:disable
    // -----------------------------------------------------------------------

    #[Test]
    public function disableSucceedsForKnownType(): void
    {
        $tester = $this->runCommand('type:disable', ['type' => 'article']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Disabled', $tester->getDisplay());
        $this->assertTrue($this->lifecycle->isDisabled('article'));
    }

    #[Test]
    public function disableFailsForUnknownType(): void
    {
        $tester = $this->runCommand('type:disable', ['type' => 'nonexistent']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown entity type', $tester->getDisplay());
    }

    #[Test]
    public function disableFailsWhenItWouldLeaveNoEnabledTypes(): void
    {
        $this->lifecycle->disable('article', 'test');

        $tester = $this->runCommand('type:disable', ['type' => 'note']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('DEFAULT_TYPE_DISABLED', $tester->getDisplay());
        $this->assertFalse($this->lifecycle->isDisabled('note'));
    }

    #[Test]
    public function disableIsIdempotentForAlreadyDisabledType(): void
    {
        $this->lifecycle->disable('note', 'test');

        $tester = $this->runCommand('type:disable', ['type' => 'note']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already disabled', $tester->getDisplay());
    }

    #[Test]
    public function disableWritesAuditEntry(): void
    {
        $this->runCommand('type:disable', ['type' => 'note', '--actor' => 'test-actor']);

        $entries = $this->lifecycle->readAuditLog('note');
        $this->assertCount(1, $entries);
        $this->assertSame('disabled', $entries[0]['action']);
        $this->assertSame('test-actor', $entries[0]['actor_id']);
    }

    // -----------------------------------------------------------------------
    // type:enable
    // -----------------------------------------------------------------------

    #[Test]
    public function enableSucceedsForDisabledType(): void
    {
        $this->lifecycle->disable('note', 'test');

        $tester = $this->runCommand('type:enable', ['type' => 'note']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Enabled', $tester->getDisplay());
        $this->assertFalse($this->lifecycle->isDisabled('note'));
    }

    #[Test]
    public function enableFailsForUnknownType(): void
    {
        $tester = $this->runCommand('type:enable', ['type' => 'nonexistent']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function enableIsIdempotentForAlreadyEnabledType(): void
    {
        $tester = $this->runCommand('type:enable', ['type' => 'note']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already enabled', $tester->getDisplay());
    }

    #[Test]
    public function enableWritesAuditEntry(): void
    {
        $this->lifecycle->disable('note', 'setup');
        $this->runCommand('type:enable', ['type' => 'note', '--actor' => 're-enabler']);

        $entries = $this->lifecycle->readAuditLog('note');
        $this->assertCount(2, $entries);
        $this->assertSame('enabled', $entries[1]['action']);
        $this->assertSame('re-enabler', $entries[1]['actor_id']);
    }

    // -----------------------------------------------------------------------
    // audit:log
    // -----------------------------------------------------------------------

    #[Test]
    public function auditLogDisplaysEntriesInTable(): void
    {
        $this->lifecycle->disable('note', 'actor1');
        $this->lifecycle->enable('note', 'actor2');

        $tester = $this->runCommand('audit:log', []);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('note', $output);
        $this->assertStringContainsString('disabled', $output);
        $this->assertStringContainsString('enabled', $output);
    }

    #[Test]
    public function auditLogFiltersbyType(): void
    {
        $this->lifecycle->disable('note', '1');
        $this->lifecycle->disable('article', '1');

        $tester = $this->runCommand('audit:log', ['--type' => 'article']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('article', $output);
        $this->assertStringNotContainsString('note', $output);
    }

    #[Test]
    public function auditLogShowsEmptyMessageWhenNoEntries(): void
    {
        $tester = $this->runCommand('audit:log', []);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No audit entries', $tester->getDisplay());
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function runCommand(string $commandName, array $input): CommandTester
    {
        $app = new Application();
        $app->add(new TypeDisableCommand($this->entityTypeManager, $this->lifecycle));
        $app->add(new TypeEnableCommand($this->entityTypeManager, $this->lifecycle));
        $app->add(new AuditLogCommand($this->lifecycle));

        $command = $app->find($commandName);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
