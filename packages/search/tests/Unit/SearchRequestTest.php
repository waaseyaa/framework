<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchRequest;

/**
 * @covers \Waaseyaa\Search\SearchRequest
 * @covers \Waaseyaa\Search\SearchFilters
 */
#[CoversClass(SearchRequest::class)]
#[CoversClass(SearchFilters::class)]
final class SearchRequestTest extends TestCase
{
    #[Test]
    public function it_creates_with_defaults(): void
    {
        $request = new SearchRequest(query: 'indigenous');

        $this->assertSame('indigenous', $request->query);
        $this->assertSame(1, $request->page);
        $this->assertSame(20, $request->pageSize);
        $this->assertTrue($request->filters->isEmpty());
    }

    #[Test]
    public function it_creates_with_filters(): void
    {
        $filters = new SearchFilters(topics: ['education'], contentType: 'article');
        $request = new SearchRequest(query: 'test', filters: $filters, page: 2);

        $this->assertSame(['education'], $request->filters->topics);
        $this->assertSame('article', $request->filters->contentType);
        $this->assertSame(2, $request->page);
        $this->assertFalse($request->filters->isEmpty());
    }

    #[Test]
    public function query_and_pagination_bounds_fail_closed(): void
    {
        foreach ([
            static fn() => new SearchRequest(str_repeat('q', SearchRequest::MAX_QUERY_LENGTH + 1)),
            static fn() => new SearchRequest("query\noperator"),
            static fn() => new SearchRequest("query \xC3\x28"),
            static fn() => new SearchRequest('query', page: 0),
            static fn() => new SearchRequest('query', page: PHP_INT_MAX, pageSize: SearchRequest::MAX_PAGE_SIZE),
        ] as $create) {
            try {
                $create();
                self::fail('Expected invalid bounded search input to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function filter_bounds_and_sort_allowlist_fail_closed(): void
    {
        foreach ([
            static fn() => new SearchFilters(minQuality: 101),
            static fn() => new SearchFilters(topics: array_fill(0, 21, 'topic')),
            static fn() => new SearchFilters(sourceNames: [str_repeat('s', 129)]),
            static fn() => new SearchFilters(sourceNames: ["source \xC3\x28"]),
            static fn() => new SearchFilters(sortField: 'raw_rank'),
            static fn() => new SearchFilters(sortOrder: 'sideways'),
        ] as $create) {
            try {
                $create();
                self::fail('Expected invalid search filters to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
