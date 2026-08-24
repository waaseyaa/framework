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

    #[Test]
    public function interleavedPrePrePostPostExistingThenNewKeepsPerEntityAction(): void
    {
        $existing = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 1], isNew: false);
        $new      = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 2], isNew: true);

        $this->dispatchInterleavedSavePair($existing, $new);

        $this->assertSame(
            ['update', 'create'],
            array_column($this->logger->read(), 'action'),
            'saveMany([existing, new]) buffers POST until after both PREs; each POST must use its own PRE isNew().',
        );
        $this->assertPendingMapEmpty();
    }

    #[Test]
    public function interleavedPrePrePostPostNewThenExistingKeepsPerEntityAction(): void
    {
        $new      = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 2], isNew: true);
        $existing = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 1], isNew: false);

        $this->dispatchInterleavedSavePair($new, $existing);

        $this->assertSame(
            ['create', 'update'],
            array_column($this->logger->read(), 'action'),
            'saveMany([new, existing]) must not let the existing entity\'s PRE overwrite the new entity\'s create action.',
        );
        $this->assertPendingMapEmpty();
    }

    #[Test]
    public function listenerReuseAfterMixedBatchDoesNotLeakStaleIsNew(): void
    {
        $existing = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 1], isNew: false);
        $new      = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 2], isNew: true);
        $this->dispatchInterleavedSavePair($existing, $new);

        $laterExisting = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 3], isNew: false);
        $this->listener->onPreSave(new EntityEvent($laterExisting));
        $this->listener->onPostSave(new EntityEvent($laterExisting));

        $actions = array_column($this->logger->read(), 'action');
        $this->assertSame(['update', 'create', 'update'], $actions);
        $this->assertPendingMapEmpty();
    }

    #[Test]
    public function exceptionDuringSecondPostSaveDoesNotRewriteTheFirstEntityAction(): void
    {
        $existing = $this->makeEntity('note', ['tenant_id' => 'acme', 'id' => 10], isNew: false);
        $exploding = $this->makeExplodingEntity('note', ['tenant_id' => 'acme', 'id' => 11], isNew: true);

        $this->listener->onPreSave(new EntityEvent($existing));
        $this->listener->onPreSave(new EntityEvent($exploding));
        $this->listener->onPostSave(new EntityEvent($existing));
        try {
            $this->listener->onPostSave(new EntityEvent($exploding));
            $this->fail('Expected the exploding entity to throw during POST_SAVE.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit-post-save-boom', $e->getMessage());
        }

        $this->assertSame(['update'], array_column($this->logger->read(), 'action'));
        $this->assertPendingMapEmpty();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function dispatchInterleavedSavePair(EntityInterface $first, EntityInterface $second): void
    {
        $this->listener->onPreSave(new EntityEvent($first));
        $this->listener->onPreSave(new EntityEvent($second));
        $this->listener->onPostSave(new EntityEvent($first));
        $this->listener->onPostSave(new EntityEvent($second));
    }

    private function assertPendingMapEmpty(): void
    {
        $property = new \ReflectionProperty($this->listener, 'pendingIsNew');
        $pending = $property->getValue($this->listener);
        $this->assertInstanceOf(\WeakMap::class, $pending);
        $this->assertCount(0, iterator_to_array($pending, false));
    }

    /** @param array<string, mixed> $values */
    private function makeEntity(string $typeId, array $values, bool $isNew): EntityInterface
    {
        return $this->makeExplodingEntity($typeId, $values, $isNew, explode: false);
    }

    /** @param array<string, mixed> $values */
    private function makeExplodingEntity(string $typeId, array $values, bool $isNew, bool $explode = true): EntityInterface
    {
        return new class($typeId, $values, $isNew, $explode) extends ContentEntityBase {
            public function __construct(
                string $typeId,
                array $values,
                private readonly bool $new,
                private readonly bool $explodeOnTypeId,
            ) {
                parent::__construct($values, $typeId, ['id' => 'id']);
            }

            public function isNew(): bool { return $this->new; }

            public function getEntityTypeId(): string
            {
                if ($this->explodeOnTypeId) {
                    throw new \RuntimeException('audit-post-save-boom');
                }

                return parent::getEntityTypeId();
            }
        };
    }
}
