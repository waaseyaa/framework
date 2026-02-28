<?php

declare(strict_types=1);

namespace Aurora\Api\Query;

/**
 * Value object representing a single sort directive.
 */
final readonly class QuerySort
{
    public function __construct(
        public string $field,
        public string $direction = 'ASC',
    ) {
        if (!\in_array($this->direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid sort direction "%s". Must be "ASC" or "DESC".', $this->direction),
            );
        }
    }
}
