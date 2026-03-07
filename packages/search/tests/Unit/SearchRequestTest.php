<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchRequest;

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
    public function cache_key_is_deterministic(): void
    {
        $a = new SearchRequest(query: 'test', page: 1);
        $b = new SearchRequest(query: 'test', page: 1);

        $this->assertSame($a->cacheKey(), $b->cacheKey());
    }

    #[Test]
    public function cache_key_differs_for_different_requests(): void
    {
        $a = new SearchRequest(query: 'test', page: 1);
        $b = new SearchRequest(query: 'test', page: 2);

        $this->assertNotSame($a->cacheKey(), $b->cacheKey());
    }
}
