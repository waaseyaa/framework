<?php

declare(strict_types=1);

namespace Aurora\Entity\Tests\Unit\Event;

use Aurora\Entity\Event\EntityDeleted;
use Aurora\Entity\Event\EntitySaved;
use Aurora\Foundation\Event\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntitySaved::class)]
#[CoversClass(EntityDeleted::class)]
final class EntitySavedTest extends TestCase
{
    #[Test]
    public function entity_saved_is_domain_event(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: ['title', 'body'],
            isNew: false,
        );

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertSame('node', $event->aggregateType);
        $this->assertSame('42', $event->aggregateId);
    }

    #[Test]
    public function entity_saved_carries_changed_fields(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: ['title', 'body'],
            isNew: true,
        );

        $this->assertSame(['title', 'body'], $event->changedFields);
        $this->assertTrue($event->isNew);
    }

    #[Test]
    public function entity_saved_payload(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: ['title'],
            isNew: false,
        );

        $payload = $event->getPayload();

        $this->assertSame('node', $payload['entity_type']);
        $this->assertSame('42', $payload['entity_id']);
        $this->assertSame(['title'], $payload['changed_fields']);
        $this->assertFalse($payload['is_new']);
    }

    #[Test]
    public function entity_saved_carries_tenant_and_actor(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: [],
            isNew: false,
            tenantId: 'acme',
            actorId: 'user-7',
        );

        $this->assertSame('acme', $event->tenantId);
        $this->assertSame('user-7', $event->actorId);
    }

    #[Test]
    public function entity_deleted_is_domain_event(): void
    {
        $event = new EntityDeleted(
            entityTypeId: 'node',
            entityId: '42',
        );

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertSame('node', $event->aggregateType);
        $this->assertSame('42', $event->aggregateId);
    }
}
