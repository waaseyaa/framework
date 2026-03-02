<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Tests\Unit\Attribute;

use Waaseyaa\Foundation\Attribute\AsFieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsFieldType::class)]
final class AsFieldTypeTest extends TestCase
{
    #[Test]
    public function stores_id_and_label(): void
    {
        $attr = new AsFieldType(id: 'text', label: 'Text');
        $this->assertSame('text', $attr->id);
        $this->assertSame('Text', $attr->label);
    }

    #[Test]
    public function is_a_class_level_attribute(): void
    {
        $ref = new \ReflectionClass(AsFieldType::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);
    }
}
