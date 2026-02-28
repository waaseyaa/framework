<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Query;

use Aurora\Api\Query\QuerySort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuerySort::class)]
final class QuerySortTest extends TestCase
{
    #[Test]
    public function constructsWithDefaultAscending(): void
    {
        $sort = new QuerySort('title');

        $this->assertSame('title', $sort->field);
        $this->assertSame('ASC', $sort->direction);
    }

    #[Test]
    public function constructsWithDescending(): void
    {
        $sort = new QuerySort('created', 'DESC');

        $this->assertSame('created', $sort->field);
        $this->assertSame('DESC', $sort->direction);
    }

    #[Test]
    public function rejectsInvalidDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sort direction "RANDOM"');

        new QuerySort('field', 'RANDOM');
    }
}
