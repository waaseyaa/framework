<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Query;

use Aurora\Api\Query\ParsedQuery;
use Aurora\Api\Query\QueryFilter;
use Aurora\Api\Query\QuerySort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedQuery::class)]
final class ParsedQueryTest extends TestCase
{
    #[Test]
    public function constructsWithDefaults(): void
    {
        $query = new ParsedQuery();

        $this->assertSame([], $query->filters);
        $this->assertSame([], $query->sorts);
        $this->assertNull($query->offset);
        $this->assertNull($query->limit);
        $this->assertSame([], $query->sparseFieldsets);
    }

    #[Test]
    public function constructsWithAllParameters(): void
    {
        $filters = [new QueryFilter('status', 1)];
        $sorts = [new QuerySort('title', 'ASC')];
        $fieldsets = ['article' => ['title', 'body']];

        $query = new ParsedQuery(
            filters: $filters,
            sorts: $sorts,
            offset: 10,
            limit: 25,
            sparseFieldsets: $fieldsets,
        );

        $this->assertCount(1, $query->filters);
        $this->assertSame('status', $query->filters[0]->field);
        $this->assertCount(1, $query->sorts);
        $this->assertSame('title', $query->sorts[0]->field);
        $this->assertSame(10, $query->offset);
        $this->assertSame(25, $query->limit);
        $this->assertSame(['article' => ['title', 'body']], $query->sparseFieldsets);
    }

    #[Test]
    public function constructsWithMultipleFiltersAndSorts(): void
    {
        $filters = [
            new QueryFilter('status', 1),
            new QueryFilter('type', 'blog', '!='),
        ];
        $sorts = [
            new QuerySort('created', 'DESC'),
            new QuerySort('title', 'ASC'),
        ];

        $query = new ParsedQuery(filters: $filters, sorts: $sorts);

        $this->assertCount(2, $query->filters);
        $this->assertCount(2, $query->sorts);
        $this->assertSame('status', $query->filters[0]->field);
        $this->assertSame('type', $query->filters[1]->field);
        $this->assertSame('DESC', $query->sorts[0]->direction);
        $this->assertSame('ASC', $query->sorts[1]->direction);
    }
}
