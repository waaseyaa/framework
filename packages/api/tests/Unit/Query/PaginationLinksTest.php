<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Query;

use Aurora\Api\Query\PaginationLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaginationLinks::class)]
final class PaginationLinksTest extends TestCase
{
    #[Test]
    public function firstPageHasNoPrevLink(): void
    {
        $links = PaginationLinks::generate('/api/article', 0, 10, 50);

        $this->assertSame('/api/article?page[offset]=0&page[limit]=10', $links['self']);
        $this->assertSame('/api/article?page[offset]=0&page[limit]=10', $links['first']);
        $this->assertSame('/api/article?page[offset]=10&page[limit]=10', $links['next']);
        $this->assertArrayNotHasKey('prev', $links);
    }

    #[Test]
    public function middlePageHasBothPrevAndNextLinks(): void
    {
        $links = PaginationLinks::generate('/api/article', 20, 10, 50);

        $this->assertSame('/api/article?page[offset]=20&page[limit]=10', $links['self']);
        $this->assertSame('/api/article?page[offset]=0&page[limit]=10', $links['first']);
        $this->assertSame('/api/article?page[offset]=10&page[limit]=10', $links['prev']);
        $this->assertSame('/api/article?page[offset]=30&page[limit]=10', $links['next']);
    }

    #[Test]
    public function lastPageHasNoNextLink(): void
    {
        $links = PaginationLinks::generate('/api/article', 40, 10, 50);

        $this->assertSame('/api/article?page[offset]=40&page[limit]=10', $links['self']);
        $this->assertSame('/api/article?page[offset]=0&page[limit]=10', $links['first']);
        $this->assertSame('/api/article?page[offset]=30&page[limit]=10', $links['prev']);
        $this->assertArrayNotHasKey('next', $links);
    }

    #[Test]
    public function selfLinkAlwaysPresent(): void
    {
        $links = PaginationLinks::generate('/api/article', 0, 10, 0);

        $this->assertArrayHasKey('self', $links);
        $this->assertSame('/api/article?page[offset]=0&page[limit]=10', $links['self']);
    }

    #[Test]
    public function firstLinkAlwaysPresent(): void
    {
        $links = PaginationLinks::generate('/api/article', 30, 10, 50);

        $this->assertArrayHasKey('first', $links);
        $this->assertSame('/api/article?page[offset]=0&page[limit]=10', $links['first']);
    }

    #[Test]
    public function singlePageHasNoNextOrPrev(): void
    {
        $links = PaginationLinks::generate('/api/article', 0, 10, 5);

        $this->assertArrayHasKey('self', $links);
        $this->assertArrayHasKey('first', $links);
        $this->assertArrayNotHasKey('next', $links);
        $this->assertArrayNotHasKey('prev', $links);
    }

    #[Test]
    public function emptyResultHasNoNextOrPrev(): void
    {
        $links = PaginationLinks::generate('/api/article', 0, 10, 0);

        $this->assertArrayNotHasKey('next', $links);
        $this->assertArrayNotHasKey('prev', $links);
    }

    #[Test]
    public function prevLinkClampsToZero(): void
    {
        // offset=5 with limit=10 -> prev would be -5, but should clamp to 0.
        $links = PaginationLinks::generate('/api/article', 5, 10, 50);

        $this->assertSame('/api/article?page[offset]=0&page[limit]=10', $links['prev']);
    }

    #[Test]
    public function exactlyFilledLastPage(): void
    {
        // 30 items total, page size 10, offset 20 = last page with exactly 10 items.
        $links = PaginationLinks::generate('/api/article', 20, 10, 30);

        $this->assertArrayNotHasKey('next', $links);
        $this->assertArrayHasKey('prev', $links);
    }
}
