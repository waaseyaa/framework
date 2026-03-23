<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\DecimalItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(DecimalItem::class)]
class DecimalItemTest extends TestCase
{
    private function createItem(array $values = []): DecimalItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'decimal',
            label: 'Decimal',
            class: DecimalItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new DecimalItem('decimal', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(['value' => 'string'], DecimalItem::propertyDefinitions());
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('value', DecimalItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2]],
            DecimalItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'pattern' => '^-?\\d+\\.\\d+$'],
            DecimalItem::jsonSchema(),
        );
    }

    public function testDefaultSettings(): void
    {
        $this->assertSame(
            ['precision' => 10, 'scale' => 2],
            DecimalItem::defaultSettings(),
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
        $item = $this->createItem(['value' => '99.95']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['value' => '123.45']);
        $this->assertSame('123.45', $item->getValue());
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('67.89');
        $this->assertSame('67.89', $item->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem(['value' => '123.45']);
        $this->assertSame(['value' => '123.45'], $item->toArray());
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['value' => '123.45']);
        $this->assertSame('123.45', $item->getString());
    }

    public function testNegativeValue(): void
    {
        $item = $this->createItem(['value' => '-42.50']);
        $this->assertSame('-42.50', $item->getValue());
    }
}
