<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Republish;

use Waaseyaa\Entity\EntityInterface;

/**
 * The arm-at-PRE_SAVE / consume-at-POST_SAVE handoff between
 * {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard} and
 * {@see \Waaseyaa\Workflows\Listener\WorkflowRepublishListener} (CW-v1
 * option-1, #1920 PR-2, design §3.1 "Same-state republish").
 *
 * `WorkflowStateGuard::onPreSave()` ARMS this marker, keyed on the entity
 * OBJECT itself, exactly when it authorizes a disciplined, revision-creating,
 * same-state save into a `default_revision: true` state — the guard's own
 * working-copy basis is the only place that can tell same-state apart from
 * `TransitionService`'s promote-branch first save (state-changing on that
 * basis), so arming happens there, not by re-deriving same-stateness later.
 * `WorkflowRepublishListener::onPostSave()` CONSUMES it — never re-derives
 * anything — and promotes the just-saved tip through the
 * `setPublishedRevision()` choke point.
 *
 * A `\WeakMap` keyed on the entity object (not the entity id) because a
 * single `EntityRepository::save()` call dispatches PRE_SAVE and POST_SAVE
 * with the SAME entity object instance (verified: `EntityRepository::doSave()`
 * mutates `$entity` in place — `setRevisionId()` et al — rather than
 * constructing a new instance between the two events), so object identity is
 * an unambiguous, self-cleaning correlation key: an entity object that is
 * garbage-collected without ever reaching POST_SAVE (a PRE_SAVE-thrown
 * denial, or any other abort) leaves no dangling entry to leak across a
 * later, unrelated save of the same entity ID via a different object.
 *
 * Bound as a container singleton ({@see \Waaseyaa\Workflows\WorkflowServiceProvider})
 * so the guard and the listener share the same instance.
 *
 * @api
 */
final class RepublishMarker
{
    /** @var \WeakMap<EntityInterface, true> */
    private \WeakMap $armed;

    public function __construct()
    {
        $this->armed = new \WeakMap();
    }

    /**
     * @api
     */
    public function arm(EntityInterface $entity): void
    {
        $this->armed[$entity] = true;
    }

    /**
     * Returns true (and clears the slot) iff `$entity` was armed. Idempotent
     * per arm: a second consume() call for the same object without an
     * intervening arm() returns false.
     *
     * @api
     */
    public function consume(EntityInterface $entity): bool
    {
        if (!isset($this->armed[$entity])) {
            return false;
        }

        unset($this->armed[$entity]);

        return true;
    }

    /**
     * Remove any arm for `$entity` without acting on it (fix-wave, #1920
     * PR-2 adversarial review, stale-arm fix):
     * {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard::onPreSave()}
     * calls this unconditionally at the start of EVERY guarded save,
     * mirroring the unconditional discipline-flag reset — a PRE_SAVE-aborted
     * save (a later listener threw AFTER the guard armed) must never leave
     * an arm behind that a later, unrelated save of the same object
     * consumes.
     *
     * @api
     */
    public function clear(EntityInterface $entity): void
    {
        unset($this->armed[$entity]);
    }
}
