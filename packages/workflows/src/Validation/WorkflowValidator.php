<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Validation;

use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * Structural validator for a {@see Workflow} definition.
 *
 * Pure, dependency-free checks over the workflow's own states/transitions/
 * initial_state — no I/O, no config-import awareness. Used at seed time
 * (WP-1 default `editorial` workflow) and by the config-import surface
 * (later work) to reject malformed workflow config before it is persisted
 * (CW-v1, docs/specs/content-workflow.md).
 *
 * @api
 */
final class WorkflowValidator
{
    /**
     * @return list<string> Human-readable violations; empty list means valid.
     */
    public function validate(Workflow $workflow): array
    {
        $states = $workflow->getStates();

        if ($states === []) {
            return [\sprintf("Workflow '%s' defines zero states.", (string) $workflow->id())];
        }

        $violations = [];

        $initialState = $workflow->getInitialState();
        if (!$workflow->hasState($initialState)) {
            $violations[] = \sprintf(
                "Workflow '%s' declares initial_state '%s', which is not a defined state.",
                (string) $workflow->id(),
                $initialState,
            );
        }

        foreach ($workflow->getTransitions() as $transition) {
            foreach ($transition->from as $fromStateId) {
                if (!$workflow->hasState($fromStateId)) {
                    $violations[] = \sprintf(
                        "Transition '%s' references unknown state '%s' in 'from'.",
                        $transition->id,
                        $fromStateId,
                    );
                }
            }

            if (!$workflow->hasState($transition->to)) {
                $violations[] = \sprintf(
                    "Transition '%s' references unknown state '%s' in 'to'.",
                    $transition->id,
                    $transition->to,
                );
            }

            if ($transition->groupConstraint !== null && $transition->groupConstraint !== WorkflowTransition::GROUP_CONSTRAINT_CONTENT_GROUPS) {
                $violations[] = \sprintf(
                    "Transition '%s' declares unknown group_constraint '%s' (only '%s' is supported).",
                    $transition->id,
                    $transition->groupConstraint,
                    WorkflowTransition::GROUP_CONSTRAINT_CONTENT_GROUPS,
                );
            }
        }

        return $violations;
    }
}
