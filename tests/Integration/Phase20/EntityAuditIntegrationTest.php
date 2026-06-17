<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase20;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\AuditLogHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\Audit\EntityAuditEntry;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\Audit\EntityWriteAuditListener;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

/**
 * End-to-end audit trail (#208): entity write → logger → CLI display.
 */
#[CoversNothing]
final class EntityAuditIntegrationTest extends TestCase
{
    private string $tempDir;
    private EntityAuditLogger $auditLogger;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->tempDir     = sys_get_temp_dir() . '/waaseyaa_audit_integration_' . uniqid();
        mkdir($this->tempDir . '/storage/framework', 0o755, true);

        $this->auditLogger = new EntityAuditLogger($this->tempDir);
        $this->dispatcher  = new EventDispatcher();

        $listener = new EntityWriteAuditListener($this->auditLogger);
        $this->dispatcher->addSubscriber($listener);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function entitySaveDispatchesAuditEntry(): void
    {
        $entity = $this->makeNote(['tenant_id' => 'acme'], isNew: true);

        $this->dispatcher->dispatch(new EntityEvent($entity), EntityEvents::PRE_SAVE->value);
        $entity->set('id', 1);
        $this->dispatcher->dispatch(new EntityEvent($entity), EntityEvents::POST_SAVE->value);

        $entries = $this->auditLogger->read();
        $this->assertCount(1, $entries);
        $this->assertSame('create', $entries[0]['action']);
        $this->assertSame('note', $entries[0]['entity_type']);
        $this->assertSame('acme', $entries[0]['tenant_id']);
    }

    #[Test]
    public function entityUpdateDispatchesUpdateAuditEntry(): void
    {
        $entity = $this->makeNote(['tenant_id' => 'acme', 'id' => 5], isNew: false);

        $this->dispatcher->dispatch(new EntityEvent($entity), EntityEvents::PRE_SAVE->value);
        $this->dispatcher->dispatch(new EntityEvent($entity), EntityEvents::POST_SAVE->value);

        $this->assertSame('update', $this->auditLogger->read()[0]['action']);
    }

    #[Test]
    public function entityDeleteDispatchesDeleteAuditEntry(): void
    {
        $entity = $this->makeNote(['tenant_id' => 'acme', 'id' => 3], isNew: false);

        $this->dispatcher->dispatch(new EntityEvent($entity), EntityEvents::POST_DELETE->value);

        $this->assertSame('delete', $this->auditLogger->read()[0]['action']);
    }

    #[Test]
    public function cliAuditLogEntityTypeShowsEntries(): void
    {
        $this->auditLogger->append(new EntityAuditEntry(
            actor: 'uid:1',
            action: 'create',
            entityId: '1',
            entityType: 'note',
            tenantId: 'acme',
        ));
        $this->auditLogger->append(new EntityAuditEntry(
            actor: 'uid:2',
            action: 'update',
            entityId: '2',
            entityType: 'article',
            tenantId: 'acme',
        ));

        $tester = $this->runAuditCommand(['--entity-type' => 'note']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('note', $output);
        $this->assertStringContainsString('create', $output);
        $this->assertStringNotContainsString('article', $output);
    }

    #[Test]
    public function cliAuditLogEntityTypeShowsAllWhenNoFilter(): void
    {
        $this->auditLogger->append(new EntityAuditEntry(
            actor: 'uid:1',
            action: 'create',
            entityId: '1',
            entityType: 'note',
            tenantId: 'acme',
        ));
        $this->auditLogger->append(new EntityAuditEntry(
            actor: 'uid:2',
            action: 'delete',
            entityId: '2',
            entityType: 'article',
            tenantId: 'acme',
        ));

        $tester = $this->runAuditCommand(['--entity-type' => '']);

        $output = $tester->getStdout();
        $this->assertStringContainsString('note', $output);
        $this->assertStringContainsString('article', $output);
    }

    #[Test]
    public function defaultRetentionIsNinetyDays(): void
    {
        $this->assertSame(90, EntityAuditLogger::DEFAULT_RETENTION_DAYS);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $values */
    private function makeNote(array $values, bool $isNew): EntityInterface
    {
        return new class ($values, $isNew) extends ContentEntityBase {
            private bool $new;

            public function __construct(array $values, bool $isNew)
            {
                parent::__construct($values, 'note', ['id' => 'id']);
                $this->new = $isNew;
            }

            public function isNew(): bool
            {
                return $this->new;
            }
        };
    }

    /** @param array<string, mixed> $input */
    private function runAuditCommand(array $input): CliTester
    {
        $lifecycleManager = new EntityTypeLifecycleManager($this->tempDir);
        $handler = new AuditLogHandler($lifecycleManager, $this->auditLogger);

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
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: {$id}");
            }
            public function has(string $id): bool
            {
                return false;
            }
        };

        $tester = CliTester::for($definition, $container);

        // Map old-style input array (e.g. ['--entity-type' => 'note']) to executeMap.
        $tester->executeMap($input);

        return $tester;
    }
}
