<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Event\EntityEvent;

/**
 * Enforces the at-most-one-active invariant for the generic entity-API save
 * path (`getRepository('attachment')->save()`), which bypasses
 * {@see AttachmentRepository} entirely.
 *
 * Registered by {@see AttachmentServiceProvider::boot()} on
 * `EntityEvents::PRE_SAVE` for every entity save (all entity types share one
 * PRE_SAVE event name; this listener filters to `Attachment` instances).
 * When the entity being saved has `is_active` truthy, it demotes every
 * sibling attachment for the same parent — the same demote-not-reject
 * semantics as {@see AttachmentRepository::setActive()} — BEFORE the row is
 * written.
 *
 * IMPORTANT — residual race: `EntityRepository::save()` dispatches PRE_SAVE
 * *outside* its write transaction (the event fires before
 * `$this->database?->transaction()` opens — see
 * `EntityRepository::doSave()`). This listener's demote UPDATE therefore
 * commits as its own statement, separate from the subsequent INSERT/UPDATE
 * of the target row. Two processes racing this exact interleaving:
 *
 *   P1: demote siblings (commits)
 *   P2: demote siblings (commits — no-op, already demoted)
 *   P1: insert/update P1's row as active (commits)
 *   P2: insert/update P2's row as active (commits)
 *
 * can both "win", leaving two active rows — this listener does NOT make the
 * generic-API path atomic across processes/connections. Detection is the
 * backstop: {@see AttachmentRepository::getActive()} deterministically
 * picks a winner and logs an error when it observes more than one active
 * row, and (where the platform supports it) `AttachmentSchema::ensureTable()`
 * materializes a partial unique index that rejects a second concurrent
 * writer outright. Callers that need single-connection atomicity should
 * write through {@see AttachmentRepository::save()} or
 * {@see AttachmentRepository::setActive()}, both of which wrap the demote +
 * write in one transaction.
 *
 * BATCHES (`saveMany()`) are correct within one process: since the
 * PRE-write dispatch fix in `EntityRepository` (WP2 review), PRE_SAVE fires
 * IMMEDIATELY inside the UnitOfWork batch transaction, so this listener's
 * demote joins the batch transaction, runs before each next insert, and a
 * batch of N active attachments converges to sequential-save semantics
 * (exactly one active, last in batch wins). Before that fix the buffered
 * listeners fired post-commit and cross-demoted each other's rows.
 */
final class AttachmentActiveGuardListener
{
    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function __invoke(EntityEvent $event): void
    {
        $entity = $event->entity;
        if (!$entity instanceof Attachment) {
            return;
        }

        if (!AttachmentActiveInvariant::isActive($entity)) {
            return;
        }

        $id = $entity->id();

        AttachmentActiveInvariant::demoteSiblings(
            $this->database,
            (string) $entity->get('parent_entity_type'),
            (string) $entity->get('parent_entity_id'),
            $id !== null ? (string) $id : null,
        );
    }
}
