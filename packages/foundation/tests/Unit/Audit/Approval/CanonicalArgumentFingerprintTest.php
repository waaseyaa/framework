<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Audit\Approval;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Audit\Approval\CanonicalArgumentFingerprint;

#[CoversClass(CanonicalArgumentFingerprint::class)]
final class CanonicalArgumentFingerprintTest extends TestCase
{
    #[Test]
    public function produces_a_lowercase_sha256_hex_digest(): void
    {
        $fingerprint = CanonicalArgumentFingerprint::compute('node_update', ['title' => 'Hello']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
    }

    #[Test]
    public function wire_fingerprint_has_a_stable_known_answer(): void
    {
        self::assertSame(
            '2edaa3e9f959685c2f18292c2daf2b7c6ce60d8f5010ccb8c9f27a68efd8d412',
            CanonicalArgumentFingerprint::compute(
                'node_update',
                ['name' => 'café', 'url' => 'https://example.test/a'],
            ),
        );
    }

    #[Test]
    public function map_key_order_does_not_change_the_fingerprint(): void
    {
        self::assertSame(
            CanonicalArgumentFingerprint::compute('node_update', ['b' => 1, 'a' => 2]),
            CanonicalArgumentFingerprint::compute('node_update', ['a' => 2, 'b' => 1]),
        );
    }

    #[Test]
    public function nested_map_keys_are_sorted_recursively(): void
    {
        self::assertSame(
            CanonicalArgumentFingerprint::compute('node_update', [
                'outer' => ['z' => ['b' => 1, 'a' => 2], 'y' => true],
            ]),
            CanonicalArgumentFingerprint::compute('node_update', [
                'outer' => ['y' => true, 'z' => ['a' => 2, 'b' => 1]],
            ]),
        );
    }

    #[Test]
    public function list_order_is_significant(): void
    {
        self::assertNotSame(
            CanonicalArgumentFingerprint::compute('node_update', ['tags' => [1, 2]]),
            CanonicalArgumentFingerprint::compute('node_update', ['tags' => [2, 1]]),
        );
    }

    #[Test]
    public function lists_nested_inside_reordered_maps_keep_their_order(): void
    {
        self::assertSame(
            CanonicalArgumentFingerprint::compute('node_update', ['b' => ['x', 'y'], 'a' => 1]),
            CanonicalArgumentFingerprint::compute('node_update', ['a' => 1, 'b' => ['x', 'y']]),
        );
        self::assertNotSame(
            CanonicalArgumentFingerprint::compute('node_update', ['a' => 1, 'b' => ['x', 'y']]),
            CanonicalArgumentFingerprint::compute('node_update', ['a' => 1, 'b' => ['y', 'x']]),
        );
    }

    #[Test]
    public function value_changes_change_the_fingerprint(): void
    {
        self::assertNotSame(
            CanonicalArgumentFingerprint::compute('node_update', ['title' => 'Hello']),
            CanonicalArgumentFingerprint::compute('node_update', ['title' => 'Hellp']),
        );
    }

    #[Test]
    public function the_tool_name_domain_separates_identical_arguments(): void
    {
        self::assertNotSame(
            CanonicalArgumentFingerprint::compute('node_update', ['id' => 7]),
            CanonicalArgumentFingerprint::compute('node_delete', ['id' => 7]),
        );
    }

    #[Test]
    public function unicode_arguments_hash_identically_regardless_of_source_escaping(): void
    {
        /** @var array<string, mixed> $fromEscapedJson */
        $fromEscapedJson = json_decode('{"name":"café","url":"https:\/\/example.test\/a"}', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            CanonicalArgumentFingerprint::compute('node_update', ['name' => 'café', 'url' => 'https://example.test/a']),
            CanonicalArgumentFingerprint::compute('node_update', $fromEscapedJson),
        );
        self::assertNotSame(
            CanonicalArgumentFingerprint::compute('node_update', ['name' => 'café']),
            CanonicalArgumentFingerprint::compute('node_update', ['name' => 'cafe']),
        );
    }

    #[Test]
    public function empty_arguments_are_a_valid_distinct_input(): void
    {
        $empty = CanonicalArgumentFingerprint::compute('node_update', []);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $empty);
        self::assertNotSame($empty, CanonicalArgumentFingerprint::compute('node_update', ['a' => null]));
    }

    #[Test]
    public function unencodable_arguments_throw_rather_than_silently_degrading(): void
    {
        $this->expectException(\JsonException::class);

        CanonicalArgumentFingerprint::compute('node_update', ['bad' => NAN]);
    }

    #[Test]
    public function an_empty_tool_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalArgumentFingerprint::compute('', ['a' => 1]);
    }
}
