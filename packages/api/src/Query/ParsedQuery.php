<?php

declare(strict_types=1);

namespace Aurora\Api\Query;

/**
 * Value object holding all parsed query components from JSON:API parameters.
 */
final readonly class ParsedQuery
{
    /**
     * @param QueryFilter[]          $filters         Parsed filter conditions.
     * @param QuerySort[]            $sorts           Parsed sort directives.
     * @param int|null               $offset          Pagination offset.
     * @param int|null               $limit           Pagination limit.
     * @param array<string, list<string>> $sparseFieldsets Sparse fieldsets keyed by resource type.
     */
    public function __construct(
        public array $filters = [],
        public array $sorts = [],
        public ?int $offset = null,
        public ?int $limit = null,
        public array $sparseFieldsets = [],
    ) {}
}
