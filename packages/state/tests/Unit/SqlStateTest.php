<?php

declare(strict_types=1);

namespace Waaseyaa\State\Tests\Unit;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\State\SqlState;
use Waaseyaa\State\StateInterface;
use PHPUnit\Framework\TestCase;

final class SqlStateTest extends TestCase
{
    private DBALDatabase $database;
    private SqlState $state;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:');
        $this->state = new SqlState($this->database);
    }

    public function testImplementsStateInterface(): void
    {
        $this->assertInstanceOf(StateInterface::class, $this->state);
    }

    public function testEnsureTableCreatesTable(): void
    {
        $this->assertFalse($this->database->schema()->tableExists('state'));

        $this->state->ensureTable();

        $this->assertTrue($this->database->schema()->tableExists('state'));
    }

    public function testEnsureTableIsIdempotent(): void
    {
        $this->state->ensureTable();
        // Calling again should not throw.
        $this->state->ensureTable();

        $this->assertTrue($this->database->schema()->tableExists('state'));
    }

    public function testGetReturnsDefaultWhenKeyDoesNotExist(): void
    {
        $this->assertNull($this->state->get('nonexistent'));
        $this->assertSame('fallback', $this->state->get('nonexistent', 'fallback'));
    }

    public function testSetAndGet(): void
    {
        $this->state->set('cron_last_run', 1700000000);

        $this->assertSame(1700000000, $this->state->get('cron_last_run'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->state->set('key', 'original');
        $this->state->set('key', 'updated');

        $this->assertSame('updated', $this->state->get('key'));
    }

    public function testGetMultiple(): void
    {
        $this->state->set('a', 1);
        $this->state->set('b', 2);
        $this->state->set('c', 3);

        $result = $this->state->getMultiple(['a', 'b', 'missing', 'c']);

        $this->assertSame(1, $result['a']);
        $this->assertSame(2, $result['b']);
        $this->assertSame(3, $result['c']);
        $this->assertArrayNotHasKey('missing', $result);
    }

    public function testGetMultipleReturnsEmptyArrayForNoMatches(): void
    {
        $result = $this->state->getMultiple(['x', 'y', 'z']);

        $this->assertSame([], $result);
    }

    public function testSetMultiple(): void
    {
        $this->state->setMultiple([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ]);

        $this->assertSame('value1', $this->state->get('key1'));
        $this->assertSame('value2', $this->state->get('key2'));
        $this->assertSame('value3', $this->state->get('key3'));
    }

    public function testDelete(): void
    {
        $this->state->set('to_delete', 'data');
        $this->assertSame('data', $this->state->get('to_delete'));

        $this->state->delete('to_delete');

        $this->assertNull($this->state->get('to_delete'));
    }

    public function testDeleteNonexistentKeyDoesNotError(): void
    {
        // Should not throw.
        $this->state->delete('nonexistent');
        $this->assertNull($this->state->get('nonexistent'));
    }

    public function testDeleteMultiple(): void
    {
        $this->state->set('a', 1);
        $this->state->set('b', 2);
        $this->state->set('c', 3);

        $this->state->deleteMultiple(['a', 'c']);

        $this->assertNull($this->state->get('a'));
        $this->assertSame(2, $this->state->get('b'));
        $this->assertNull($this->state->get('c'));
    }

    public function testSerializationOfArray(): void
    {
        $array = ['nested' => ['data' => [1, 2, 3]], 'key' => 'value'];
        $this->state->set('array_key', $array);

        // Clear the in-memory cache by creating a fresh SqlState on the same database.
        $freshState = new SqlState($this->database);
        $result = $freshState->get('array_key');

        $this->assertSame($array, $result);
    }

    public function testSerializationOfObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->value = 42;

        $this->state->set('object_key', $obj);

        // Clear the in-memory cache by creating a fresh SqlState on the same database.
        $freshState = new SqlState($this->database);
        $retrieved = $freshState->get('object_key');

        $this->assertInstanceOf(\stdClass::class, $retrieved);
        $this->assertSame('test', $retrieved->name);
        $this->assertSame(42, $retrieved->value);
    }

    public function testSerializationOfBooleans(): void
    {
        $this->state->set('flag_true', true);
        $this->state->set('flag_false', false);

        // Fresh state to bypass cache.
        $freshState = new SqlState($this->database);

        $this->assertTrue($freshState->get('flag_true'));
        $this->assertFalse($freshState->get('flag_false'));
    }

    public function testSerializationOfNull(): void
    {
        $this->state->set('nullable', null);

        // Fresh state to bypass cache.
        $freshState = new SqlState($this->database);

        // Should return null (the stored value), NOT the default.
        $this->assertNull($freshState->get('nullable', 'default'));
    }

    public function testCachingPreventsRedundantQueries(): void
    {
        $this->state->set('cached_key', 'cached_value');

        // First get populates the cache (value was already cached from set).
        $value1 = $this->state->get('cached_key');

        // Drop the table entirely to prove second read comes from cache.
        $this->database->schema()->dropTable('state');

        // This should still work because of the in-memory cache.
        $value2 = $this->state->get('cached_key');

        $this->assertSame('cached_value', $value1);
        $this->assertSame('cached_value', $value2);
    }

    public function testDeleteClearsCache(): void
    {
        $this->state->set('key', 'value');
        $this->assertSame('value', $this->state->get('key'));

        $this->state->delete('key');

        // After delete, get should return default, not cached value.
        $this->assertNull($this->state->get('key'));
    }

    public function testGetMultipleUsesCacheForKnownKeys(): void
    {
        $this->state->set('x', 10);
        $this->state->set('y', 20);

        // Values are now cached. Get multiple for a mix of cached and uncached.
        $result = $this->state->getMultiple(['x', 'y', 'z']);

        $this->assertSame(10, $result['x']);
        $this->assertSame(20, $result['y']);
        $this->assertArrayNotHasKey('z', $result);
    }

    public function testTableAutoCreatedOnFirstOperation(): void
    {
        $this->assertFalse($this->database->schema()->tableExists('state'));

        // Any operation should auto-create the table.
        $this->state->set('auto', 'created');

        $this->assertTrue($this->database->schema()->tableExists('state'));
        $this->assertSame('created', $this->state->get('auto'));
    }

    public function testStoresIntegerValue(): void
    {
        $this->state->set('counter', 42);

        $freshState = new SqlState($this->database);
        $this->assertSame(42, $freshState->get('counter'));
    }

    public function testStoresFloatValue(): void
    {
        $this->state->set('pi', 3.14159);

        $freshState = new SqlState($this->database);
        $this->assertSame(3.14159, $freshState->get('pi'));
    }

    public function testStoresStringValue(): void
    {
        $this->state->set('greeting', 'Hello, World!');

        $freshState = new SqlState($this->database);
        $this->assertSame('Hello, World!', $freshState->get('greeting'));
    }

    /**
     * Regression test for the concurrent new-key PK-collision race (state m2).
     *
     * SqlState::set() does UPDATE-then-INSERT. When two concurrent writers both
     * see 0 rows affected by the UPDATE (the key is new), both attempt INSERT;
     * the second INSERT hits the PRIMARY KEY on `name` and throws.
     *
     * The fix catches UniqueConstraintViolationException from the INSERT and
     * re-applies the value via a second UPDATE (last-writer-wins). This test
     * pins that: a spy DatabaseInterface simulates the race by intercepting
     * the first update('state') call, inserting the competitor row out-of-band
     * (the "concurrent winner"), and returning 0 — exactly the condition that
     * triggers the collision on the subsequent INSERT. Pre-fix: throws. Post-fix:
     * succeeds with last-writer-wins semantics.
     */
    public function testSetDoesNotThrowOnConcurrentNewKeyRace(): void
    {
        $key = 'concurrent_key';
        $realDb = DBALDatabase::createSqlite(':memory:');
        $racingDb = $this->buildRaceSimulatingDatabase($realDb, $key);

        $state = new SqlState($racingDb);
        $state->ensureTable();

        // Pre-fix: throws Doctrine\DBAL\Exception\UniqueConstraintViolationException.
        // Post-fix: catches it and re-UPDATEs, so this completes without throwing.
        $state->set($key, 'my_value');

        // Last-writer-wins: our re-UPDATE overwrote the competitor's serialized value.
        $freshState = new SqlState($realDb);
        $this->assertSame('my_value', $freshState->get($key));
    }

    /**
     * Returns a DatabaseInterface spy that, on the FIRST update('state') call,
     * inserts the target key out-of-band (simulating a concurrent winner) and
     * then returns 0 — so the caller's subsequent INSERT hits the PK constraint.
     * All subsequent calls, and all other methods, delegate to the real database.
     */
    private function buildRaceSimulatingDatabase(DBALDatabase $inner, string $key): DatabaseInterface
    {
        return new class ($inner, $key) implements DatabaseInterface {
            /** @var bool Whether the race interception has already fired. */
            private bool $armed = true;

            public function __construct(
                private readonly DBALDatabase $inner,
                private readonly string $key,
            ) {}

            public function update(string $table): UpdateInterface
            {
                if (!$this->armed || $table !== 'state') {
                    return $this->inner->update($table);
                }
                $this->armed = false;

                // Return a fake UpdateInterface: inserts the competitor row
                // (concurrent winner) and returns 0 so the caller proceeds to INSERT.
                return new class ($this->inner, $this->key) implements UpdateInterface {
                    public function __construct(
                        private readonly DatabaseInterface $db,
                        private readonly string $key,
                    ) {}

                    public function fields(array $fields): static
                    {
                        return $this;
                    }

                    public function condition(string $field, mixed $value, string $operator = '='): static
                    {
                        return $this;
                    }

                    public function execute(): int
                    {
                        // Concurrent winner inserts the row between our UPDATE and INSERT.
                        $this->db->insert('state')
                            ->fields(['name', 'value'])
                            ->values(['name' => $this->key, 'value' => serialize('competitor_value')])
                            ->execute();

                        // Return 0: the key did not exist when our UPDATE ran.
                        return 0;
                    }
                };
            }

            public function insert(string $table): InsertInterface
            {
                return $this->inner->insert($table);
            }

            public function select(string $table, string $alias = ''): SelectInterface
            {
                return $this->inner->select($table, $alias);
            }

            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }

            public function schema(): SchemaInterface
            {
                return $this->inner->schema();
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                return $this->inner->transaction($name);
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
        };
    }
}
