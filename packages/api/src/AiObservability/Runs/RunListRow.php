<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * A single row in the AI observability runs list.
 *
 * @api
 */
final readonly class RunListRow
{
    public function __construct(
        public string $traceUuid,
        public string $pipeline,
        public string $status,
        public string $startedAt,
        public ?string $endedAt,
        public ?int $durationMs,
        public float $costUsd,
        public int $totalTokens,
        public int $spanCount,
    ) {}
}
