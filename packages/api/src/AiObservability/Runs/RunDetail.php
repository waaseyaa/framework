<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * Full detail of a single AI observability run including the span tree.
 *
 * @api
 */
final readonly class RunDetail
{
    /**
     * @param list<RunSpanNode> $spans Root-level spans (children nested inside each node).
     */
    public function __construct(
        public RunListRow $header,
        public array $spans,
    ) {}
}
