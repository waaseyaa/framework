<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Transition;

/**
 * Outcome of a successful {@see TransitionService::transition()} call
 * (CW-v1 WP-1, docs/specs/content-workflow.md).
 *
 * @api
 */
final readonly class TransitionResult
{
    public function __construct(
        public string $fromState,
        public string $toState,
        public string $transitionId,
    ) {}
}
