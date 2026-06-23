<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\IntegerItem;

/**
 * @covers \Waaseyaa\Field\Item\IntegerItem
 */
class IntegerItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'int']],
            IntegerItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(['type' => 'integer'], IntegerItem::jsonSchema());
    }
}
