<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Read;

/** Exact workflow engine view over revision and publication selectors. @api */
final readonly class WorkflowEntitySnapshot
{
    public function __construct(
        public ?string $workflowState,
        public int $status,
        public int|string|null $publishedRevisionId,
        public int|string|null $revisionId,
    ) {}
}
