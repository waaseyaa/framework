<?php

declare(strict_types=1);

namespace Aurora\Workflows;

/**
 * Value object representing a transition between workflow states.
 *
 * Transitions are the directed edges in a workflow graph (e.g.
 * "publish" from draft to published). Each transition has one or more
 * source states and exactly one target state.
 */
final readonly class WorkflowTransition
{
    /**
     * @param string $id Machine name of the transition.
     * @param string $label Human-readable label.
     * @param string[] $from Source state IDs that this transition can originate from.
     * @param string $to Target state ID.
     * @param int $weight Sort weight for ordering.
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $from,
        public string $to,
        public int $weight = 0,
    ) {}
}
