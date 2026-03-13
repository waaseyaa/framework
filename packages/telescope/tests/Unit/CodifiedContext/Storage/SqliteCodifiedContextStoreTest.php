<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;
use Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextStoreInterface;
use Waaseyaa\Telescope\CodifiedContext\Storage\SqliteCodifiedContextStore;
use Waaseyaa\Telescope\TelescopeEntry;

#[CoversClass(SqliteCodifiedContextStore::class)]
final class SqliteCodifiedContextStoreTest extends TestCase
{
    private SqliteCodifiedContextStore $store;

    protected function setUp(): void
    {
        $this->store = SqliteCodifiedContextStore::createInMemory();
    }

    #[Test]
    public function implementsCodifiedContextStoreInterface(): void
    {
        $this->assertInstanceOf(CodifiedContextStoreInterface::class, $this->store);
    }

    #[Test]
    public function storeAndQueryReturnsTelescopeEntries(): void
    {
        $this->store->store('drift_detected', ['session_id' => 'sess-1', 'severity' => 'high']);
        $this->store->store('drift_detected', ['session_id' => 'sess-2', 'severity' => 'low']);

        $entries = $this->store->query('drift_detected');

        $this->assertCount(2, $entries);
        $this->assertContainsOnlyInstancesOf(TelescopeEntry::class, $entries);
    }

    #[Test]
    public function queryFiltersByType(): void
    {
        $this->store->store('drift_detected', ['session_id' => 'sess-1']);
        $this->store->store('validation_passed', ['session_id' => 'sess-1']);

        $driftEntries = $this->store->query('drift_detected');
        $validationEntries = $this->store->query('validation_passed');

        $this->assertCount(1, $driftEntries);
        $this->assertCount(1, $validationEntries);
    }

    #[Test]
    public function queryReturnsEntriesInDescendingOrder(): void
    {
        $this->store->store('event', ['session_id' => 'sess-1', 'order' => 'first']);
        usleep(1000);
        $this->store->store('event', ['session_id' => 'sess-1', 'order' => 'second']);

        $entries = $this->store->query('event');

        $this->assertSame('second', $entries[0]->data['order']);
        $this->assertSame('first', $entries[1]->data['order']);
    }

    #[Test]
    public function queryRespectsLimitAndOffset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store->store('event', ['session_id' => "sess-{$i}"]);
            usleep(500);
        }

        $page1 = $this->store->query('event', limit: 2, offset: 0);
        $page2 = $this->store->query('event', limit: 2, offset: 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $ids1 = array_map(fn(TelescopeEntry $e) => $e->id, $page1);
        $ids2 = array_map(fn(TelescopeEntry $e) => $e->id, $page2);
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    #[Test]
    public function queryBySessionReturnsCodifiedContextEntries(): void
    {
        $this->store->store('event', ['session_id' => 'sess-A', 'x' => 1]);
        $this->store->store('event', ['session_id' => 'sess-B', 'x' => 2]);
        $this->store->store('event', ['session_id' => 'sess-A', 'x' => 3]);

        $entries = $this->store->queryBySession('sess-A');

        $this->assertCount(2, $entries);
        $this->assertContainsOnlyInstancesOf(CodifiedContextEntry::class, $entries);
        foreach ($entries as $entry) {
            $this->assertSame('sess-A', $entry->sessionId);
        }
    }

    #[Test]
    public function queryBySessionReturnsEmptyForUnknownSession(): void
    {
        $this->store->store('event', ['session_id' => 'sess-X']);

        $this->assertSame([], $this->store->queryBySession('sess-UNKNOWN'));
    }

    #[Test]
    public function queryByEventTypeUsesJsonExtract(): void
    {
        $this->store->store('cc', ['session_id' => 's1', 'event_type' => 'drift_detected']);
        $this->store->store('cc', ['session_id' => 's2', 'event_type' => 'validation_passed']);
        $this->store->store('cc', ['session_id' => 's3', 'event_type' => 'drift_detected']);

        $entries = $this->store->queryByEventType('drift_detected');

        $this->assertCount(2, $entries);
        $this->assertContainsOnlyInstancesOf(CodifiedContextEntry::class, $entries);
    }

    #[Test]
    public function queryByDriftSeverityUsesJsonExtract(): void
    {
        $this->store->store('drift', ['session_id' => 's1', 'severity' => 'critical']);
        $this->store->store('drift', ['session_id' => 's2', 'severity' => 'low']);
        $this->store->store('drift', ['session_id' => 's3', 'severity' => 'critical']);

        $entries = $this->store->queryByDriftSeverity('critical');

        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame('critical', $entry->data['severity']);
        }
    }

    #[Test]
    public function queryByTimeRangeFiltersCorrectly(): void
    {
        $this->store->store('event', ['session_id' => 's1']);
        $start = new \DateTimeImmutable();
        usleep(2000);
        $this->store->store('event', ['session_id' => 's2']);
        usleep(2000);
        $end = new \DateTimeImmutable();
        usleep(2000);
        $this->store->store('event', ['session_id' => 's3']);

        $entries = $this->store->queryByTimeRange($start, $end);

        $this->assertCount(1, $entries);
        $this->assertSame('s2', $entries[0]->data['session_id']);
    }

    #[Test]
    public function pruneRemovesOldEntries(): void
    {
        $this->store->store('event', ['session_id' => 's1']);
        $this->store->store('event', ['session_id' => 's2']);

        $pruned = $this->store->prune(new \DateTimeImmutable('+1 second'));

        $this->assertSame(2, $pruned);
        $this->assertSame([], $this->store->query('event'));
    }

    #[Test]
    public function pruneKeepsRecentEntries(): void
    {
        $this->store->store('event', ['session_id' => 's1']);

        $pruned = $this->store->prune(new \DateTimeImmutable('-1 day'));

        $this->assertSame(0, $pruned);
        $this->assertCount(1, $this->store->query('event'));
    }

    #[Test]
    public function clearRemovesAllEntries(): void
    {
        $this->store->store('event', ['session_id' => 's1']);
        $this->store->store('drift', ['session_id' => 's2', 'severity' => 'high']);

        $this->store->clear();

        $this->assertSame([], $this->store->query('event'));
        $this->assertSame([], $this->store->query('drift'));
    }

    #[Test]
    public function storesAndRestoresComplexData(): void
    {
        $data = [
            'session_id' => 'sess-complex',
            'nested' => ['a' => 1, 'b' => [2, 3]],
            'severity' => 'medium',
        ];

        $this->store->store('complex', $data);
        $entries = $this->store->query('complex');

        $this->assertCount(1, $entries);
        $this->assertSame($data, $entries[0]->data);
    }
}
