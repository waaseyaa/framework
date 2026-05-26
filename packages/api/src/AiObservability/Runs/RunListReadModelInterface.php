<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * Read-model contract for paginated + filtered AI observability runs.
 *
 * Implemented in `packages/ai-observability` (Layer 5) to keep the API layer
 * (Layer 4) free of `Waaseyaa\AI\*` imports (C-003 / NFR-001).
 *
 * @api
 */
interface RunListReadModelInterface
{
    /**
     * Return a paginated page of runs, newest first.
     *
     * @param int $page   1-based page number (≥1).
     * @param int $perPage Results per page (1–100).
     */
    public function recentRuns(RunListFilter $filter, int $page, int $perPage): RunListPage;
}
