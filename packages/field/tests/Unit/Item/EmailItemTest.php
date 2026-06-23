<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\EmailItem;

#[CoversClass(EmailItem::class)]
class EmailItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'varchar', 'length' => 254]],
            EmailItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'email', 'maxLength' => 254],
            EmailItem::jsonSchema(),
        );
    }
}
