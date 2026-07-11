<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Group;

use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * Evaluates a transition's `group_constraint` against an acting account's
 * group memberships (CW-v1 WP-3, docs/specs/content-workflow.md "Concepts
 * and config schema"). Permission answers *may this kind of person do this*;
 * this checker answers *may they do it to THIS content*.
 *
 * Fail-closed by design (design invariant 5): content carrying no
 * `group_content` relationship row cannot satisfy ANY group constraint —
 * there is no department to compare membership against, and silently
 * allowing here would defeat the constraint entirely (loud misconfiguration,
 * not a silent pass). A `group_constraint` value this checker does not
 * recognise (config that evaded {@see \Waaseyaa\Workflows\Validation\WorkflowValidator})
 * denies for the same reason: degrading to "unconstrained" on unknown input
 * is the one thing a fail-closed gate must never do.
 *
 * @api
 */
final class GroupConstraintChecker
{
    public function __construct(
        private readonly GroupMembershipService $membership,
    ) {}

    public function satisfies(
        WorkflowTransition $transition,
        string $entityTypeId,
        int|string $entityId,
        int|string $accountUid,
    ): bool {
        if ($transition->groupConstraint === null) {
            return true;
        }

        if ($transition->groupConstraint !== WorkflowTransition::GROUP_CONSTRAINT_CONTENT_GROUPS) {
            // Fail closed: an unrecognised constraint kind denies rather
            // than degrading to unconstrained.
            return false;
        }

        $groups = $this->membership->groupIdsForContent($entityTypeId, $entityId);
        if ($groups === []) {
            // Fail closed: content with no recorded group cannot satisfy a
            // department constraint (design invariant 5).
            return false;
        }

        return $this->membership->isMemberOfAny($accountUid, $groups);
    }
}
