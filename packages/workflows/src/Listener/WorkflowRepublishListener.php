<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Listener;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader;
use Waaseyaa\Workflows\Republish\RepublishMarker;

/**
 * The consume-at-POST_SAVE half of the same-state republish two-step
 * (CW-v1 option-1, #1920 PR-2, design §3.1 "Same-state republish"). See
 * {@see WorkflowStateGuard::guardSameStateRepublish()} for the arm-at-PRE_SAVE
 * half — this listener trusts the marker completely and does NOT re-derive
 * same-stateness itself: the event's `originalEntity` is the BASE row
 * (already 'published' under discipline), which would misclassify
 * {@see \Waaseyaa\Workflows\Transition\TransitionService}'s promote-branch
 * first save (state-changing on the guard's own working-copy basis) as
 * same-state and double-promote inside the SAME `repository->save()` call,
 * resurrecting the orphan-tip residue (design §3.1 finding A1).
 *
 * Registered against {@see \Waaseyaa\Entity\Event\EntityEvents::POST_SAVE}.
 * Failure between the save and the promotion (a denial from
 * {@see \Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard}'s own
 * re-validation, or any other throw from `setPublishedRevision()`) is
 * allowed to propagate — loud, never swallowed: the published revision
 * stays untouched and the just-saved tip stays unpromoted, exactly the
 * same non-atomic shape `TransitionService`'s promote branch already uses
 * (WP-2-accepted).
 *
 * @api
 */
final class WorkflowRepublishListener
{
    public function __construct(
        private readonly RepublishMarker $marker,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?WorkflowEntitySnapshotReader $workflowValues = null,
    ) {}

    public function onPostSave(EntityEvent $event): void
    {
        $entity = $event->entity;

        if (!$this->marker->consume($entity)) {
            return;
        }

        $id = $entity->id();
        if ($id === null || $id === '') {
            return;
        }

        $revisionId = $this->revisionIdOf($entity);
        if ($revisionId === null) {
            return;
        }

        $repository = $this->entityTypeManager->getRepository($entity->getEntityTypeId());

        // Already-published self-skip (fix-wave, #1920 PR-2 adversarial
        // review): a SPURIOUS arm is possible — in the guard-first PRE_SAVE
        // listener order, WorkflowStateGuard's willCreateRevision() runs
        // BEFORE NodeRevisionDefaultListener applies the bundle's
        // `new_revision: false` opt-out, so the guard arms on the
        // entity-type default and the save then updates the published tip
        // IN PLACE. Promoting the revision the pointer already serves is a
        // pure no-op move that would still re-fire the pointer-move
        // event/audit/reindex chain — skip it. One cheap base-row read:
        // find() hydrates `published_revision_id` into the values bag (the
        // documented WP-2 gotcha, load-bearing here). This makes the
        // arm/consume pair harmless in EVERY PRE_SAVE listener order.
        $baseRow = $repository->find((string) $id);
        $livePointer = $baseRow !== null ? $this->workflowValues()->read($baseRow)->publishedRevisionId : null;
        if ($livePointer !== null && (int) $livePointer === $revisionId) {
            return;
        }

        // The returned freshly-promoted entity isn't needed here — the
        // caller already has $entity in memory — but the call's return
        // value is captured (not discarded) to satisfy the "don't discard
        // a meaningful return value" static-analysis rule (same convention
        // TransitionService's own promote branch follows for the identical
        // call).
        $promoted = $repository->setPublishedRevision((string) $id, $revisionId);
    }

    /**
     * Duck-checks both revision contracts (the #1654 pattern, mirrored from
     * {@see \Waaseyaa\Workflows\Transition\TransitionService::revisionIdOf()}):
     * the just-saved tip's revision id off the entity object itself — no
     * second load.
     */
    private function revisionIdOf(EntityInterface $entity): ?int
    {
        if ($entity instanceof RevisionableInterface) {
            return $entity->getRevisionId();
        }

        if ($entity instanceof RevisionableEntityInterface && \method_exists($entity, 'getRevisionId')) {
            $revisionId = $entity->getRevisionId();

            return \is_int($revisionId) ? $revisionId : null;
        }

        return null;
    }

    private function workflowValues(): WorkflowEntitySnapshotReader
    {
        return $this->workflowValues ?? new WorkflowEntitySnapshotReader();
    }
}
