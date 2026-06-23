<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\StringItem;

/**
 * @covers \Waaseyaa\Field\Item\StringItem
 */
class StringItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 255]],
            StringItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'maxLength' => 255],
            StringItem::jsonSchema(),
        );
    }
}
