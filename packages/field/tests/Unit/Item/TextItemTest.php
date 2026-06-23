<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\TextItem;

/**
 * @covers \Waaseyaa\Field\Item\TextItem
 */
class TextItemTest extends TestCase
{
    public function testSchema(): void
    {
        $expected = [
            'value' => ['type' => 'text'],
            'format' => ['type' => 'varchar', 'length' => 255],
        ];

        $this->assertSame($expected, TextItem::schema());
    }

    public function testJsonSchema(): void
    {
        $expected = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'format' => ['type' => 'string'],
            ],
        ];

        $this->assertSame($expected, TextItem::jsonSchema());
    }
}
