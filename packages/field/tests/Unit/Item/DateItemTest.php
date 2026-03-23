<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\DateItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(DateItem::class)]
class DateItemTest extends TestCase
{
    private function createItem(array $values = []): DateItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'date',
            label: 'Date',
            class: DateItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new DateItem('date', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(['value' => 'string'], DateItem::propertyDefinitions());
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('value', DateItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 10]],
            DateItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'date'],
            DateItem::jsonSchema(),
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
        $item = $this->createItem(['value' => '2026-03-23']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['value' => '2026-03-23']);
        $this->assertSame('2026-03-23', $item->getValue());
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('2026-01-01');
        $this->assertSame('2026-01-01', $item->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem(['value' => '2026-03-23']);
        $this->assertSame(['value' => '2026-03-23'], $item->toArray());
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['value' => '2026-03-23']);
        $this->assertSame('2026-03-23', $item->getString());
    }
}
