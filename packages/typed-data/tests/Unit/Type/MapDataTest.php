<?php

declare(strict_types=1);

namespace Aurora\TypedData\Tests\Unit\Type;

use Aurora\TypedData\ComplexDataInterface;
use Aurora\TypedData\DataDefinition;
use Aurora\TypedData\DataDefinitionInterface;
use Aurora\TypedData\Type\MapData;
use Aurora\TypedData\Type\StringData;
use Aurora\TypedData\TypedDataInterface;
use Aurora\TypedData\TypedDataManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapData::class)]
final class MapDataTest extends TestCase
{
    private DataDefinition $definition;
    private TypedDataManager $manager;

    protected function setUp(): void
    {
        $this->definition = new DataDefinition(dataType: 'map');
        $this->manager = new TypedDataManager();
    }

    public function testImplementsInterfaces(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $this->assertInstanceOf(ComplexDataInterface::class, $map);
        $this->assertInstanceOf(TypedDataInterface::class, $map);
        $this->assertInstanceOf(\IteratorAggregate::class, $map);
        $this->assertInstanceOf(\Traversable::class, $map);
        $this->assertInstanceOf(\Countable::class, $map);
    }

    public function testEmptyByDefault(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $this->assertSame([], $map->toArray());
        $this->assertSame([], $map->getProperties());
    }

    public function testSetAndGetProperty(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $map->set('title', 'Hello World');

        $this->assertSame('Hello World', $map->get('title')->getValue());
    }

    public function testSetReturnsStatic(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $result = $map->set('title', 'Hello');

        $this->assertSame($map, $result);
    }

    public function testSetUpdatesExistingProperty(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('title', 'Original');

        $map->set('title', 'Updated');

        $this->assertSame('Updated', $map->get('title')->getValue());
    }

    public function testSetWithTypedDataInstance(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $stringDef = new DataDefinition(dataType: 'string');
        $item = new StringData($stringDef, 'typed');

        $map->set('name', $item);

        $this->assertSame($item, $map->get('name'));
        $this->assertSame('typed', $map->get('name')->getValue());
    }

    public function testGetNonExistentPropertyThrows(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Property "missing" does not exist.');
        $map->get('missing');
    }

    public function testGetProperties(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('title', 'Hello');
        $map->set('count', 42);

        $properties = $map->getProperties();

        $this->assertCount(2, $properties);
        $this->assertArrayHasKey('title', $properties);
        $this->assertArrayHasKey('count', $properties);
        $this->assertInstanceOf(DataDefinitionInterface::class, $properties['title']);
        $this->assertInstanceOf(DataDefinitionInterface::class, $properties['count']);
    }

    public function testToArray(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('title', 'Hello');
        $map->set('count', 42);
        $map->set('active', true);

        $array = $map->toArray();

        $this->assertSame([
            'title' => 'Hello',
            'count' => 42,
            'active' => true,
        ], $array);
    }

    public function testGetValue(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('key', 'value');

        // getValue() returns the same as toArray().
        $this->assertSame(['key' => 'value'], $map->getValue());
    }

    public function testSetValueFromArray(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $map->setValue(['name' => 'Alice', 'age' => 30]);

        $this->assertSame('Alice', $map->get('name')->getValue());
        $this->assertSame(30, $map->get('age')->getValue());
    }

    public function testSetValueIgnoresNonArray(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('existing', 'value');

        // Non-array should be ignored.
        $map->setValue('not an array');

        $this->assertSame('value', $map->get('existing')->getValue());
    }

    public function testGetString(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('name', 'Alice');
        $map->set('age', 30);

        $string = $map->getString();

        $this->assertSame('name: Alice, age: 30', $string);
    }

    public function testGetStringEmpty(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $this->assertSame('', $map->getString());
    }

    public function testGetDataDefinition(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $this->assertSame($this->definition, $map->getDataDefinition());
    }

    public function testIterable(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('a', 'one');
        $map->set('b', 'two');

        $values = [];
        foreach ($map as $name => $item) {
            $values[$name] = $item->getValue();
        }

        $this->assertSame(['a' => 'one', 'b' => 'two'], $values);
    }

    public function testValidateAggregatesPropertyViolations(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('name', 'valid');

        $violations = $map->validate();

        $this->assertCount(0, $violations);
    }

    public function testAutoDetectsIntegerType(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('count', 42);

        $properties = $map->getProperties();
        $this->assertSame('integer', $properties['count']->getDataType());
    }

    public function testAutoDetectsFloatType(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('price', 9.99);

        $properties = $map->getProperties();
        $this->assertSame('float', $properties['price']->getDataType());
    }

    public function testAutoDetectsBooleanType(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('active', true);

        $properties = $map->getProperties();
        $this->assertSame('boolean', $properties['active']->getDataType());
    }

    public function testAutoDetectsStringType(): void
    {
        $map = new MapData($this->definition, $this->manager);
        $map->set('name', 'Alice');

        $properties = $map->getProperties();
        $this->assertSame('string', $properties['name']->getDataType());
    }

    public function testChainedSet(): void
    {
        $map = new MapData($this->definition, $this->manager);

        $map->set('a', 1)->set('b', 2)->set('c', 3);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $map->toArray());
    }
}
