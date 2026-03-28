<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Ingestion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Ingestion\Envelope;

#[CoversClass(Envelope::class)]
final class EnvelopeTest extends TestCase
{
    #[Test]
    public function constructWithAllFields(): void
    {
        $envelope = new Envelope(
            source: 'manual',
            type: 'core.note',
            payload: ['title' => 'Hello'],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            tenantId: 'tenant-1',
            metadata: ['priority' => 'high'],
        );

        $this->assertSame('manual', $envelope->source);
        $this->assertSame('core.note', $envelope->type);
        $this->assertSame(['title' => 'Hello'], $envelope->payload);
        $this->assertSame('2026-03-08T17:00:00+00:00', $envelope->timestamp);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $envelope->traceId);
        $this->assertSame('tenant-1', $envelope->tenantId);
        $this->assertSame(['priority' => 'high'], $envelope->metadata);
    }

    #[Test]
    public function constructWithOptionalFieldsOmitted(): void
    {
        $envelope = new Envelope(
            source: 'api',
            type: 'core.note',
            payload: [],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
        );

        $this->assertNull($envelope->tenantId);
        $this->assertSame([], $envelope->metadata);
    }

    #[Test]
    public function toArrayProducesExpectedShape(): void
    {
        $envelope = new Envelope(
            source: 'manual',
            type: 'core.note',
            payload: ['title' => 'Hello'],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
            tenantId: 'tenant-1',
            metadata: ['priority' => 'high'],
        );

        $arr = $envelope->toArray();

        $this->assertSame('manual', $arr['source']);
        $this->assertSame('core.note', $arr['type']);
        $this->assertSame(['title' => 'Hello'], $arr['payload']);
        $this->assertSame('2026-03-08T17:00:00+00:00', $arr['timestamp']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $arr['trace_id']);
        $this->assertSame('tenant-1', $arr['tenant_id']);
        $this->assertSame(['priority' => 'high'], $arr['metadata']);
    }

    #[Test]
    public function toArrayIncludesNullTenantId(): void
    {
        $envelope = new Envelope(
            source: 'api',
            type: 'core.note',
            payload: [],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
        );

        $arr = $envelope->toArray();

        $this->assertArrayHasKey('tenant_id', $arr);
        $this->assertNull($arr['tenant_id']);
    }

    #[Test]
    public function toArrayIncludesEmptyMetadata(): void
    {
        $envelope = new Envelope(
            source: 'api',
            type: 'core.note',
            payload: [],
            timestamp: '2026-03-08T17:00:00+00:00',
            traceId: '550e8400-e29b-41d4-a716-446655440000',
        );

        $arr = $envelope->toArray();

        $this->assertSame([], $arr['metadata']);
    }
}
