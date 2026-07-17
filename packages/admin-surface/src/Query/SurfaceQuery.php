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
    /** @var list<string> Server-established bundle scope; never populated by the HTTP parser. */
    public array $trustedBundleScope;

    /**
     * @param array<array{field: string, operator: SurfaceFilterOperator, value: mixed}> $filters
     */
    public function __construct(
        array $filters = [],
        ?string $sortField = null,
        string $sortDirection = 'ASC',
        int $offset = 0,
        int $limit = 50,
        array $trustedBundleScope = [],
    ) {
        $this->filters = $filters;
        $this->sortField = $sortField;
        $this->sortDirection = $sortDirection;
        $this->offset = max(0, $offset);
        $this->limit = $limit < 1 ? 50 : min($limit, 500);
        $this->trustedBundleScope = array_values(array_unique(array_filter(
            $trustedBundleScope,
            static fn(mixed $bundle): bool => is_string($bundle) && $bundle !== '',
        )));
    }
}
