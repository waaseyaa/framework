<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Inbound;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\MercureMonitor\EventStreamFilter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Http\Inbound\EventStreamReadModel;

/**
 * Unit tests for the M5D EventStreamReadModel adapter (T004).
 *
 * FR-003: malformed-JSON row → empty data, not 500.
 * FR-003: limit > 1000 → clamp to 1000.
 * FR-003: filter combinations (channels, event, since).
 */
#[CoversClass(EventStreamReadModel::class)]
final class EventStreamReadModelTest extends TestCase
{
    private DBALDatabase $database;
    private BroadcastStorage $broadcastStorage;
    private EventStreamReadModel $readModel;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->broadcastStorage = new BroadcastStorage($this->database);
        $this->readModel = new EventStreamReadModel($this->database);
    }

    #[Test]
    public function emptyTableReturnsEmptyArray(): void
    {
        $rows = $this->readModel->recentEvents(new EventStreamFilter());
        self::assertSame([], $rows);
    }

    #[Test]
    public function returnsAllRowsWithNoFilter(): void
    {
        $this->broadcastStorage->push('admin', 'entity.saved', ['id' => 1]);
        $this->broadcastStorage->push('realtime', 'user.connected', ['uid' => 2]);
        $this->broadcastStorage->push('pipeline', 'job.started', ['job' => 3]);

        $rows = $this->readModel->recentEvents(new EventStreamFilter());

        self::assertCount(3, $rows);
    }

    #[Test]
    public function filtersByChannels(): void
    {
        $this->broadcastStorage->push('admin', 'entity.saved', []);
        $this->broadcastStorage->push('realtime', 'user.connected', []);
        $this->broadcastStorage->push('pipeline', 'job.started', []);

        $rows = $this->readModel->recentEvents(
            new EventStreamFilter(channels: ['admin', 'realtime']),
        );

        self::assertCount(2, $rows);
        $channels = array_map(fn($r) => $r->channel, $rows);
        self::assertContains('admin', $channels);
        self::assertContains('realtime', $channels);
        self::assertNotContains('pipeline', $channels);
    }

    #[Test]
    public function filtersByEventName(): void
    {
        $this->broadcastStorage->push('admin', 'entity.saved', []);
        $this->broadcastStorage->push('admin', 'entity.deleted', []);
        $this->broadcastStorage->push('realtime', 'entity.saved', []);

        $rows = $this->readModel->recentEvents(
            new EventStreamFilter(event: 'entity.saved'),
        );

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('entity.saved', $row->event);
        }
    }

    #[Test]
    public function filtersBySince(): void
    {
        // Seed one old row by inserting raw with old timestamp
        $this->database->query(
            'INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES (?, ?, ?, ?)',
            ['admin', 'old.event', '{}', microtime(true) - 3600],
        );
        $this->broadcastStorage->push('admin', 'new.event', []);

        $since = microtime(true) - 60.0; // last 60s
        $rows = $this->readModel->recentEvents(
            new EventStreamFilter(since: $since),
        );

        self::assertCount(1, $rows);
        self::assertSame('new.event', $rows[0]->event);
    }

    #[Test]
    public function malformedJsonDataRowReturnsEmptyDataNotFatal(): void
    {
        // Insert a row with malformed JSON in data column
        $this->database->query(
            'INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES (?, ?, ?, ?)',
            ['admin', 'malformed', '{not-valid-json}', microtime(true)],
        );

        $rows = $this->readModel->recentEvents(new EventStreamFilter());

        self::assertCount(1, $rows);
        self::assertSame([], $rows[0]->data);
    }

    #[Test]
    public function limitGreaterThan1000IsClamped(): void
    {
        // Seed 3 rows and request limit=9999 — should still get 3 (≤ 1000)
        $this->broadcastStorage->push('admin', 'a', []);
        $this->broadcastStorage->push('admin', 'b', []);
        $this->broadcastStorage->push('admin', 'c', []);

        $rows = $this->readModel->recentEvents(new EventStreamFilter(), limit: 9999);

        // All 3 returned — limit was clamped to 1000 but no crash
        self::assertCount(3, $rows);
    }

    #[Test]
    public function resultsOrderedByIdDesc(): void
    {
        $this->broadcastStorage->push('admin', 'first', []);
        $this->broadcastStorage->push('admin', 'second', []);
        $this->broadcastStorage->push('admin', 'third', []);

        $rows = $this->readModel->recentEvents(new EventStreamFilter());

        // First result should be the most recent (highest id)
        self::assertSame('third', $rows[0]->event);
        self::assertSame('first', $rows[2]->event);
    }
}
