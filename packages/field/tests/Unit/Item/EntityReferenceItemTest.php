<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\EntityReferenceItem;

/**
 * @covers \Waaseyaa\Field\Item\EntityReferenceItem
 */
class EntityReferenceItemTest extends TestCase
{
    public function testSchema(): void
    {
        $expected = [
            'target_id' => ['type' => 'int'],
            'target_type' => ['type' => 'varchar', 'length' => 255],
        ];

        $this->assertSame($expected, EntityReferenceItem::schema());
    }

    public function testJsonSchema(): void
    {
        $expected = [
            'type' => 'object',
            'properties' => [
                'target_id' => ['type' => 'integer'],
                'target_type' => ['type' => 'string'],
            ],
        ];

        $this->assertSame($expected, EntityReferenceItem::jsonSchema());
    }
}
