<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Factory\EntityTypeFactory;

#[CoversClass(EntityTypeFactory::class)]
final class EntityTypeFactoryTest extends TestCase
{
    #[Test]
    public function it_builds_a_shape_only_entity_type_without_dependency_test_autoload(): void
    {
        $type = EntityTypeFactory::create(
            'article',
            keys: ['id' => 'nid', 'label' => 'title'],
        );

        self::assertSame('article', $type->id());
        self::assertSame('Article', $type->getLabel());
        self::assertSame(['id' => 'nid', 'label' => 'title'], $type->getKeys());
    }
}
