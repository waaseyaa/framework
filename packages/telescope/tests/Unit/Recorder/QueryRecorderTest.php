<?php

declare(strict_types=1);

namespace Aurora\Telescope\Tests\Unit\Recorder;

use Aurora\Telescope\Recorder\QueryRecorder;
use Aurora\Telescope\Storage\SqliteTelescopeStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryRecorder::class)]
final class QueryRecorderTest extends TestCase
{
    private SqliteTelescopeStore $store;

    protected function setUp(): void
    {
        $this->store = SqliteTelescopeStore::createInMemory();
    }

    #[Test]
    public function recordsQueryWithAllFields(): void
    {
        $recorder = new QueryRecorder(store: $this->store);

        $recorder->record(
            sql: 'SELECT * FROM users WHERE id = ?',
            bindings: [42],
            duration: 5.3,
            connection: 'default',
        );

        $entries = $this->store->query(QueryRecorder::TYPE);
        $this->assertCount(1, $entries);

        $data = $entries[0]->data;
        $this->assertSame('SELECT * FROM users WHERE id = ?', $data['sql']);
        $this->assertSame([42], $data['bindings']);
        $this->assertSame(5.3, $data['duration']);
        $this->assertSame('default', $data['connection']);
        $this->assertFalse($data['slow']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    #[Test]
    public function marksSlowQueriesWhenAboveThreshold(): void
    {
        $recorder = new QueryRecorder(
            store: $this->store,
            slowQueryThreshold: 50.0,
        );

        $recorder->record(sql: 'SELECT 1', bindings: [], duration: 75.0);

        $entries = $this->store->query(QueryRecorder::TYPE);
        $this->assertTrue($entries[0]->data['slow']);
    }

    #[Test]
    public function marksQueryAtExactThresholdAsSlow(): void
    {
        $recorder = new QueryRecorder(
            store: $this->store,
            slowQueryThreshold: 100.0,
        );

        $recorder->record(sql: 'SELECT 1', bindings: [], duration: 100.0);

        $entries = $this->store->query(QueryRecorder::TYPE);
        $this->assertTrue($entries[0]->data['slow']);
    }

    #[Test]
    public function slowQueriesOnlyFiltersFastQueries(): void
    {
        $recorder = new QueryRecorder(
            store: $this->store,
            slowQueryThreshold: 50.0,
            slowQueriesOnly: true,
        );

        $recorder->record(sql: 'SELECT 1', bindings: [], duration: 10.0); // Fast, should be ignored.
        $recorder->record(sql: 'SELECT 2', bindings: [], duration: 75.0); // Slow, should be recorded.

        $entries = $this->store->query(QueryRecorder::TYPE);
        $this->assertCount(1, $entries);
        $this->assertSame('SELECT 2', $entries[0]->data['sql']);
    }

    #[Test]
    public function defaultConnectionIsDefault(): void
    {
        $recorder = new QueryRecorder(store: $this->store);

        $recorder->record(sql: 'SELECT 1', bindings: [], duration: 1.0);

        $entries = $this->store->query(QueryRecorder::TYPE);
        $this->assertSame('default', $entries[0]->data['connection']);
    }

    #[Test]
    public function exposesConfiguration(): void
    {
        $recorder = new QueryRecorder(
            store: $this->store,
            slowQueryThreshold: 200.0,
            slowQueriesOnly: true,
        );

        $this->assertSame(200.0, $recorder->getSlowQueryThreshold());
        $this->assertTrue($recorder->isSlowQueriesOnly());
    }

    #[Test]
    public function typeConstantIsQuery(): void
    {
        $this->assertSame('query', QueryRecorder::TYPE);
    }
}
