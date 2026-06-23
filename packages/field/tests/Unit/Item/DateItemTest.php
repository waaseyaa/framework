<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\DateItem;

#[CoversClass(DateItem::class)]
class DateItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 10]],
            DateItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'date'],
            DateItem::jsonSchema(),
        );
    }
}
