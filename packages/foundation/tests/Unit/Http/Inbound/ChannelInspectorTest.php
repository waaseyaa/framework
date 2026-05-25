<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Inbound;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Http\Inbound\ChannelInspector;

/**
 * Unit tests for the M5D ChannelInspector adapter (T004).
 *
 * Uses DBALDatabase::createSqlite() + BroadcastStorage::ensureTable()
 * to seed _broadcast_log rows, then asserts grouped counts + last event
 * metadata per the FR-002 spec.
 */
#[CoversClass(ChannelInspector::class)]
final class ChannelInspectorTest extends TestCase
{
    private DBALDatabase $database;
    private BroadcastStorage $broadcastStorage;
    private ChannelInspector $inspector;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->broadcastStorage = new BroadcastStorage($this->database);
        $this->inspector = new ChannelInspector($this->database);
    }

    #[Test]
    public function emptyTableReturnsEmptyArray(): void
    {
        $rows = $this->inspector->listChannels();
        self::assertSame([], $rows);
    }

    #[Test]
    public function groupsRowsByChannelWithCorrectCounts(): void
    {
        // Seed ≥3 channels with multiple events each
        $this->broadcastStorage->push('admin', 'entity.saved', ['a' => 1]);
        $this->broadcastStorage->push('admin', 'entity.deleted', ['a' => 2]);
        $this->broadcastStorage->push('admin', 'entity.saved', ['a' => 3]);
        $this->broadcastStorage->push('realtime', 'user.connected', ['b' => 1]);
        $this->broadcastStorage->push('realtime', 'user.disconnected', ['b' => 2]);
        $this->broadcastStorage->push('pipeline', 'job.started', ['c' => 1]);

        $rows = $this->inspector->listChannels();

        self::assertCount(3, $rows);

        // Find by channel name
        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[$row->channel] = $row;
        }

        self::assertArrayHasKey('admin', $byChannel);
        self::assertSame(3, $byChannel['admin']->eventCount24h);
        self::assertNotNull($byChannel['admin']->lastEventAt);
        self::assertNotNull($byChannel['admin']->lastEventName);

        self::assertArrayHasKey('realtime', $byChannel);
        self::assertSame(2, $byChannel['realtime']->eventCount24h);

        self::assertArrayHasKey('pipeline', $byChannel);
        self::assertSame(1, $byChannel['pipeline']->eventCount24h);
    }

    #[Test]
    public function returnsLastEventMetadataPerChannel(): void
    {
        $this->broadcastStorage->push('admin', 'entity.saved', []);
        $this->broadcastStorage->push('admin', 'entity.updated', []);

        $rows = $this->inspector->listChannels();
        self::assertCount(1, $rows);
        // MAX(event) alphabetically: entity.updated > entity.saved
        self::assertSame('admin', $rows[0]->channel);
        self::assertSame(2, $rows[0]->eventCount24h);
        self::assertNotNull($rows[0]->lastEventAt);
        self::assertNotNull($rows[0]->lastEventName);
    }
}
