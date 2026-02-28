<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Query;

use Aurora\Api\Query\QueryFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryFilter::class)]
final class QueryFilterTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $filter = new QueryFilter('status', 1);

        $this->assertSame('status', $filter->field);
        $this->assertSame(1, $filter->value);
        $this->assertSame('=', $filter->operator);
    }

    #[Test]
    public function constructsWithExplicitOperator(): void
    {
        $filter = new QueryFilter('status', 5, '>=');

        $this->assertSame('status', $filter->field);
        $this->assertSame(5, $filter->value);
        $this->assertSame('>=', $filter->operator);
    }

    #[Test]
    public function supportsAllValidOperators(): void
    {
        $operators = ['=', '!=', '>', '<', '>=', '<=', 'CONTAINS', 'STARTS_WITH'];

        foreach ($operators as $operator) {
            $filter = new QueryFilter('field', 'value', $operator);
            $this->assertSame($operator, $filter->operator);
        }
    }

    #[Test]
    public function rejectsInvalidOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid filter operator "LIKE"');

        new QueryFilter('field', 'value', 'LIKE');
    }

    #[Test]
    public function acceptsStringValue(): void
    {
        $filter = new QueryFilter('title', 'Hello');
        $this->assertSame('Hello', $filter->value);
    }

    #[Test]
    public function acceptsNullValue(): void
    {
        $filter = new QueryFilter('title', null);
        $this->assertNull($filter->value);
    }
}
