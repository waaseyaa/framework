<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Query;

final readonly class SurfaceQuery
{
    /** @var array<array{field: string, operator: SurfaceFilterOperator, value: mixed}> */
    public array $filters;
    public ?string $sortField;
    public string $sortDirection;
    public int $offset;
    public int $limit;

    /**
     * @param array<array{field: string, operator: SurfaceFilterOperator, value: mixed}> $filters
     */
    public function __construct(
        array $filters = [],
        ?string $sortField = null,
        string $sortDirection = 'ASC',
        int $offset = 0,
        int $limit = 50,
    ) {
        $this->filters = $filters;
        $this->sortField = $sortField;
        $this->sortDirection = $sortDirection;
        $this->offset = max(0, $offset);
        $this->limit = $limit < 1 ? 50 : min($limit, 500);
    }
}
