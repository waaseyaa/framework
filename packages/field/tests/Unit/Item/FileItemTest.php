<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\FileItem;

#[CoversClass(FileItem::class)]
class FileItemTest extends TestCase
{
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
}
