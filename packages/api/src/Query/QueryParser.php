<?php

declare(strict_types=1);

namespace Aurora\Api\Query;

/**
 * Parses JSON:API query parameters into a structured ParsedQuery.
 *
 * Supports:
 * - filter[field]=value (simple equality)
 * - filter[field][operator]=op & filter[field][value]=val (operator filter)
 * - sort=field,-field2 (comma-separated, prefix - for DESC)
 * - page[offset]=N & page[limit]=N (offset-based pagination)
 * - fields[type]=field1,field2 (sparse fieldsets)
 */
final class QueryParser
{
    /**
     * Parse a raw query parameter array into a ParsedQuery.
     *
     * @param array<string, mixed> $query The query parameters (e.g. from $_GET).
     */
    public function parse(array $query): ParsedQuery
    {
        return new ParsedQuery(
            filters: $this->parseFilters($query),
            sorts: $this->parseSorts($query),
            offset: $this->parseOffset($query),
            limit: $this->parseLimit($query),
            sparseFieldsets: $this->parseSparseFieldsets($query),
        );
    }

    /**
     * Parse filter parameters.
     *
     * @return QueryFilter[]
     */
    private function parseFilters(array $query): array
    {
        if (!isset($query['filter']) || !\is_array($query['filter'])) {
            return [];
        }

        $filters = [];

        foreach ($query['filter'] as $field => $definition) {
            if (\is_array($definition)) {
                // Operator filter: filter[field][operator]=op & filter[field][value]=val
                $operator = $definition['operator'] ?? '=';
                $value = $definition['value'] ?? null;
                $filters[] = new QueryFilter($field, $value, $operator);
            } else {
                // Simple equality: filter[field]=value
                $filters[] = new QueryFilter($field, $definition);
            }
        }

        return $filters;
    }

    /**
     * Parse sort parameter.
     *
     * @return QuerySort[]
     */
    private function parseSorts(array $query): array
    {
        if (!isset($query['sort']) || !\is_string($query['sort']) || $query['sort'] === '') {
            return [];
        }

        $sorts = [];
        $fields = explode(',', $query['sort']);

        foreach ($fields as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }

            if (str_starts_with($field, '-')) {
                $sorts[] = new QuerySort(substr($field, 1), 'DESC');
            } else {
                $sorts[] = new QuerySort($field, 'ASC');
            }
        }

        return $sorts;
    }

    /**
     * Parse pagination offset.
     */
    private function parseOffset(array $query): ?int
    {
        if (!isset($query['page']['offset'])) {
            return null;
        }

        $offset = (int) $query['page']['offset'];

        return max(0, $offset);
    }

    /**
     * Parse pagination limit.
     */
    private function parseLimit(array $query): ?int
    {
        if (!isset($query['page']['limit'])) {
            return null;
        }

        $limit = (int) $query['page']['limit'];

        return max(1, $limit);
    }

    /**
     * Parse sparse fieldsets.
     *
     * @return array<string, list<string>>
     */
    private function parseSparseFieldsets(array $query): array
    {
        if (!isset($query['fields']) || !\is_array($query['fields'])) {
            return [];
        }

        $fieldsets = [];

        foreach ($query['fields'] as $type => $fields) {
            if (\is_string($fields)) {
                $fieldsets[$type] = array_map('trim', explode(',', $fields));
            }
        }

        return $fieldsets;
    }
}
