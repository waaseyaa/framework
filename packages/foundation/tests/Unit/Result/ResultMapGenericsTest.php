<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Result\Result;

/**
 * Regression coverage for D-28: map()/mapError() must declare their own
 * @template (U for map, F for mapError) so the monad preserves transformed-type
 * safety, and the value-propagation semantics across a chain must be unchanged
 * by the passthrough-rebuild used to make the generics resolve.
 */
#[CoversClass(Result::class)]
final class ResultMapGenericsTest extends TestCase
{
    #[Test]
    public function chained_map_carries_transformed_value_through(): void
    {
        $result = Result::ok(5)
            ->map(fn (int $v): int => $v * 2)
            ->map(fn (int $v): string => "value:{$v}");

        self::assertTrue($result->isOk());
        self::assertSame('value:10', $result->unwrap());
    }

    #[Test]
    public function map_failure_passthrough_preserves_error_across_chain(): void
    {
        $result = Result::fail('boom')
            ->map(fn (int $v): int => $v * 2)
            ->map(fn (int $v): int => $v + 1);

        self::assertTrue($result->isFail());
        self::assertSame('boom', $result->error());
    }

    #[Test]
    public function map_error_failure_passthrough_does_not_mutate_value_identity(): void
    {
        $error = new \RuntimeException('original');
        $result = Result::fail($error)->mapError(static fn (\RuntimeException $e): string => $e->getMessage());

        self::assertTrue($result->isFail());
        self::assertSame('original', $result->error());
    }

    #[Test]
    public function map_error_success_passthrough_preserves_value_across_chain(): void
    {
        $result = Result::ok('intact')
            ->mapError(fn (string $e): string => strtoupper($e))
            ->mapError(fn (string $e): string => $e . '!');

        self::assertTrue($result->isOk());
        self::assertSame('intact', $result->unwrap());
    }

    #[Test]
    public function map_returns_a_distinct_instance_on_passthrough(): void
    {
        $original = Result::fail('e');
        $mapped = $original->map(fn (int $v): int => $v);

        // Rebuild-on-passthrough means a fresh instance, but identical observable state.
        self::assertNotSame($original, $mapped);
        self::assertSame('e', $mapped->error());
    }
}
