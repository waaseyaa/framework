<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * Service contract for replaying an AI observability run.
 *
 * Implemented in `packages/ai-observability` (Layer 5) to keep the API layer
 * (Layer 4) free of `Waaseyaa\AI\*` imports (C-003 / NFR-001).
 *
 * The replay route additionally requires the `ai.trace.replay` gate ability
 * (DIR-004 / FR-007) — the controller does NOT re-check this.
 *
 * @api
 */
interface RunReplayServiceInterface
{
    /**
     * Replay the pipeline run identified by the given trace UUID.
     *
     * @throws \RuntimeException if the trace is not found or the pipeline cannot be resolved.
     */
    public function replay(string $traceUuid): RunReplayResult;
}
