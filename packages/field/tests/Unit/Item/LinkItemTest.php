<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Item\LinkItem;

#[CoversClass(LinkItem::class)]
class LinkItemTest extends TestCase
{
    public function testSchema(): void
    {
        $this->assertSame(
            [
                'uri' => ['type' => 'varchar', 'length' => 2048],
                'title' => ['type' => 'varchar', 'length' => 255],
            ],
            LinkItem::schema(),
        );
    }

    public function testJsonSchema(): void
    {
        $schema = LinkItem::jsonSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('uri', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertSame(['uri'], $schema['required']);
    }
}
