<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\ListItem;

#[CoversClass(ListItem::class)]
class ListItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 255]],
            ListItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string'],
            ListItem::jsonSchema(),
        );
    }

    public function testDefaultSettings(): void
    {
        $this->assertSame(
            ['allowed_values' => []],
            ListItem::defaultSettings(),
        );
    }
}
