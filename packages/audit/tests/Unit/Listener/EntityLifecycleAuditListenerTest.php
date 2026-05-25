<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\EntityLifecycleAuditListener;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

#[CoversClass(EntityLifecycleAuditListener::class)]
final class EntityLifecycleAuditListenerTest extends TestCase
{
    #[Test]
    public function it_records_entity_write_on_post_save(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('isNew')->willReturn(true);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000001');

        $listener = new EntityLifecycleAuditListener($writer);

        // PRE_SAVE captures isNew.
        $listener->onPreSave(new EntityEvent($entity));
        // POST_SAVE records.
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::EntityWrite, $recorded[0]->kind);
        $this->assertSame('allowed', $recorded[0]->outcome);
    }

    #[Test]
    public function it_records_entity_delete_on_post_delete(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000002');

        $listener = new EntityLifecycleAuditListener($writer);
        $listener->onPostDelete(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::EntityDelete, $recorded[0]->kind);
    }

    #[Test]
    public function it_does_not_bubble_exception_when_writer_fails(): void
    {
        $writer = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $d): void
            {
                throw new \RuntimeException('Writer broken — best-effort should swallow');
            }
        };

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('isNew')->willReturn(false);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000003');

        $listener = new EntityLifecycleAuditListener($writer);
        $listener->onPreSave(new EntityEvent($entity));

        // Must not throw.
        $listener->onPostSave(new EntityEvent($entity));
        $this->assertTrue(true, 'Best-effort: no exception bubbled up');
    }

    #[Test]
    public function it_subscribes_to_the_correct_entity_events(): void
    {
        $subscribed = EntityLifecycleAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(EntityEvents::PRE_SAVE->value, $subscribed);
        $this->assertArrayHasKey(EntityEvents::POST_SAVE->value, $subscribed);
        $this->assertArrayHasKey(EntityEvents::POST_DELETE->value, $subscribed);
    }
}
