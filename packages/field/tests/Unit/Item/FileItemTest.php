<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\FileItem;
use Waaseyaa\Plugin\Definition\PluginDefinition;

#[CoversClass(FileItem::class)]
class FileItemTest extends TestCase
{
    private function createItem(array $values = []): FileItem
    {
        $pluginDefinition = new PluginDefinition(
            id: 'file',
            label: 'File',
            class: FileItem::class,
        );

        $configuration = [];
        if ($values !== []) {
            $configuration['values'] = $values;
        }

        return new FileItem('file', $pluginDefinition, $configuration);
    }

    public function testPropertyDefinitions(): void
    {
        $this->assertSame(
            [
                'uri' => 'string',
                'filename' => 'string',
                'mime_type' => 'string',
                'size' => 'integer',
            ],
            FileItem::propertyDefinitions(),
        );
    }

    public function testMainPropertyName(): void
    {
        $this->assertSame('uri', FileItem::mainPropertyName());
    }

    public function testSchema(): void
    {
        $this->assertSame(
            [
                'uri' => ['type' => 'varchar', 'length' => 512],
                'filename' => ['type' => 'varchar', 'length' => 255],
                'mime_type' => ['type' => 'varchar', 'length' => 127],
                'size' => ['type' => 'int'],
            ],
            FileItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $schema = FileItem::jsonSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('uri', $schema['properties']);
        $this->assertArrayHasKey('filename', $schema['properties']);
        $this->assertArrayHasKey('mime_type', $schema['properties']);
        $this->assertArrayHasKey('size', $schema['properties']);
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
        $item = $this->createItem(['uri' => 'public://documents/report.pdf']);
        $this->assertFalse($item->isEmpty());
    }

    public function testGetValue(): void
    {
        $item = $this->createItem(['uri' => 'public://documents/report.pdf']);
        $this->assertSame('public://documents/report.pdf', $item->getValue());
    }

    public function testSetValue(): void
    {
        $item = $this->createItem();
        $item->setValue('public://files/new.txt');
        $this->assertSame('public://files/new.txt', $item->getValue());
    }

    public function testSetValueWithArray(): void
    {
        $item = $this->createItem();
        $item->setValue([
            'uri' => 'public://docs/file.pdf',
            'filename' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'size' => 12345,
        ]);
        $this->assertSame('public://docs/file.pdf', $item->getValue());
        $this->assertSame('file.pdf', $item->get('filename')->getValue());
        $this->assertSame('application/pdf', $item->get('mime_type')->getValue());
        $this->assertSame(12345, $item->get('size')->getValue());
    }

    public function testToArray(): void
    {
        $item = $this->createItem([
            'uri' => 'public://docs/file.pdf',
            'filename' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'size' => 12345,
        ]);
        $this->assertSame(
            [
                'uri' => 'public://docs/file.pdf',
                'filename' => 'file.pdf',
                'mime_type' => 'application/pdf',
                'size' => 12345,
            ],
            $item->toArray(),
        );
    }

    public function testGetString(): void
    {
        $item = $this->createItem(['uri' => 'public://documents/report.pdf']);
        $this->assertSame('public://documents/report.pdf', $item->getString());
    }
}
