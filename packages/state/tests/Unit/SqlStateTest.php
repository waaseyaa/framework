<?php

declare(strict_types=1);

namespace Waaseyaa\State\Tests\Unit;

use Waaseyaa\Database\DBALDatabase;
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
}
