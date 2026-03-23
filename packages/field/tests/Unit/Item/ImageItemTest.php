<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\ImageItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(ImageItem::class)]
class ImageItemTest extends TestCase
{
    private function createItem(array $values = []): ImageItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'image',
            label: 'Image',
            class: ImageItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new ImageItem('image', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $definitions = ImageItem::propertyDefinitions();
        $this->assertSame('string', $definitions['uri']);
        $this->assertSame('string', $definitions['filename']);
        $this->assertSame('string', $definitions['mime_type']);
        $this->assertSame('integer', $definitions['size']);
        $this->assertSame('string', $definitions['alt']);
        $this->assertSame('integer', $definitions['width']);
        $this->assertSame('integer', $definitions['height']);
        $this->assertCount(7, $definitions);
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('uri', ImageItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $schema = ImageItem::schema();
        $this->assertSame(['type' => 'varchar', 'length' => 512], $schema['uri']);
        $this->assertSame(['type' => 'varchar', 'length' => 255], $schema['filename']);
        $this->assertSame(['type' => 'varchar', 'length' => 127], $schema['mime_type']);
        $this->assertSame(['type' => 'int'], $schema['size']);
        $this->assertSame(['type' => 'varchar', 'length' => 512], $schema['alt']);
        $this->assertSame(['type' => 'int'], $schema['width']);
        $this->assertSame(['type' => 'int'], $schema['height']);
    }

    public function testJsonSchema(): void
    {
        $schema = ImageItem::jsonSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('alt', $schema['properties']);
        $this->assertArrayHasKey('width', $schema['properties']);
        $this->assertArrayHasKey('height', $schema['properties']);
        $this->assertSame(['uri'], $schema['required']);
    }

    public function testIsEmptyWithNull(): void
    {
        $item = $this->createItem();
        $this->assertTrue($item->isEmpty());
    }

    public function testIsNotEmpty(): void
    {
        $item = $this->createItem(['uri' => 'public://images/photo.jpg']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['uri' => 'public://images/photo.jpg']);
        $this->assertSame('public://images/photo.jpg', $item->getValue());
    }

    public function testSetValueWithArray(): void
    {
        $item = $this->createItem();
        $item->setValue([
            'uri' => 'public://images/photo.jpg',
            'alt' => 'A photo',
            'width' => 800,
            'height' => 600,
        ]);
        $this->assertSame('public://images/photo.jpg', $item->getValue());
        $this->assertSame('A photo', $item->get('alt')->getValue());
        $this->assertSame(800, $item->get('width')->getValue());
        $this->assertSame(600, $item->get('height')->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem([
            'uri' => 'public://images/photo.jpg',
            'filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 54321,
            'alt' => 'A photo',
            'width' => 800,
            'height' => 600,
        ]);
        $array = $item->toArray();
        $this->assertSame('public://images/photo.jpg', $array['uri']);
        $this->assertSame('A photo', $array['alt']);
        $this->assertSame(800, $array['width']);
        $this->assertSame(600, $array['height']);
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['uri' => 'public://images/photo.jpg']);
        $this->assertSame('public://images/photo.jpg', $item->getString());
    }
}
