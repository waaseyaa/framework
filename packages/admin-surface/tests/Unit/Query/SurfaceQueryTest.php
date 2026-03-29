<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;

#[CoversClass(SurfaceQuery::class)]
final class SurfaceQueryTest extends TestCase
{
    #[Test]
    public function empty_query_has_defaults(): void
    {
        $query = new SurfaceQuery();

        $this->assertSame([], $query->filters);
        $this->assertNull($query->sortField);
        $this->assertSame('ASC', $query->sortDirection);
        $this->assertSame(0, $query->offset);
        $this->assertSame(50, $query->limit);
    }

    #[Test]
    public function constructor_accepts_all_parameters(): void
    {
        $filters = [
            ['field' => 'stage', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'lead'],
        ];
        $query = new SurfaceQuery(
            filters: $filters,
            sortField: 'created_at',
            sortDirection: 'DESC',
            offset: 10,
            limit: 25,
        );

        $this->assertCount(1, $query->filters);
        $this->assertSame('stage', $query->filters[0]['field']);
        $this->assertSame(SurfaceFilterOperator::EQUALS, $query->filters[0]['operator']);
        $this->assertSame('lead', $query->filters[0]['value']);
        $this->assertSame('created_at', $query->sortField);
        $this->assertSame('DESC', $query->sortDirection);
        $this->assertSame(10, $query->offset);
        $this->assertSame(25, $query->limit);
    }

    #[Test]
    public function limit_is_clamped_to_500(): void
    {
        $query = new SurfaceQuery(limit: 1000);
        $this->assertSame(500, $query->limit);
    }

    #[Test]
    public function limit_below_1_defaults_to_50(): void
    {
        $query = new SurfaceQuery(limit: 0);
        $this->assertSame(50, $query->limit);
    }
}
