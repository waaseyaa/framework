<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * A paginated page of AI observability runs.
 *
 * @api
 */
final readonly class RunListPage
{
    /**
     * @param list<RunListRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}
}
