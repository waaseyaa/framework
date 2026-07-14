<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Unit\Plugin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Migration\Plugin\ReservedPluginIds;

#[CoversClass(ReservedPluginIds::class)]
final class ReservedPluginIdsTest extends TestCase
{
    #[Test]
    public function exposes_reserved_ids_in_canonical_order(): void
    {
        self::assertSame([
            'pass_through',
            'html_sanitize',
            'lookup',
            'concat',
            'type_coerce',
            'default_value',
            'partitioned_lookup',
        ], ReservedPluginIds::ALL);
    }

    #[Test]
    public function each_constant_matches_its_string_value(): void
    {
        self::assertSame('pass_through', ReservedPluginIds::PASS_THROUGH);
        self::assertSame('html_sanitize', ReservedPluginIds::HTML_SANITIZE);
        self::assertSame('lookup', ReservedPluginIds::LOOKUP);
        self::assertSame('concat', ReservedPluginIds::CONCAT);
        self::assertSame('type_coerce', ReservedPluginIds::TYPE_COERCE);
        self::assertSame('default_value', ReservedPluginIds::DEFAULT_VALUE);
        self::assertSame('partitioned_lookup', ReservedPluginIds::PARTITIONED_LOOKUP);
    }

    #[Test]
    public function is_reserved_recognises_every_canonical_id(): void
    {
        foreach (ReservedPluginIds::ALL as $id) {
            self::assertTrue(
                ReservedPluginIds::isReserved($id),
                \sprintf('Expected %s to be reported as reserved.', var_export($id, true)),
            );
        }
    }

    #[Test]
    public function is_reserved_returns_false_for_arbitrary_ids(): void
    {
        self::assertFalse(ReservedPluginIds::isReserved('my_custom_plugin'));
        self::assertFalse(ReservedPluginIds::isReserved(''));
        self::assertFalse(ReservedPluginIds::isReserved('Pass_Through'));
    }
}
