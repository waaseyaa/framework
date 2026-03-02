<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Tests\Unit\Attribute;

use Waaseyaa\Foundation\Attribute\AsMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsMiddleware::class)]
final class AsMiddlewareTest extends TestCase
{
    #[Test]
    public function stores_pipeline_and_priority(): void
    {
        $attr = new AsMiddleware(pipeline: 'http', priority: 100);
        $this->assertSame('http', $attr->pipeline);
        $this->assertSame(100, $attr->priority);
    }

    #[Test]
    public function priority_defaults_to_zero(): void
    {
        $attr = new AsMiddleware(pipeline: 'event');
        $this->assertSame(0, $attr->priority);
    }

    #[Test]
    public function is_a_class_level_attribute(): void
    {
        $ref = new \ReflectionClass(AsMiddleware::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $instance->flags);
    }
}
