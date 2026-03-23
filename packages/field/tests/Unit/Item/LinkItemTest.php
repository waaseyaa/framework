<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\LinkItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(LinkItem::class)]
class LinkItemTest extends TestCase
{
    private function createItem(array $values = []): LinkItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'link',
            label: 'Link',
            class: LinkItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new LinkItem('link', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(
            ['uri' => 'string', 'title' => 'string'],
            LinkItem::propertyDefinitions(),
        );
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('uri', LinkItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $this->assertSame(
            [
                'uri' => ['type' => 'varchar', 'length' => 2048],
                'title' => ['type' => 'varchar', 'length' => 255],
            ],
            LinkItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $schema = LinkItem::jsonSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('uri', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertSame(['uri'], $schema['required']);
    }

    public function testIsEmptyWithNull(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->isEmpty());
    }

    public function testIsEmptyWithEmptyString(): void
    {
        $item = $this->createItem(['uri' => '']);
        $this->assertTrue($item->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $item = $this->createItem(['uri' => 'https://example.com']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['uri' => 'https://example.com']);
        $this->assertSame('https://example.com', $item->getValue());
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('https://example.org');
        $this->assertSame('https://example.org', $item->getValue());
    }

    public function testSetValueWithArray(): void
    {
        $item = $this->createItem();
        $item->setValue(['uri' => 'https://example.com', 'title' => 'Example']);
        $this->assertSame('https://example.com', $item->getValue());
        $this->assertSame('Example', $item->get('title')->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem(['uri' => 'https://example.com', 'title' => 'Example']);
        $this->assertSame(
            ['uri' => 'https://example.com', 'title' => 'Example'],
            $item->toArray(),
        );
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['uri' => 'https://example.com']);
        $this->assertSame('https://example.com', $item->getString());
    }
}
