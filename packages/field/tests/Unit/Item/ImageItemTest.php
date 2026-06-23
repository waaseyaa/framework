<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\ImageItem;

#[CoversClass(ImageItem::class)]
class ImageItemTest extends TestCase
{
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
}
