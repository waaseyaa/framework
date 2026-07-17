<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\List;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\List\ListMetadata;
use Waaseyaa\AdminSurface\List\SurfaceQueryPolicy;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;

final class SurfaceQueryPolicyTest extends TestCase
{
    #[Test]
    public function declared_search_filter_and_sort_are_allowed(): void
    {
        $error = SurfaceQueryPolicy::validate(new SurfaceQuery(
            filters: [
                ['field' => 'title', 'operator' => SurfaceFilterOperator::STARTS_WITH, 'value' => 'Wa'],
                ['field' => 'kind', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'news'],
            ],
            sortField: 'changed',
            sortDirection: 'DESC',
        ), $this->metadata());

        self::assertNull($error);
    }

    #[Test]
    public function undeclared_filter_sort_and_invalid_operator_are_generic_rejections(): void
    {
        $queries = [
            new SurfaceQuery(filters: [['field' => 'secret', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'x']]),
            new SurfaceQuery(sortField: 'secret', sortDirection: 'ASC'),
            new SurfaceQuery(filters: [['field' => 'kind', 'operator' => SurfaceFilterOperator::CONTAINS, 'value' => 'news']]),
            new SurfaceQuery(sortField: 'changed', sortDirection: 'ASC'),
        ];

        foreach ($queries as $query) {
            $error = SurfaceQueryPolicy::validate($query, $this->metadata());
            self::assertNotNull($error);
            self::assertFalse($error->ok);
            self::assertSame(400, $error->error['status']);
            self::assertSame('Invalid list query', $error->error['title']);
            self::assertSame('The requested list query is not allowed.', $error->error['detail']);
            self::assertStringNotContainsString('secret', json_encode($error->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    private function metadata(): ListMetadata
    {
        return ListMetadata::fromArray([
            'search' => ['field' => 'title', 'operator' => 'STARTS_WITH', 'label' => 'Search'],
            'filters' => [['field' => 'kind', 'operator' => 'EQUALS', 'label' => 'Kind']],
            'sorts' => [['field' => 'changed', 'direction' => 'DESC', 'label' => 'Recently changed']],
        ]) ?? throw new \LogicException('Fixture metadata must be valid.');
    }
}
