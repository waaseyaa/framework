<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * Read-model contract for fetching a single run with its full span tree.
 *
 * Implemented in `packages/ai-observability` (Layer 5) to keep the API layer
 * (Layer 4) free of `Waaseyaa\AI\*` imports (C-003 / NFR-001).
 *
 * @api
 */
interface RunDetailReadModelInterface
{
    /**
     * Return the full detail of a single trace, or null if not found.
     */
    public function findByUuid(string $traceUuid): ?RunDetail;
}
