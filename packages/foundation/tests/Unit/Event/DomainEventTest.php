<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Event;

use Aurora\Foundation\Event\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomainEvent::class)]
final class DomainEventTest extends TestCase
{
    #[Test]
    public function carries_aggregate_identity(): void
    {
        $event = new class('node', '42') extends DomainEvent {
            public function getPayload(): array { return ['test' => true]; }
        };

        $this->assertSame('node', $event->aggregateType);
        $this->assertSame('42', $event->aggregateId);
    }

    #[Test]
    public function generates_uuid_event_id(): void
    {
        $event = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertNotEmpty($event->eventId);
        // UUIDv7 format check: 36 chars with hyphens
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $event->eventId,
        );
    }

    #[Test]
    public function records_occurred_at_timestamp(): void
    {
        $before = new \DateTimeImmutable();
        $event = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $event->occurredAt);
        $this->assertLessThanOrEqual($after, $event->occurredAt);
    }

    #[Test]
    public function carries_optional_tenant_and_actor(): void
    {
        $event = new class('node', '1', 'acme', 'user-7') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertSame('acme', $event->tenantId);
        $this->assertSame('user-7', $event->actorId);
    }

    #[Test]
    public function tenant_and_actor_default_to_null(): void
    {
        $event = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertNull($event->tenantId);
        $this->assertNull($event->actorId);
    }

    #[Test]
    public function two_events_have_different_ids(): void
    {
        $event1 = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };
        $event2 = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertNotSame($event1->eventId, $event2->eventId);
    }
}
