<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\DateTimeItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(DateTimeItem::class)]
class DateTimeItemTest extends TestCase
{
    private function createItem(array $values = []): DateTimeItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'datetime',
            label: 'Date and Time',
            class: DateTimeItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new DateTimeItem('datetime', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(['value' => 'string'], DateTimeItem::propertyDefinitions());
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('value', DateTimeItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 32]],
            DateTimeItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            DateTimeItem::jsonSchema(),
        );
    }

    public function testIsEmptyWithNull(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->isEmpty());
    }

    public function testIsEmptyWithEmptyString(): void
    {
        $item = $this->createItem(['value' => '']);
        $this->assertTrue($item->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $item = $this->createItem(['value' => '2026-03-23T10:30:00Z']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['value' => '2026-03-23T10:30:00Z']);
        $this->assertSame('2026-03-23T10:30:00Z', $item->getValue());
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('2026-01-01T00:00:00Z');
        $this->assertSame('2026-01-01T00:00:00Z', $item->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem(['value' => '2026-03-23T10:30:00Z']);
        $this->assertSame(['value' => '2026-03-23T10:30:00Z'], $item->toArray());
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['value' => '2026-03-23T10:30:00Z']);
        $this->assertSame('2026-03-23T10:30:00Z', $item->getString());
    }
}
