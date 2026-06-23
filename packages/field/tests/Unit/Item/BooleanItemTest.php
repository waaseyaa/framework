<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\BooleanItem;

/**
 * @covers \Waaseyaa\Field\Item\BooleanItem
 */
class BooleanItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'int', 'size' => 'tiny']],
            BooleanItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(['type' => 'boolean'], BooleanItem::jsonSchema());
    }
}
