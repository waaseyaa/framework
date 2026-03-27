<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Query;

/**
 * Value object representing a single query filter condition.
 */
final readonly class QueryFilter
{
    /**
     * Supported filter operators.
     */
    private const VALID_OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'CONTAINS', 'STARTS_WITH', 'IN'];

    public function __construct(
        public string $field,
        public mixed $value,
        public string $operator = '=',
    ) {
        if (!\in_array($this->operator, self::VALID_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid filter operator "%s". Supported operators: %s', $this->operator, implode(', ', self::VALID_OPERATORS)),
            );
        }
    }
}
