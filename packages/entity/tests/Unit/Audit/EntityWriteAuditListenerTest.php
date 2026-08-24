<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\Audit\EntityWriteAuditListener;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

#[CoversClass(EntityWriteAuditListener::class)]
final class EntityWriteAuditListenerTest extends TestCase
{
    private string $projectRoot;
    private EntityAuditLogger $logger;
    private EntityWriteAuditListener $listener;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_entity_audit_listener_test_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
        $this->logger   = new EntityAuditLogger($this->projectRoot);
        $this->listener = new EntityWriteAuditListener($this->logger);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    #[Test]
    public function onPreSaveRecordsIsNewState(): void
    {
        $entity = $this->makeEntity('note', ['tenant_id' => 'acme'], isNew: true);

        $this->listener->onPreSave(new EntityEvent($entity));
        $this->listener->onPostSave(new EntityEvent($entity));

        $entries = $this->logger->read();
        $this->assertCount(1, $entries);
        $this->assertSame('create', $entries[0]['action']);
    }

    #[Test]
    public function onPostSaveLogsUpdateWhenEntityWasNotNew(): void
    {
        $entity = $this->makeEntity('note', ['tenant_id' => 'acme'], isNew: false);

        $this->listener->onPreSave(new EntityEvent($entity));
        $this->listener->onPostSave(new EntityEvent($entity));

        $entries = $this->logger->read();
        $this->assertCount(1, $entries);
        $this->assertSame('update', $entries[0]['action']);
    }

    #[Test]
    public function onPostSaveIncludesRequiredAuditFields(): void
    {
        $entity = $this->makeEntity('note', ['tenant_id' => 'acme'], isNew: true);
        $entity->set('id', 42);

        $this->listener->onPreSave(new EntityEvent($entity));
        $this->listener->onPostSave(new EntityEvent($entity));

        $entry = $this->logger->read()[0];
        $this->assertArrayHasKey('actor', $entry);
        $this->assertArrayHasKey('action', $entry);
        $this->assertArrayHasKey('entity_id', $entry);
        $this->assertArrayHasKey('entity_type', $entry);
        $this->assertArrayHasKey('tenant_id', $entry);
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertSame('note', $entry['entity_type']);
        $this->assertSame('acme', $entry['tenant_id']);
    }

    #[Test]
    public function onPostDeleteLogsDeleteAction(): void
    {
        $entity = $this->makeEntity('note', ['tenant_id' => 'acme'], isNew: false);
        $entity->set('id', 7);

        $this->listener->onPostDelete(new EntityEvent($entity));

        $entries = $this->logger->read();
        $this->assertCount(1, $entries);
        $this->assertSame('delete', $entries[0]['action']);
    }

    #[Test]
    public function actorDefaultsToSystemWhenNoUidOnEntity(): void
    {
        $entity = $this->makeEntity('note', ['tenant_id' => 'acme'], isNew: true);

        $this->listener->onPreSave(new EntityEvent($entity));
        $this->listener->onPostSave(new EntityEvent($entity));

        $this->assertSame('system', $this->logger->read()[0]['actor']);
    }

    #[Test]
    public function actorIsUidPrefixedWhenEntityHasUid(): void
    {
        $entity = $this->makeEntity('note', ['tenant_id' => 'acme', 'uid' => 99], isNew: true);

        $this->listener->onPreSave(new EntityEvent($entity));
        $this->listener->onPostSave(new EntityEvent($entity));

        $this->assertSame('uid:99', $this->logger->read()[0]['actor']);
    }

    #[Test]
    public function getSubscribedEventsCoversPreSavePostSaveAndPostDelete(): void
    {
        $events = EntityWriteAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(EntityEvents::PRE_SAVE->value, $events);
        $this->assertArrayHasKey(EntityEvents::POST_SAVE->value, $events);
        $this->assertArrayHasKey(EntityEvents::POST_DELETE->value, $events);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $values */
    private function makeEntity(string $typeId, array $values, bool $isNew): EntityInterface
    {
        return new class($typeId, $values, $isNew) extends ContentEntityBase {
            private bool $new;

            public function __construct(string $typeId, array $values, bool $isNew)
            {
                parent::__construct($values, $typeId, ['id' => 'id']);
                $this->new = $isNew;
            }

            public function isNew(): bool { return $this->new; }
        };
    }
}
