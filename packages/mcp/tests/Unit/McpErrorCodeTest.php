<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\McpErrorCode;

/**
 * The MCP 2026-07-28 error-code policy, as a predicate (#2561).
 *
 * {@see \Waaseyaa\Mcp\Tests\Architecture\McpErrorCodeAllocationTest} applies
 * this predicate to the shipped source; here it is exercised directly against
 * the boundaries of every band the specification names, because an off-by-one
 * in a range test is exactly the kind of error that would silently re-open the
 * reserved sub-range.
 */
#[CoversClass(McpErrorCode::class)]
final class McpErrorCodeTest extends TestCase
{
    /** @return iterable<string, array{int, bool}> */
    public static function codes(): iterable
    {
        // Retired by name: MUST NOT be emitted regardless of range.
        yield '-32002 retired resource-not-found' => [-32002, false];
        yield '-32042 retired URL elicitation' => [-32042, false];

        // Inside -32099..-32020, reserved for the specification.
        yield '-32020 HeaderMismatch (defined)' => [-32020, true];
        yield '-32021 MissingRequiredClientCapability (defined)' => [-32021, true];
        yield '-32022 UnsupportedProtocolVersion (defined)' => [-32022, true];
        yield '-32023 undefined in the reserved sub-range' => [-32023, false];
        yield '-32029 undefined (was rate limit)' => [-32029, false];
        yield '-32030 undefined (was limiter unavailable)' => [-32030, false];
        yield '-32040 undefined (was forbidden origin)' => [-32040, false];
        yield '-32041 undefined (was accept violation)' => [-32041, false];
        yield '-32043 undefined (was body too large)' => [-32043, false];
        yield '-32099 low bound of the reserved sub-range' => [-32099, false];

        // Immediately outside that sub-range in both directions.
        yield '-32100 below the reserved sub-range' => [-32100, true];
        yield '-32019 above the reserved sub-range (legacy)' => [-32019, true];

        // JSON-RPC's own codes stay correct in every era.
        yield '-32700 parse error' => [-32700, true];
        yield '-32600 invalid request' => [-32600, true];
        yield '-32601 method not found' => [-32601, true];
        yield '-32602 invalid params' => [-32602, true];

        // The JSON-RPC reserved range's own bounds.
        yield '-32768 low bound of the JSON-RPC reserved range' => [-32768, true];
        yield '-32769 below the JSON-RPC reserved range' => [-32769, true];
        yield '-32000 high bound of the JSON-RPC reserved range' => [-32000, true];
        yield '-31999 outside the reserved range' => [-31999, true];

        // This package's own band.
        yield '-31001 audit trail unavailable' => [-31001, true];
        yield '-31043 request too large' => [-31043, true];
        yield 'a positive application code' => [1234, true];
    }

    #[Test]
    #[DataProvider('codes')]
    public function the_must_level_policy_admits_exactly_the_permitted_codes(int $code, bool $expected): void
    {
        self::assertSame($expected, McpErrorCode::isEmittable($code));
    }

    /**
     * The legacy sub-range is SHOULD NOT, so it is reported separately rather
     * than folded into the MUST-level predicate — conflating the two would
     * either understate a real violation or overstate a permitted code.
     */
    #[Test]
    public function the_legacy_sub_range_is_reported_separately_from_the_must_level_policy(): void
    {
        foreach ([-32000, -32001, -32003, -32004, -32010, -32019] as $code) {
            self::assertTrue(McpErrorCode::isLegacySubRange($code), sprintf('%d is legacy.', $code));
        }

        foreach ([-32020, -31999, -32099, -32602, 1234] as $code) {
            self::assertFalse(McpErrorCode::isLegacySubRange($code), sprintf('%d is not legacy.', $code));
        }

        // -32001 is discouraged, not forbidden: the two predicates disagree
        // about it on purpose.
        self::assertTrue(McpErrorCode::isEmittable(-32001));
        self::assertTrue(McpErrorCode::isLegacySubRange(-32001));
    }

    #[Test]
    public function every_code_this_package_allocates_satisfies_its_own_policy(): void
    {
        $allocations = [
            'FORBIDDEN_ORIGIN' => McpErrorCode::FORBIDDEN_ORIGIN,
            'UNACCEPTABLE_ACCEPT' => McpErrorCode::UNACCEPTABLE_ACCEPT,
            'UNSUPPORTED_CONTENT_TYPE' => McpErrorCode::UNSUPPORTED_CONTENT_TYPE,
            'REQUEST_TOO_LARGE' => McpErrorCode::REQUEST_TOO_LARGE,
            'RATE_LIMIT_EXCEEDED' => McpErrorCode::RATE_LIMIT_EXCEEDED,
            'RATE_LIMITER_UNAVAILABLE' => McpErrorCode::RATE_LIMITER_UNAVAILABLE,
            'AUDIT_TRAIL_UNAVAILABLE' => McpErrorCode::AUDIT_TRAIL_UNAVAILABLE,
            'APPROVAL_STORE_UNAVAILABLE' => McpErrorCode::APPROVAL_STORE_UNAVAILABLE,
        ];

        foreach ($allocations as $name => $code) {
            self::assertTrue(McpErrorCode::isEmittable($code), sprintf('%s must be emittable.', $name));
            self::assertFalse(McpErrorCode::isLegacySubRange($code), sprintf('%s must not be legacy.', $name));
            self::assertGreaterThan(
                McpErrorCode::JSON_RPC_RESERVED_HIGH,
                $code,
                sprintf('%s must sit outside the JSON-RPC reserved range.', $name),
            );
        }

        self::assertSame(
            count($allocations),
            count(array_unique($allocations)),
            'Two refusals sharing one code is the defect #2561 reported; the allocation must stay injective.',
        );
    }

    /**
     * The audit-trail and approval-store outages shared `-32002` and were
     * separable only by reading the message. They must never converge again.
     */
    #[Test]
    public function the_two_infrastructure_outages_are_distinct_codes(): void
    {
        self::assertNotSame(
            McpErrorCode::AUDIT_TRAIL_UNAVAILABLE,
            McpErrorCode::APPROVAL_STORE_UNAVAILABLE,
        );
    }
}
