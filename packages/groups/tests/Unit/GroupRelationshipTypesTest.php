<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Groups\GroupRelationshipTypes;

/**
 * Pins the `relationship_type` string constants as public API — the
 * workflows package (CW-v1 WP-3 T3) and any future consumer reads these
 * literal values from config/queries, so an accidental rename here is a
 * breaking change.
 */
#[CoversClass(GroupRelationshipTypes::class)]
final class GroupRelationshipTypesTest extends TestCase
{
    #[Test]
    public function membership_constant_pins_the_directed_edge_type(): void
    {
        self::assertSame('group_membership', GroupRelationshipTypes::MEMBERSHIP);
    }

    #[Test]
    public function content_constant_pins_the_directed_edge_type(): void
    {
        self::assertSame('group_content', GroupRelationshipTypes::CONTENT);
    }
}
