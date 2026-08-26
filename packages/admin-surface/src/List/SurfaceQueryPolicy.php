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
        foreach ($query->filters as $filter) {
            if (!self::filterIsAllowed($filter, $metadata)) {
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

    /** @param array{field: string, operator: \Waaseyaa\AdminSurface\Query\SurfaceFilterOperator, value: mixed} $filter */
    private static function filterIsAllowed(array $filter, ListMetadata $metadata): bool
    {
        $declaration = $metadata->toArray();
        $candidates = [];
        if (isset($declaration['search'])) {
            $candidates[] = $declaration['search'];
        }
        foreach ($declaration['filters'] ?? [] as $candidate) {
            $candidates[] = $candidate;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['field'] !== $filter['field'] || $candidate['operator'] !== $filter['operator']->value) {
                continue;
            }
            if (!array_key_exists('options', $candidate)) {
                return true;
            }

            return self::valueMatchesOption($filter['value'], $candidate['options']);
        }

        return false;
    }

    /** @param list<array{value: mixed, label: string}> $options */
    private static function valueMatchesOption(mixed $value, array $options): bool
    {
        $normalized = self::normalizeQueryScalar($value);
        if ($normalized === null) {
            return false;
        }

        foreach ($options as $option) {
            if ($normalized === self::normalizeQueryScalar($option['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirror the scalar spellings emitted into an HTTP query string by the
     * admin client. Null is the literal "null"; a null return means that the
     * supplied value has no safe scalar HTTP representation.
     */
    private static function normalizeQueryScalar(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        return null;
    }

    private static function error(): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(400, 'Invalid list query', 'The requested list query is not allowed.');
    }
}
