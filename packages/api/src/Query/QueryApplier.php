<?php

declare(strict_types=1);

namespace Aurora\Api\Query;

use Aurora\Entity\Storage\EntityQueryInterface;

/**
 * Applies a ParsedQuery to an EntityQueryInterface instance.
 *
 * Translates parsed filter, sort, and pagination parameters into
 * entity query method calls.
 */
final class QueryApplier
{
    private int $defaultLimit = 50;
    private int $maxLimit = 100;

    /**
     * Apply parsed query parameters to an entity query.
     *
     * @return EntityQueryInterface The modified query (for chaining).
     */
    public function apply(ParsedQuery $query, EntityQueryInterface $entityQuery): EntityQueryInterface
    {
        // Apply filter conditions.
        foreach ($query->filters as $filter) {
            $entityQuery->condition($filter->field, $filter->value, $filter->operator);
        }

        // Apply sort directives.
        foreach ($query->sorts as $sort) {
            $entityQuery->sort($sort->field, $sort->direction);
        }

        // Apply pagination range.
        $offset = $query->offset ?? 0;
        $limit = $query->limit ?? $this->defaultLimit;
        $limit = min($limit, $this->maxLimit);
        $entityQuery->range($offset, $limit);

        return $entityQuery;
    }

    /**
     * Get the effective limit for the given parsed query.
     */
    public function getEffectiveLimit(ParsedQuery $query): int
    {
        $limit = $query->limit ?? $this->defaultLimit;

        return min($limit, $this->maxLimit);
    }

    /**
     * Get the effective offset for the given parsed query.
     */
    public function getEffectiveOffset(ParsedQuery $query): int
    {
        return $query->offset ?? 0;
    }

    public function getDefaultLimit(): int
    {
        return $this->defaultLimit;
    }

    public function getMaxLimit(): int
    {
        return $this->maxLimit;
    }
}
