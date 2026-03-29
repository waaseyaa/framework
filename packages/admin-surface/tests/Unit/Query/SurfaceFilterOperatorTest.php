<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;

#[CoversClass(SurfaceFilterOperator::class)]
final class SurfaceFilterOperatorTest extends TestCase
{
    #[Test]
    public function from_string_returns_operator_for_valid_name(): void
    {
        $this->assertSame(SurfaceFilterOperator::EQUALS, SurfaceFilterOperator::fromString('EQUALS'));
        $this->assertSame(SurfaceFilterOperator::IN, SurfaceFilterOperator::fromString('IN'));
        $this->assertSame(SurfaceFilterOperator::CONTAINS, SurfaceFilterOperator::fromString('CONTAINS'));
    }

    #[Test]
    public function from_string_is_case_insensitive(): void
    {
        $this->assertSame(SurfaceFilterOperator::EQUALS, SurfaceFilterOperator::fromString('equals'));
        $this->assertSame(SurfaceFilterOperator::NOT_EQUALS, SurfaceFilterOperator::fromString('not_equals'));
    }

    #[Test]
    public function from_string_returns_null_for_unknown(): void
    {
        $this->assertNull(SurfaceFilterOperator::fromString('LIKE'));
        $this->assertNull(SurfaceFilterOperator::fromString(''));
    }

    #[Test]
    public function to_sql_operator_returns_correct_sql(): void
    {
        $this->assertSame('=', SurfaceFilterOperator::EQUALS->toSqlOperator());
        $this->assertSame('!=', SurfaceFilterOperator::NOT_EQUALS->toSqlOperator());
        $this->assertSame('IN', SurfaceFilterOperator::IN->toSqlOperator());
        $this->assertSame('LIKE', SurfaceFilterOperator::CONTAINS->toSqlOperator());
        $this->assertSame('>', SurfaceFilterOperator::GT->toSqlOperator());
        $this->assertSame('<', SurfaceFilterOperator::LT->toSqlOperator());
        $this->assertSame('>=', SurfaceFilterOperator::GTE->toSqlOperator());
        $this->assertSame('<=', SurfaceFilterOperator::LTE->toSqlOperator());
    }
}
