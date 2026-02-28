<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Result;

use Aurora\Foundation\Result\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Result::class)]
final class ResultTest extends TestCase
{
    #[Test]
    public function ok_result_is_ok(): void
    {
        $result = Result::ok('value');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFail());
        $this->assertSame('value', $result->unwrap());
    }

    #[Test]
    public function ok_result_with_null_value(): void
    {
        $result = Result::ok();

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrap());
    }

    #[Test]
    public function fail_result_is_fail(): void
    {
        $result = Result::fail('error message');

        $this->assertTrue($result->isFail());
        $this->assertFalse($result->isOk());
        $this->assertSame('error message', $result->error());
    }

    #[Test]
    public function unwrap_on_failure_throws(): void
    {
        $result = Result::fail('something broke');

        $this->expectException(\LogicException::class);
        $result->unwrap();
    }

    #[Test]
    public function error_on_success_throws(): void
    {
        $result = Result::ok('fine');

        $this->expectException(\LogicException::class);
        $result->error();
    }

    #[Test]
    public function unwrap_or_returns_value_on_success(): void
    {
        $result = Result::ok('real');

        $this->assertSame('real', $result->unwrapOr('default'));
    }

    #[Test]
    public function unwrap_or_returns_default_on_failure(): void
    {
        $result = Result::fail('error');

        $this->assertSame('default', $result->unwrapOr('default'));
    }

    #[Test]
    public function map_transforms_success_value(): void
    {
        $result = Result::ok(5);
        $mapped = $result->map(fn (int $v) => $v * 2);

        $this->assertTrue($mapped->isOk());
        $this->assertSame(10, $mapped->unwrap());
    }

    #[Test]
    public function map_passes_through_failure(): void
    {
        $result = Result::fail('error');
        $mapped = $result->map(fn ($v) => $v * 2);

        $this->assertTrue($mapped->isFail());
        $this->assertSame('error', $mapped->error());
    }

    #[Test]
    public function map_error_transforms_failure(): void
    {
        $result = Result::fail('low');
        $mapped = $result->mapError(fn (string $e) => strtoupper($e));

        $this->assertTrue($mapped->isFail());
        $this->assertSame('LOW', $mapped->error());
    }

    #[Test]
    public function map_error_passes_through_success(): void
    {
        $result = Result::ok('fine');
        $mapped = $result->mapError(fn (string $e) => strtoupper($e));

        $this->assertTrue($mapped->isOk());
        $this->assertSame('fine', $mapped->unwrap());
    }
}
