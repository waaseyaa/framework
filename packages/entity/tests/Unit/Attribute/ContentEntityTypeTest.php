<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\ContentEntityType;

#[CoversClass(ContentEntityType::class)]
final class ContentEntityTypeTest extends TestCase
{
    #[Test]
    public function it_constructs_with_id_only_for_backwards_compat(): void
    {
        $attr = new ContentEntityType(id: 'foo');

        self::assertSame('foo', $attr->id);
        self::assertSame('', $attr->label);
        self::assertSame('', $attr->description);
        self::assertFalse($attr->api);
    }

    #[Test]
    public function it_exposes_label_and_description_when_provided(): void
    {
        $attr = new ContentEntityType(
            id: 'todo',
            label: 'Todo Item',
            description: 'A unit of work.',
            api: true,
        );

        self::assertSame('todo', $attr->id);
        self::assertSame('Todo Item', $attr->label);
        self::assertSame('A unit of work.', $attr->description);
        self::assertTrue($attr->api);
    }

    #[Test]
    public function attribute_targets_class_only(): void
    {
        $reflection = new \ReflectionClass(ContentEntityType::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        self::assertCount(1, $attributes);

        /** @var \Attribute $instance */
        $instance = $attributes[0]->newInstance();
        self::assertSame(\Attribute::TARGET_CLASS, $instance->flags);
    }

    #[Test]
    public function class_is_final_and_readonly(): void
    {
        $reflection = new \ReflectionClass(ContentEntityType::class);

        self::assertTrue($reflection->isFinal(), 'ContentEntityType must be final.');
        self::assertTrue($reflection->isReadOnly(), 'ContentEntityType must be readonly.');
    }

    #[Test]
    public function attribute_round_trips_via_reflection(): void
    {
        $fixture = new #[ContentEntityType(id: 'sample', label: 'Sample', description: 'A sample.')] class () {
        };

        $attributes = (new \ReflectionClass($fixture))->getAttributes(ContentEntityType::class);
        self::assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        self::assertSame('sample', $instance->id);
        self::assertSame('Sample', $instance->label);
        self::assertSame('A sample.', $instance->description);
    }
}
