<?php

declare(strict_types=1);

namespace Aurora\Telescope\Tests\Unit\Storage;

use Aurora\Telescope\Storage\SqliteTelescopeStore;
use Aurora\Telescope\Storage\TelescopeStoreInterface;
use Aurora\Telescope\TelescopeEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteTelescopeStore::class)]
final class SqliteTelescopeStoreTest extends TestCase
{
    private SqliteTelescopeStore $store;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
    }

    #[Test]
    public function implementsTelescopeStoreInterface(): void
    {
        $this->assertInstanceOf(TelescopeStoreInterface::class, $this->store);
    }

    #[Test]
    public function storeAndQueryEntries(): void
    {
        $this->store->store('query', ['sql' => 'SELECT 1', 'duration' => 5.0]);
        $this->store->store('query', ['sql' => 'SELECT 2', 'duration' => 10.0]);

        $entries = $this->store->query('query');

        $this->assertCount(2, $entries);
        $this->assertContainsOnlyInstancesOf(TelescopeEntry::class, $entries);
    }

    #[Test]
    public function queryReturnsEntriesInDescendingOrder(): void
    {
        $this->store->store('query', ['sql' => 'first']);
        usleep(1000); // Ensure different timestamps.
        $this->store->store('query', ['sql' => 'second']);

        $entries = $this->store->query('query');

        // Most recent first.
        $this->assertSame('second', $entries[0]->data['sql']);
        $this->assertSame('first', $entries[1]->data['sql']);
    }

    #[Test]
    public function queryFiltersByType(): void
    {
        $this->store->store('query', ['sql' => 'SELECT 1']);
        $this->store->store('event', ['event' => 'UserCreated']);
        $this->store->store('query', ['sql' => 'SELECT 2']);

        $queries = $this->store->query('query');
        $events = $this->store->query('event');

        $this->assertCount(2, $queries);
        $this->assertCount(1, $events);
    }

    #[Test]
    public function queryRespectsLimitAndOffset(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->store->store('query', ['sql' => "SELECT {$i}"]);
            usleep(1000);
        }

        $page1 = $this->store->query('query', limit: 3, offset: 0);
        $page2 = $this->store->query('query', limit: 3, offset: 3);

        $this->assertCount(3, $page1);
        $this->assertCount(3, $page2);
        // Pages should not overlap (entries are desc by created_at).
        $page1Ids = array_map(fn(TelescopeEntry $e) => $e->id, $page1);
        $page2Ids = array_map(fn(TelescopeEntry $e) => $e->id, $page2);
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids));
    }

    #[Test]
    public function queryReturnsEmptyArrayWhenNoEntriesExist(): void
    {
        $entries = $this->store->query('query');

        $this->assertSame([], $entries);
    }

    #[Test]
    public function pruneRemovesOldEntries(): void
    {
        $this->store->store('query', ['sql' => 'old query']);
        $this->store->store('event', ['event' => 'old event']);

        // Prune everything before "now + 1 second" to remove everything.
        $pruned = $this->store->prune(new \DateTimeImmutable('+1 second'));

        $this->assertSame(2, $pruned);
        $this->assertSame([], $this->store->query('query'));
        $this->assertSame([], $this->store->query('event'));
    }

    #[Test]
    public function pruneReturnsZeroWhenNothingToPrune(): void
    {
        $this->store->store('query', ['sql' => 'fresh']);

        // Prune entries older than yesterday - nothing should be pruned.
        $pruned = $this->store->prune(new \DateTimeImmutable('-1 day'));

        $this->assertSame(0, $pruned);
        $this->assertCount(1, $this->store->query('query'));
    }

    #[Test]
    public function clearRemovesAllEntries(): void
    {
        $this->store->store('query', ['sql' => 'SELECT 1']);
        $this->store->store('event', ['event' => 'Test']);
        $this->store->store('cache', ['key' => 'users:1']);

        $this->store->clear();

        $this->assertSame([], $this->store->query('query'));
        $this->assertSame([], $this->store->query('event'));
        $this->assertSame([], $this->store->query('cache'));
    }

    #[Test]
    public function clearOnEmptyStoreDoesNotError(): void
    {
        // Should not throw.
        $this->store->clear();

        $this->assertSame([], $this->store->query('query'));
    }

    #[Test]
    public function storesComplexJsonData(): void
    {
        $complexData = [
            'sql' => 'SELECT * FROM users WHERE id = ?',
            'bindings' => [42],
            'duration' => 12.5,
            'connection' => 'default',
            'nested' => ['key' => 'value', 'list' => [1, 2, 3]],
        ];

        $this->store->store('query', $complexData);

        $entries = $this->store->query('query');
        $this->assertCount(1, $entries);
        $this->assertSame($complexData, $entries[0]->data);
    }

    #[Test]
    public function entryHasValidIdAndType(): void
    {
        $this->store->store('request', ['method' => 'GET', 'uri' => '/']);

        $entries = $this->store->query('request');
        $entry = $entries[0];

        $this->assertNotEmpty($entry->id);
        $this->assertSame('request', $entry->type);
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->createdAt);
    }
}
