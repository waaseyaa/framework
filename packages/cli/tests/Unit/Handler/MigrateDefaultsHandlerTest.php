<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\MigrateDefaultsHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(MigrateDefaultsHandler::class)]
final class MigrateDefaultsHandlerTest extends TestCase
{
    private string $tempDir;
    private EntityTypeLifecycleManager $lifecycle;
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_cli_migrate_defaults_test_' . uniqid();
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
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function noTenantsDiscoveredReportsMessage(): void
    {
        $tester = $this->createTester();
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('No tenants discovered', $tester->getStdout());
    }

    #[Test]
    public function dryRunReportsWouldEnable(): void
    {
        // Pre-disable all types so tenant has none enabled
        $this->lifecycle->disable('note', 'cli', 'tenant-1');
        $this->lifecycle->disable('article', 'cli', 'tenant-1');

        $tester = $this->createTester();
        $tester->execute(['--tenant', 'tenant-1', '--enable', 'note', '--yes', '--dry-run']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('[dry-run]', $tester->getStdout());
        $this->assertStringContainsString('note', $tester->getStdout());
    }

    #[Test]
    public function allTenantsAlreadyHaveEnabledTypeReportsMessage(): void
    {
        // No types disabled means all tenants have enabled types
        $tester = $this->createTester();
        $tester->execute(['--tenant', 'tenant-1', '--yes']);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('All tenants already have at least one enabled type', $tester->getStdout());
    }

    private function createTester(): CliTester
    {
        $handler = new MigrateDefaultsHandler(
            $this->entityTypeManager,
            $this->lifecycle,
            null,
            $this->tempDir,
        );
        $definition = new HandlerCommand(
            name: 'migrate:defaults',
            description: 'Migrate default content type enablement for tenants',
            options: [
                new HandlerOption(name: 'tenant', mode: HandlerOptionMode::Array_, description: 'Tenant IDs to migrate (repeatable)'),
                new HandlerOption(name: 'enable', mode: HandlerOptionMode::Required, description: 'Type ID to enable for all tenants (e.g. note)', default: ''),
                new HandlerOption(name: 'actor', mode: HandlerOptionMode::Required, description: 'Actor ID for audit log entries', default: 'cli'),
                new HandlerOption(name: 'yes', shortcut: 'y', mode: HandlerOptionMode::None, description: 'Skip confirmation prompts'),
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Report actions without making changes'),
                new HandlerOption(name: 'rollback', mode: HandlerOptionMode::None, description: 'Rollback previous migrate:defaults actions'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $dir . '/' . $item;
            is_dir($target) ? $this->removeDir($target) : unlink($target);
        }
        rmdir($dir);
    }
}
