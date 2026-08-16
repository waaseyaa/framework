<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\CanonicalConfigEncoder;

#[CoversClass(CanonicalConfigEncoder::class)]
final class CanonicalConfigEncoderTest extends TestCase
{
    #[Test]
    public function nested_maps_sort_bytewise_while_lists_preserve_order(): void
    {
        $encoder = new CanonicalConfigEncoder();
        $left = ['nested' => ['beta' => 2, 'alpha' => 1], 'list' => ['b', 'a']];
        $right = ['list' => ['b', 'a'], 'nested' => ['alpha' => 1, 'beta' => 2]];

        self::assertSame($encoder->encode($left), $encoder->encode($right));
        self::assertNotSame(
            $encoder->encode($left),
            $encoder->encode(['nested' => ['alpha' => 1, 'beta' => 2], 'list' => ['a', 'b']]),
        );
    }

    #[Test]
    public function schema_context_disambiguates_empty_map_and_list(): void
    {
        $encoder = new CanonicalConfigEncoder();

        self::assertSame('{}', $encoder->encode([], ['type' => 'object', 'properties' => []]));
        self::assertSame('[]', $encoder->encode([], ['type' => 'array', 'items' => ['type' => 'string']]));
    }

    #[Test]
    public function non_ascii_or_empty_map_keys_are_rejected(): void
    {
        $encoder = new CanonicalConfigEncoder();

        foreach ([['' => true], ['anishinaabemowin-é' => true]] as $value) {
            try {
                $encoder->encode($value);
                self::fail('Expected invalid canonical map key to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function floats_and_unsafe_integers_are_rejected(): void
    {
        $encoder = new CanonicalConfigEncoder();

        foreach ([1.5, 9_007_199_254_740_992] as $value) {
            try {
                $encoder->encode($value);
                self::fail('Expected non-I-JSON number to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function schema_encoding_preserves_empty_properties_as_a_map(): void
    {
        self::assertSame(
            '{"dialect":"waaseyaa.config-schema/1","properties":{},"type":"object"}',
            new CanonicalConfigEncoder()->encodeSchema([
                'type' => 'object',
                'properties' => [],
                'dialect' => 'waaseyaa.config-schema/1',
            ]),
        );
    }

    #[Test]
    public function digests_are_domain_separated(): void
    {
        $encoder = new CanonicalConfigEncoder();

        self::assertNotSame(
            $encoder->digest('waaseyaa.config-authored/1', ['enabled' => true]),
            $encoder->digest('waaseyaa.config-effective/1', ['enabled' => true]),
        );
        self::assertMatchesRegularExpression(
            '/^sha256:[0-9a-f]{64}$/',
            $encoder->digest('waaseyaa.config-authored/1', ['enabled' => true]),
        );
    }
}
