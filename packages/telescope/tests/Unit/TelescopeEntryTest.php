<?php

declare(strict_types=1);

namespace Aurora\Telescope\Tests\Unit;

use Aurora\Telescope\TelescopeEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelescopeEntry::class)]
final class TelescopeEntryTest extends TestCase
{
    #[Test]
    public function constructsWithTypeAndData(): void
    {
        $entry = new TelescopeEntry(
            type: 'query',
            data: ['sql' => 'SELECT 1', 'duration' => 1.5],
        );

        $this->assertSame('query', $entry->type);
        $this->assertSame(['sql' => 'SELECT 1', 'duration' => 1.5], $entry->data);
        $this->assertNotEmpty($entry->id);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->createdAt);
    }

    #[Test]
    public function constructsWithExplicitIdAndTimestamp(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-15 10:30:00');

        $entry = new TelescopeEntry(
            type: 'event',
            data: ['event' => 'UserCreated'],
            id: 'custom-id-123',
            createdAt: $timestamp,
        );

        $this->assertSame('custom-id-123', $entry->id);
        $this->assertSame($timestamp, $entry->createdAt);
    }

    #[Test]
    public function toArrayReturnsExpectedStructure(): void
    {
        $timestamp = new \DateTimeImmutable('2026-02-01 12:00:00.000000');

        $entry = new TelescopeEntry(
            type: 'request',
            data: ['method' => 'GET', 'uri' => '/api/nodes'],
            id: 'entry-001',
            createdAt: $timestamp,
        );

        $array = $entry->toArray();

        $this->assertSame('entry-001', $array['id']);
        $this->assertSame('request', $array['type']);
        $this->assertSame(['method' => 'GET', 'uri' => '/api/nodes'], $array['data']);
        $this->assertSame('2026-02-01 12:00:00.000000', $array['created_at']);
    }

    #[Test]
    public function fromArrayReconstructsEntry(): void
    {
        $row = [
            'id' => 'entry-002',
            'type' => 'cache',
            'data' => json_encode(['operation' => 'hit', 'key' => 'user:1']),
            'created_at' => '2026-02-01 14:30:00.000000',
        ];

        $entry = TelescopeEntry::fromArray($row);

        $this->assertSame('entry-002', $entry->id);
        $this->assertSame('cache', $entry->type);
        $this->assertSame(['operation' => 'hit', 'key' => 'user:1'], $entry->data);
        $this->assertSame('2026-02-01 14:30:00.000000', $entry->createdAt->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    public function fromArrayAcceptsAlreadyDecodedData(): void
    {
        $row = [
            'id' => 'entry-003',
            'type' => 'event',
            'data' => ['event' => 'NodeSaved'],
            'created_at' => '2026-02-01 15:00:00.000000',
        ];

        $entry = TelescopeEntry::fromArray($row);

        $this->assertSame(['event' => 'NodeSaved'], $entry->data);
    }

    #[Test]
    public function generatesUniqueIds(): void
    {
        $entry1 = new TelescopeEntry(type: 'query', data: []);
        $entry2 = new TelescopeEntry(type: 'query', data: []);

        $this->assertNotSame($entry1->id, $entry2->id);
    }
}
