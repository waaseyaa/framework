<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\AuditLogHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\Audit\EntityAuditEntry;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\EntityTypeLifecycleManager;

#[CoversClass(AuditLogHandler::class)]
final class AuditLogHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_audit_handler_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target_path = $dir . '/' . $entry;
            if (is_dir($target_path)) {
                $this->removeDirectory($target_path);
            } else {
                unlink($target_path);
            }
        }

        rmdir($dir);
    }

    #[Test]
    public function showsNoAuditEntriesWhenEmpty(): void
    {
        $manager = new EntityTypeLifecycleManager($this->tempDir);

        $tester = $this->createTester($manager);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No audit entries found.', $tester->getStdout());
    }

    #[Test]
    public function showsLifecycleLogEntries(): void
    {
        $manager = new EntityTypeLifecycleManager($this->tempDir);
        $manager->enable('node', 'default', 'admin');

        $tester = $this->createTester($manager);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        $this->assertStringContainsString('node', $stdout);
        $this->assertStringContainsString('enable', $stdout);
    }

    #[Test]
    public function showsErrorWhenEntityAuditLoggerNotConfigured(): void
    {
        $manager = new EntityTypeLifecycleManager($this->tempDir);

        $tester = $this->createTester($manager, null);
        $tester->executeMap(['--entity-type' => 'node']);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringContainsString('Entity audit logger is not configured.', $tester->getStderr());
    }

    #[Test]
    public function showsEntityWriteLogEntries(): void
    {
        $manager = new EntityTypeLifecycleManager($this->tempDir);

        $auditLogger = new EntityAuditLogger($this->tempDir);
        $auditLogger->append(new EntityAuditEntry(
            actor: 'admin',
            action: 'create',
            entityId: '1',
            entityType: 'node',
            tenantId: 'default',
        ));

        $tester = $this->createTester($manager, $auditLogger);
        $tester->executeMap(['--entity-type' => 'node']);

        $this->assertSame(0, $tester->getExitCode());
        $stdout = $tester->getStdout();
        $this->assertStringContainsString('node', $stdout);
        $this->assertStringContainsString('create', $stdout);
    }

    private function createTester(EntityTypeLifecycleManager $manager, ?EntityAuditLogger $auditLogger = null): CliTester
    {
        $handler = new AuditLogHandler($manager, $auditLogger);
        $definition = new HandlerCommand(
            name: 'audit:log',
            description: 'Display the entity type lifecycle audit log, or entity-write audit log with --entity-type',
            options: [
                new HandlerOption(name: 'type', mode: HandlerOptionMode::Required, description: 'Filter lifecycle log by entity type ID (e.g. note)', default: ''),
                new HandlerOption(name: 'tenant', mode: HandlerOptionMode::Required, description: 'Filter lifecycle log by tenant ID', default: ''),
                new HandlerOption(name: 'entity-type', mode: HandlerOptionMode::Required, description: 'Show entity-write audit log, optionally filtered by type (e.g. note)'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: {$id}"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}
