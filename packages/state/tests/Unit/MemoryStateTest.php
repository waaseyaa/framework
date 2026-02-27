<?php

declare(strict_types=1);

namespace Aurora\State\Tests\Unit;

use Aurora\State\MemoryState;
use Aurora\State\StateInterface;
use PHPUnit\Framework\TestCase;

final class MemoryStateTest extends TestCase
{
    private MemoryState $state;

    protected function setUp(): void
    {
        $this->state = new MemoryState();
    }

    public function testImplementsStateInterface(): void
    {
        $this->assertInstanceOf(StateInterface::class, $this->state);
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

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $result);
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

    public function testStoresNullValue(): void
    {
        $this->state->set('nullable', null);

        // Should return null (the stored value), NOT the default.
        $this->assertNull($this->state->get('nullable', 'default'));
        // Confirm the key exists in getMultiple.
        $result = $this->state->getMultiple(['nullable']);
        $this->assertArrayHasKey('nullable', $result);
        $this->assertNull($result['nullable']);
    }

    public function testStoresArrayValue(): void
    {
        $array = ['nested' => ['data' => [1, 2, 3]]];
        $this->state->set('array_key', $array);

        $this->assertSame($array, $this->state->get('array_key'));
    }

    public function testStoresObjectValue(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->value = 42;

        $this->state->set('object_key', $obj);

        $retrieved = $this->state->get('object_key');
        $this->assertInstanceOf(\stdClass::class, $retrieved);
        $this->assertSame('test', $retrieved->name);
        $this->assertSame(42, $retrieved->value);
    }

    public function testStoresBooleanValues(): void
    {
        $this->state->set('flag_true', true);
        $this->state->set('flag_false', false);

        $this->assertTrue($this->state->get('flag_true'));
        $this->assertFalse($this->state->get('flag_false'));
    }
}
