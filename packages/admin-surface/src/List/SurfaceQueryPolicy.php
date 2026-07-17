<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\List;

use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;

/** Server-side enforcement for a validated host list declaration. @api */
final class SurfaceQueryPolicy
{
    public static function validate(SurfaceQuery $query, ListMetadata $metadata): ?AdminSurfaceResultData
    {
        $allowedFilters = $metadata->allowedFilters();
        foreach ($query->filters as $filter) {
            $allowed = false;
            foreach ($allowedFilters as $candidate) {
                if ($candidate['field'] === $filter['field'] && $candidate['operator'] === $filter['operator']->value) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return self::error();
            }
        }

        if ($query->sortField !== null) {
            $allowed = false;
            foreach ($metadata->allowedSorts() as $sort) {
                if ($sort['field'] === $query->sortField && $sort['direction'] === $query->sortDirection) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return self::error();
            }
        }

        return null;
    }

    private static function error(): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(400, 'Invalid list query', 'The requested list query is not allowed.');
    }
}
