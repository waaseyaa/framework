<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Audit\Approval;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\CanonicalArgumentFingerprint;

#[CoversClass(ApprovalTuple::class)]
final class ApprovalTupleTest extends TestCase
{
    private const string FINGERPRINT = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    #[Test]
    public function for_call_computes_the_canonical_argument_fingerprint(): void
    {
        $tuple = ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_update', ['id' => 7]);

        self::assertSame('token:ab12', $tuple->principalKey);
        self::assertSame('mcp.write', $tuple->surface);
        self::assertSame('node_update', $tuple->operation);
        self::assertSame(
            CanonicalArgumentFingerprint::compute('node_update', ['id' => 7]),
            $tuple->argumentsFingerprint,
        );
    }

    #[Test]
    public function the_request_key_is_deterministic_for_an_identical_tuple(): void
    {
        $a = new ApprovalTuple('token:ab12', 'mcp.write', 'node_update', self::FINGERPRINT);
        $b = new ApprovalTuple('token:ab12', 'mcp.write', 'node_update', self::FINGERPRINT);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->requestKey);
        self::assertSame($a->requestKey, $b->requestKey);
    }

    #[Test]
    public function every_tuple_component_participates_in_the_request_key(): void
    {
        $base = new ApprovalTuple('token:ab12', 'mcp.write', 'node_update', self::FINGERPRINT);
        $otherFingerprint = str_repeat('f', 64);

        $variants = [
            new ApprovalTuple('token:cd34', 'mcp.write', 'node_update', self::FINGERPRINT),
            new ApprovalTuple('token:ab12', 'mcp.other', 'node_update', self::FINGERPRINT),
            new ApprovalTuple('token:ab12', 'mcp.write', 'node_delete', self::FINGERPRINT),
            new ApprovalTuple('token:ab12', 'mcp.write', 'node_update', $otherFingerprint),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($base->requestKey, $variant->requestKey);
        }
    }

    #[Test]
    public function shifting_content_between_adjacent_components_changes_the_request_key(): void
    {
        // A separator-based derivation would collide here; the key must be
        // unambiguous about which component each byte belongs to.
        $a = new ApprovalTuple('ab', 'c.d', 'node_update', self::FINGERPRINT);
        $b = new ApprovalTuple('a', 'bc.d', 'node_update', self::FINGERPRINT);

        self::assertNotSame($a->requestKey, $b->requestKey);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidComponents(): iterable
    {
        yield 'empty principal key' => ['', 'mcp.write', 'node_update', self::FINGERPRINT];
        yield 'empty surface' => ['token:ab12', '', 'node_update', self::FINGERPRINT];
        yield 'empty operation' => ['token:ab12', 'mcp.write', '', self::FINGERPRINT];
        yield 'empty fingerprint' => ['token:ab12', 'mcp.write', 'node_update', ''];
        yield 'short fingerprint' => ['token:ab12', 'mcp.write', 'node_update', 'abc123'];
        yield 'uppercase fingerprint' => ['token:ab12', 'mcp.write', 'node_update', strtoupper(self::FINGERPRINT)];
        yield 'non-hex fingerprint' => ['token:ab12', 'mcp.write', 'node_update', str_repeat('g', 64)];
    }

    #[Test]
    #[DataProvider('invalidComponents')]
    public function invalid_components_are_rejected(
        string $principalKey,
        string $surface,
        string $operation,
        string $fingerprint,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalTuple($principalKey, $surface, $operation, $fingerprint);
    }
}
