<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\DecimalItem;

#[CoversClass(DecimalItem::class)]
class DecimalItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            ['value' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2]],
            DecimalItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $this->assertSame(
            ['type' => 'string', 'pattern' => '^-?\\d+(?:\\.\\d+)?$'],
            DecimalItem::jsonSchema(),
        );
    }

    public function testEntityStorageUsesLosslessText(): void
    {
        $definition = new \Waaseyaa\Field\FieldDefinition(name: 'amount', type: 'decimal');
        $this->assertSame(['type' => 'text'], DecimalItem::entityStorageColumnSchemaFor($definition));
    }

    public function testDefaultSettings(): void
    {
        $this->assertSame(
            ['precision' => 10, 'scale' => 2],
            DecimalItem::defaultSettings(),
        );
    }
}
