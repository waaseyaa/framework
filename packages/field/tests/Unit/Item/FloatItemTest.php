<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\FloatItem;

/**
 * @covers \Waaseyaa\Field\Item\FloatItem
 */
class FloatItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'float']],
            FloatItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(['type' => 'number'], FloatItem::jsonSchema());
    }
}
