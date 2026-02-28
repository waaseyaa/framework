<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Query;

use Aurora\Api\Query\ParsedQuery;
use Aurora\Api\Query\QueryApplier;
use Aurora\Api\Query\QueryFilter;
use Aurora\Api\Query\QuerySort;
use Aurora\Entity\Storage\EntityQueryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryApplier::class)]
final class QueryApplierTest extends TestCase
{
    private QueryApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new QueryApplier();
    }

    #[Test]
    public function appliesFilterConditions(): void
    {
        $query = new ParsedQuery(
            filters: [
                new QueryFilter('status', 1),
                new QueryFilter('type', 'blog', '!='),
            ],
        );

        $entityQuery = $this->createMock(EntityQueryInterface::class);
        $entityQuery->expects($this->exactly(2))
            ->method('condition')
            ->willReturnCallback(function (string $field, mixed $value, string $operator) use ($entityQuery) {
                static $callIndex = 0;
                $callIndex++;
                if ($callIndex === 1) {
                    $this->assertSame('status', $field);
                    $this->assertSame(1, $value);
                    $this->assertSame('=', $operator);
                } else {
                    $this->assertSame('type', $field);
                    $this->assertSame('blog', $value);
                    $this->assertSame('!=', $operator);
                }
                return $entityQuery;
            });

        $entityQuery->method('sort')->willReturnSelf();
        $entityQuery->method('range')->willReturnSelf();

        $result = $this->applier->apply($query, $entityQuery);
        $this->assertSame($entityQuery, $result);
    }

    #[Test]
    public function appliesSortDirectives(): void
    {
        $query = new ParsedQuery(
            sorts: [
                new QuerySort('title', 'ASC'),
                new QuerySort('created', 'DESC'),
            ],
        );

        $entityQuery = $this->createMock(EntityQueryInterface::class);
        $entityQuery->expects($this->exactly(2))
            ->method('sort')
            ->willReturnCallback(function (string $field, string $direction) use ($entityQuery) {
                static $callIndex = 0;
                $callIndex++;
                if ($callIndex === 1) {
                    $this->assertSame('title', $field);
                    $this->assertSame('ASC', $direction);
                } else {
                    $this->assertSame('created', $field);
                    $this->assertSame('DESC', $direction);
                }
                return $entityQuery;
            });

        $entityQuery->method('condition')->willReturnSelf();
        $entityQuery->method('range')->willReturnSelf();

        $this->applier->apply($query, $entityQuery);
    }

    #[Test]
    public function appliesPaginationRange(): void
    {
        $query = new ParsedQuery(offset: 20, limit: 10);

        $entityQuery = $this->createMock(EntityQueryInterface::class);
        $entityQuery->expects($this->once())
            ->method('range')
            ->with(20, 10)
            ->willReturnSelf();

        $entityQuery->method('condition')->willReturnSelf();
        $entityQuery->method('sort')->willReturnSelf();

        $this->applier->apply($query, $entityQuery);
    }

    #[Test]
    public function appliesDefaultLimitWhenNoneSpecified(): void
    {
        $query = new ParsedQuery();

        $entityQuery = $this->createMock(EntityQueryInterface::class);
        $entityQuery->expects($this->once())
            ->method('range')
            ->with(0, 50)  // default offset=0, default limit=50
            ->willReturnSelf();

        $entityQuery->method('condition')->willReturnSelf();
        $entityQuery->method('sort')->willReturnSelf();

        $this->applier->apply($query, $entityQuery);
    }

    #[Test]
    public function enforcesMaxLimit(): void
    {
        $query = new ParsedQuery(limit: 500);

        $entityQuery = $this->createMock(EntityQueryInterface::class);
        $entityQuery->expects($this->once())
            ->method('range')
            ->with(0, 100)  // capped at maxLimit=100
            ->willReturnSelf();

        $entityQuery->method('condition')->willReturnSelf();
        $entityQuery->method('sort')->willReturnSelf();

        $this->applier->apply($query, $entityQuery);
    }

    #[Test]
    public function getEffectiveLimitReturnsDefaultWhenNone(): void
    {
        $query = new ParsedQuery();
        $this->assertSame(50, $this->applier->getEffectiveLimit($query));
    }

    #[Test]
    public function getEffectiveLimitCapsAtMax(): void
    {
        $query = new ParsedQuery(limit: 200);
        $this->assertSame(100, $this->applier->getEffectiveLimit($query));
    }

    #[Test]
    public function getEffectiveLimitReturnsRequestedWhenWithinBounds(): void
    {
        $query = new ParsedQuery(limit: 25);
        $this->assertSame(25, $this->applier->getEffectiveLimit($query));
    }

    #[Test]
    public function getEffectiveOffsetReturnsZeroWhenNone(): void
    {
        $query = new ParsedQuery();
        $this->assertSame(0, $this->applier->getEffectiveOffset($query));
    }

    #[Test]
    public function getEffectiveOffsetReturnsRequestedValue(): void
    {
        $query = new ParsedQuery(offset: 30);
        $this->assertSame(30, $this->applier->getEffectiveOffset($query));
    }

    #[Test]
    public function returnsEntityQueryForChaining(): void
    {
        $query = new ParsedQuery();

        $entityQuery = $this->createMock(EntityQueryInterface::class);
        $entityQuery->method('condition')->willReturnSelf();
        $entityQuery->method('sort')->willReturnSelf();
        $entityQuery->method('range')->willReturnSelf();

        $result = $this->applier->apply($query, $entityQuery);
        $this->assertSame($entityQuery, $result);
    }
}
