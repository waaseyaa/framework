<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Tests\Unit\Attribute;

use Waaseyaa\Foundation\Attribute\AsEntityType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsEntityType::class)]
final class AsEntityTypeTest extends TestCase
{
    #[Test]
    public function stores_id_and_label(): void
    {
        $attr = new AsEntityType(id: 'node', label: 'Content');
        $this->assertSame('node', $attr->id);
        $this->assertSame('Content', $attr->label);
    }

    #[Test]
    public function is_a_class_level_attribute(): void
    {
        $ref = new \ReflectionClass(AsEntityType::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);
    }
}
