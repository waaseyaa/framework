<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\EmailItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(EmailItem::class)]
class EmailItemTest extends TestCase
{
    private function createItem(array $values = []): EmailItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'email',
            label: 'Email',
            class: EmailItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new EmailItem('email', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(['value' => 'string'], EmailItem::propertyDefinitions());
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('value', EmailItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 254]],
            EmailItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'email', 'maxLength' => 254],
            EmailItem::jsonSchema(),
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
        $item = $this->createItem(['value' => 'user@example.com']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['value' => 'user@example.com']);
        $this->assertSame('user@example.com', $item->getValue());
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('admin@example.org');
        $this->assertSame('admin@example.org', $item->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem(['value' => 'user@example.com']);
        $this->assertSame(['value' => 'user@example.com'], $item->toArray());
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['value' => 'user@example.com']);
        $this->assertSame('user@example.com', $item->getString());
    }
}
