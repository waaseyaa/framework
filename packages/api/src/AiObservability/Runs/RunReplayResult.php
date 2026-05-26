<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * Result of replaying an AI observability run.
 *
 * @api
 */
final readonly class RunReplayResult
{
    public function __construct(
        public string $newRunUuid,
        public string $status,
        public string $startedAt,
    ) {}
}
