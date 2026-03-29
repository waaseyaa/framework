<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Query;

use Symfony\Component\HttpFoundation\Request;

final class SurfaceQueryParser
{
    public static function fromRequest(Request $request): SurfaceQuery
    {
        $filters = self::parseFilters($request);
        [$sortField, $sortDirection] = self::parseSort($request);
        [$offset, $limit] = self::parsePagination($request);

        return new SurfaceQuery(
            filters: $filters,
            sortField: $sortField,
            sortDirection: $sortDirection,
            offset: $offset,
            limit: $limit,
        );
    }

    /**
     * @return array<array{field: string, operator: SurfaceFilterOperator, value: mixed}>
     */
    private static function parseFilters(Request $request): array
    {
        $raw = $request->query->all('filter');
        if (!is_array($raw)) {
            return [];
        }

        $filters = [];
        foreach ($raw as $field => $condition) {
            if (!is_array($condition) || !isset($condition['operator'], $condition['value'])) {
                continue;
            }
            $operator = SurfaceFilterOperator::fromString($condition['operator']);
            if ($operator === null) {
                continue;
            }
            $filters[] = [
                'field' => (string) $field,
                'operator' => $operator,
                'value' => $condition['value'],
            ];
        }

        return $filters;
    }

    /**
     * @return array{?string, string}
     */
    private static function parseSort(Request $request): array
    {
        $sort = $request->query->getString('sort');
        if ($sort === '') {
            return [null, 'ASC'];
        }
        if (str_starts_with($sort, '-')) {
            return [substr($sort, 1), 'DESC'];
        }

        return [$sort, 'ASC'];
    }

    /**
     * @return array{int, int}
     */
    private static function parsePagination(Request $request): array
    {
        $page = $request->query->all('page');
        if (!is_array($page)) {
            $page = [];
        }

        $offset = max(0, (int) ($page['offset'] ?? 0));
        $limit = (int) ($page['limit'] ?? 50);

        return [$offset, $limit];
    }
}
