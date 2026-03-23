<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\ListItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(ListItem::class)]
class ListItemTest extends TestCase
{
    private function createItem(array $values = []): ListItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'list',
            label: 'List (Select)',
            class: ListItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new ListItem('list', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(['value' => 'string'], ListItem::propertyDefinitions());
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('value', ListItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 255]],
            ListItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string'],
            ListItem::jsonSchema(),
        );
    }

    public function testDefaultSettings(): void
    {
        $this->assertSame(
            ['allowed_values' => []],
            ListItem::defaultSettings(),
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
        $item = $this->createItem(['value' => 'option_a']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['value' => 'published']);
        $this->assertSame('published', $item->getValue());
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('draft');
        $this->assertSame('draft', $item->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem(['value' => 'published']);
        $this->assertSame(['value' => 'published'], $item->toArray());
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['value' => 'published']);
        $this->assertSame('published', $item->getString());
    }
}
