<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQueryParser;

#[CoversClass(SurfaceQueryParser::class)]
final class SurfaceQueryParserTest extends TestCase
{
    #[Test]
    public function empty_request_returns_default_query(): void
    {
        $request = Request::create('/admin/surface/lead');
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertSame([], $query->filters);
        $this->assertNull($query->sortField);
        $this->assertSame(0, $query->offset);
        $this->assertSame(50, $query->limit);
    }

    #[Test]
    public function parses_single_filter(): void
    {
        $request = Request::create('/admin/surface/lead', 'GET', [
            'filter' => [
                'stage' => ['operator' => 'EQUALS', 'value' => 'lead'],
            ],
        ]);
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertCount(1, $query->filters);
        $this->assertSame('stage', $query->filters[0]['field']);
        $this->assertSame(SurfaceFilterOperator::EQUALS, $query->filters[0]['operator']);
        $this->assertSame('lead', $query->filters[0]['value']);
    }

    #[Test]
    public function parses_multiple_filters(): void
    {
        $request = Request::create('/admin/surface/lead', 'GET', [
            'filter' => [
                'stage' => ['operator' => 'IN', 'value' => 'lead,qualified'],
                'sector' => ['operator' => 'EQUALS', 'value' => 'IT'],
            ],
        ]);
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertCount(2, $query->filters);
    }

    #[Test]
    public function ignores_invalid_operator(): void
    {
        $request = Request::create('/admin/surface/lead', 'GET', [
            'filter' => [
                'stage' => ['operator' => 'LIKE', 'value' => 'lead'],
            ],
        ]);
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertSame([], $query->filters);
    }

    #[Test]
    public function parses_sort_ascending(): void
    {
        $request = Request::create('/admin/surface/lead', 'GET', [
            'sort' => 'created_at',
        ]);
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertSame('created_at', $query->sortField);
        $this->assertSame('ASC', $query->sortDirection);
    }

    #[Test]
    public function parses_sort_descending_with_minus_prefix(): void
    {
        $request = Request::create('/admin/surface/lead', 'GET', [
            'sort' => '-stage_changed_at',
        ]);
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertSame('stage_changed_at', $query->sortField);
        $this->assertSame('DESC', $query->sortDirection);
    }

    #[Test]
    public function parses_pagination(): void
    {
        $request = Request::create('/admin/surface/lead', 'GET', [
            'page' => ['offset' => '10', 'limit' => '25'],
        ]);
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertSame(10, $query->offset);
        $this->assertSame(25, $query->limit);
    }

    #[Test]
    public function parses_bracket_style_pagination(): void
    {
        $request = Request::create('/admin/surface/lead?page%5Boffset%5D=5&page%5Blimit%5D=100');
        $query = SurfaceQueryParser::fromRequest($request);

        $this->assertSame(5, $query->offset);
        $this->assertSame(100, $query->limit);
    }
}
