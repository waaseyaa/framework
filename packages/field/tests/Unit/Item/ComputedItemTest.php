<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Field\ComputedFieldInterface;
use Waaseyaa\Field\Item\ComputedItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(ComputedItem::class)]
class ComputedItemTest extends TestCase
{
    private function createItem(array $values = []): ComputedItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'computed',
            label: 'Computed',
            class: ComputedItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new ComputedItem('computed', $pluginDefinition, $configuration);
    }

    public function testImplementsComputedFieldInterface(): void
    {
        $item = $this->createItem();
        $this->assertInstanceOf(ComputedFieldInterface::class, $item);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(['value' => 'string'], ComputedItem::propertyDefinitions());
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('value', ComputedItem::mainPropertyName());
    }

    public function testSchemaIsEmpty(): void
    {
        $this->assertSame([], ComputedItem::schema());
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(['type' => 'string'], ComputedItem::jsonSchema());
    }

    public function testIsEmptyWithNull(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $item = $this->createItem(['value' => 'computed result']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['value' => 'computed result']);
        $this->assertSame('computed result', $item->getValue());
    }

    public function testCompute(): void
    {
        $item = $this->createItem(['value' => 'test']);
        $entity = $this->createStub(EntityInterface::class);
        $this->assertSame('test', $item->compute($entity));
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('new value');
        $this->assertSame('new value', $item->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem(['value' => 'computed']);
        $this->assertSame(['value' => 'computed'], $item->toArray());
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['value' => 'computed']);
        $this->assertSame('computed', $item->getString());
    }
}
