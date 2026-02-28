<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Query;

use Aurora\Api\Query\QueryParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryParser::class)]
final class QueryParserTest extends TestCase
{
    private QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryParser();
    }

    // --- Filters ---

    #[Test]
    public function parsesSimpleEqualityFilter(): void
    {
        $query = ['filter' => ['status' => '1']];
        $parsed = $this->parser->parse($query);

        $this->assertCount(1, $parsed->filters);
        $this->assertSame('status', $parsed->filters[0]->field);
        $this->assertSame('1', $parsed->filters[0]->value);
        $this->assertSame('=', $parsed->filters[0]->operator);
    }

    #[Test]
    public function parsesOperatorFilter(): void
    {
        $query = [
            'filter' => [
                'status' => [
                    'operator' => '>=',
                    'value' => '1',
                ],
            ],
        ];
        $parsed = $this->parser->parse($query);

        $this->assertCount(1, $parsed->filters);
        $this->assertSame('status', $parsed->filters[0]->field);
        $this->assertSame('1', $parsed->filters[0]->value);
        $this->assertSame('>=', $parsed->filters[0]->operator);
    }

    #[Test]
    public function parsesOperatorFilterWithDefaultOperator(): void
    {
        $query = [
            'filter' => [
                'title' => [
                    'value' => 'Hello',
                ],
            ],
        ];
        $parsed = $this->parser->parse($query);

        $this->assertCount(1, $parsed->filters);
        $this->assertSame('title', $parsed->filters[0]->field);
        $this->assertSame('Hello', $parsed->filters[0]->value);
        $this->assertSame('=', $parsed->filters[0]->operator);
    }

    #[Test]
    public function parsesMultipleFilters(): void
    {
        $query = [
            'filter' => [
                'status' => '1',
                'type' => 'blog',
            ],
        ];
        $parsed = $this->parser->parse($query);

        $this->assertCount(2, $parsed->filters);
        $this->assertSame('status', $parsed->filters[0]->field);
        $this->assertSame('type', $parsed->filters[1]->field);
    }

    #[Test]
    public function returnsNoFiltersWhenFilterKeyMissing(): void
    {
        $parsed = $this->parser->parse([]);

        $this->assertSame([], $parsed->filters);
    }

    #[Test]
    public function returnsNoFiltersWhenFilterIsNotArray(): void
    {
        $parsed = $this->parser->parse(['filter' => 'invalid']);

        $this->assertSame([], $parsed->filters);
    }

    // --- Sorts ---

    #[Test]
    public function parsesSingleAscendingSort(): void
    {
        $query = ['sort' => 'title'];
        $parsed = $this->parser->parse($query);

        $this->assertCount(1, $parsed->sorts);
        $this->assertSame('title', $parsed->sorts[0]->field);
        $this->assertSame('ASC', $parsed->sorts[0]->direction);
    }

    #[Test]
    public function parsesSingleDescendingSort(): void
    {
        $query = ['sort' => '-created'];
        $parsed = $this->parser->parse($query);

        $this->assertCount(1, $parsed->sorts);
        $this->assertSame('created', $parsed->sorts[0]->field);
        $this->assertSame('DESC', $parsed->sorts[0]->direction);
    }

    #[Test]
    public function parsesMultipleSortFields(): void
    {
        $query = ['sort' => 'title,-created'];
        $parsed = $this->parser->parse($query);

        $this->assertCount(2, $parsed->sorts);
        $this->assertSame('title', $parsed->sorts[0]->field);
        $this->assertSame('ASC', $parsed->sorts[0]->direction);
        $this->assertSame('created', $parsed->sorts[1]->field);
        $this->assertSame('DESC', $parsed->sorts[1]->direction);
    }

    #[Test]
    public function returnsNoSortsWhenSortKeyMissing(): void
    {
        $parsed = $this->parser->parse([]);

        $this->assertSame([], $parsed->sorts);
    }

    #[Test]
    public function returnsNoSortsWhenSortIsEmpty(): void
    {
        $parsed = $this->parser->parse(['sort' => '']);

        $this->assertSame([], $parsed->sorts);
    }

    // --- Pagination ---

    #[Test]
    public function parsesPaginationParameters(): void
    {
        $query = ['page' => ['offset' => '10', 'limit' => '25']];
        $parsed = $this->parser->parse($query);

        $this->assertSame(10, $parsed->offset);
        $this->assertSame(25, $parsed->limit);
    }

    #[Test]
    public function parsesOffsetOnly(): void
    {
        $query = ['page' => ['offset' => '5']];
        $parsed = $this->parser->parse($query);

        $this->assertSame(5, $parsed->offset);
        $this->assertNull($parsed->limit);
    }

    #[Test]
    public function parsesLimitOnly(): void
    {
        $query = ['page' => ['limit' => '20']];
        $parsed = $this->parser->parse($query);

        $this->assertNull($parsed->offset);
        $this->assertSame(20, $parsed->limit);
    }

    #[Test]
    public function clampsNegativeOffsetToZero(): void
    {
        $query = ['page' => ['offset' => '-5']];
        $parsed = $this->parser->parse($query);

        $this->assertSame(0, $parsed->offset);
    }

    #[Test]
    public function clampsZeroLimitToOne(): void
    {
        $query = ['page' => ['limit' => '0']];
        $parsed = $this->parser->parse($query);

        $this->assertSame(1, $parsed->limit);
    }

    #[Test]
    public function returnsNullPaginationWhenMissing(): void
    {
        $parsed = $this->parser->parse([]);

        $this->assertNull($parsed->offset);
        $this->assertNull($parsed->limit);
    }

    // --- Sparse Fieldsets ---

    #[Test]
    public function parsesSparseFieldsets(): void
    {
        $query = ['fields' => ['article' => 'title,body']];
        $parsed = $this->parser->parse($query);

        $this->assertSame(['article' => ['title', 'body']], $parsed->sparseFieldsets);
    }

    #[Test]
    public function parsesSparseFieldsetsForMultipleTypes(): void
    {
        $query = ['fields' => ['article' => 'title', 'user' => 'name,email']];
        $parsed = $this->parser->parse($query);

        $this->assertSame(['article' => ['title'], 'user' => ['name', 'email']], $parsed->sparseFieldsets);
    }

    #[Test]
    public function returnsNoFieldsetsWhenMissing(): void
    {
        $parsed = $this->parser->parse([]);

        $this->assertSame([], $parsed->sparseFieldsets);
    }

    #[Test]
    public function returnsNoFieldsetsWhenNotArray(): void
    {
        $parsed = $this->parser->parse(['fields' => 'invalid']);

        $this->assertSame([], $parsed->sparseFieldsets);
    }

    // --- Empty and combined ---

    #[Test]
    public function parsesEmptyQuery(): void
    {
        $parsed = $this->parser->parse([]);

        $this->assertSame([], $parsed->filters);
        $this->assertSame([], $parsed->sorts);
        $this->assertNull($parsed->offset);
        $this->assertNull($parsed->limit);
        $this->assertSame([], $parsed->sparseFieldsets);
    }

    #[Test]
    public function parsesCombinedParameters(): void
    {
        $query = [
            'filter' => [
                'status' => '1',
                'type' => ['operator' => '!=', 'value' => 'page'],
            ],
            'sort' => 'title,-created',
            'page' => ['offset' => '10', 'limit' => '25'],
            'fields' => ['article' => 'title,body'],
        ];

        $parsed = $this->parser->parse($query);

        $this->assertCount(2, $parsed->filters);
        $this->assertSame('status', $parsed->filters[0]->field);
        $this->assertSame('=', $parsed->filters[0]->operator);
        $this->assertSame('type', $parsed->filters[1]->field);
        $this->assertSame('!=', $parsed->filters[1]->operator);

        $this->assertCount(2, $parsed->sorts);
        $this->assertSame('title', $parsed->sorts[0]->field);
        $this->assertSame('ASC', $parsed->sorts[0]->direction);
        $this->assertSame('created', $parsed->sorts[1]->field);
        $this->assertSame('DESC', $parsed->sorts[1]->direction);

        $this->assertSame(10, $parsed->offset);
        $this->assertSame(25, $parsed->limit);
        $this->assertSame(['article' => ['title', 'body']], $parsed->sparseFieldsets);
    }
}
