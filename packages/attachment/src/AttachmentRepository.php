<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Repository for Attachment entities.
 *
 * All entity CRUD is delegated to the injected EntityRepositoryInterface,
 * except where the at-most-one-active invariant requires direct
 * `DatabaseInterface` writes:
 *
 *   - {@see setActive()} issues two UPDATE statements in a single
 *     transaction to atomically flip the active flag (see data-model.md § 1
 *     and research.md Q6).
 *   - {@see save()} — when the entity being saved has `is_active` truthy —
 *     wraps a sibling-demote UPDATE and the entity save in one transaction,
 *     mirroring setActive()'s "demote, then persist" semantics, so a direct
 *     save() of a second active attachment cannot leave two active rows for
 *     the same parent on this connection. This is a DIFFERENT surface from
 *     the generic entity API (`getRepository('attachment')->save()`), which
 *     bypasses this class entirely and is instead guarded by
 *     {@see AttachmentActiveGuardListener} (registered on
 *     `EntityEvents::PRE_SAVE` by `AttachmentServiceProvider::boot()`) — see
 *     that listener's docblock for the residual cross-process race it does
 *     NOT close.
 *   - {@see getActive()} orders deterministically and detects (and logs) the
 *     invariant-violated state where more than one row is active for a
 *     parent, rather than silently masking it behind an unordered LIMIT 1.
 *
 * @api
 */
final class AttachmentRepository
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Returns all attachments for the given parent entity, ordered by id ASC.
     *
     * ACCESS-CHECK CONTRACT (audit-remediation batch, 2026-07-01): this uses
     * `EntityRepositoryInterface::findBy()`, which — unlike
     * `EntityRepository::getQuery()` — does NOT apply a per-account access
     * check (verified by reading `EntityRepository::findBy()`: it calls
     * `$this->driver->findBy()` directly, with no `setAccount()`/
     * `accessCheck()` gate at all). This is a DELIBERATE low-level-primitive
     * shape, not an oversight papered over: `AttachmentRepository` is `@api`
     * public surface with no production caller in THIS repository today
     * (verified by a repo-wide grep for `listFor(`/`getActive(` — the only
     * production usages of `AttachmentRepository` are within the attachment
     * package itself). Any caller — in this codebase or a downstream
     * consumer — that exposes these results to an end user MUST apply its
     * own per-result access check before doing so, the same way
     * `AttachmentDownloadRouter` gates its own (single-row) `find()` call
     * with `EntityAccessHandler::check($attachment, 'view', $account)
     * ->isAllowed()` before streaming bytes. `listFor()`/`getActive()` do
     * NOT perform that check themselves because they have no `$account`
     * parameter to check against — inventing one with no current caller to
     * supply it would be speculative, not a fix. See
     * `docs/specs/work-surface.md` § F4 for the caller-must-gate contract
     * this documents. NOTE: this contract is a documented convention with
     * NO mechanical enforcement — findBy() callsites are invisible to the
     * check-getquery-bindings CI gate (it only sees getQuery() chains), so
     * reviewers of new consumers must check compliance by hand.
     *
     * @return list<Attachment>
     */
    public function listFor(string $parentEntityType, string $parentId): array
    {
        /** @var list<Attachment> */
        return $this->entityRepository->findBy(
            ['parent_entity_type' => $parentEntityType, 'parent_entity_id' => $parentId],
            ['id' => 'ASC'],
        );
    }

    /**
     * Returns the single active attachment for the given parent entity, or null.
     *
     * ACCESS-CHECK CONTRACT: same unguarded-primitive shape as
     * {@see listFor()} — `findBy()` applies no per-account access check, and
     * this method has no `$account` parameter to check against. See
     * {@see listFor()}'s docblock for the full rationale (no current
     * production caller; downstream `@api` consumers must gate their own
     * results the way `AttachmentDownloadRouter` does).
     *
     * Orders by id DESC (newest wins) and fetches up to 2 rows so a
     * multi-active state — which should never happen, but the save-path
     * guards are not fully atomic across processes; see
     * {@see AttachmentActiveGuardListener} — is detected rather than
     * silently masked by an unordered LIMIT 1. When 2 rows come back, the
     * violation is logged at ERROR with the parent and both ids, and the
     * deterministic winner (highest id) is still returned so callers keep
     * getting a usable result.
     */
    public function getActive(string $parentEntityType, string $parentId): ?Attachment
    {
        /** @var list<Attachment> $results */
        $results = $this->entityRepository->findBy(
            [
                'parent_entity_type' => $parentEntityType,
                'parent_entity_id' => $parentId,
                'is_active' => 1,
            ],
            ['id' => 'DESC'],
            2,
        );

        if (\count($results) > 1) {
            $ids = array_map(static fn(Attachment $a): string => (string) $a->id(), $results);

            // Message carries the real values directly (sprintf, not PSR-3
            // {placeholder} interpolation — LoggerInterface does not
            // guarantee interpolation at the call site); $context is kept
            // as structured data for consumers that read it programmatically.
            $this->logger->error(
                \sprintf(
                    'AttachmentRepository::getActive(): at-most-one-active invariant violated for '
                    . 'parent %s:%s — %d active attachments found (ids: %s). Returning the newest '
                    . '(highest id) as the deterministic winner.',
                    $parentEntityType,
                    $parentId,
                    \count($results),
                    implode(', ', $ids),
                ),
                [
                    'parentEntityType' => $parentEntityType,
                    'parentEntityId' => $parentId,
                    'ids' => $ids,
                ],
            );
        }

        return $results[0] ?? null;
    }

    /**
     * Persists an Attachment entity via the entity repository.
     *
     * When $attachment's `is_active` is truthy, demotes every sibling
     * attachment for the same parent and persists $attachment in a single
     * transaction — mirroring setActive()'s "demote, then persist"
     * semantics — so this direct save path cannot leave two active rows on
     * this connection. Inactive saves are unaffected (no transaction, no
     * demote — identical to the pre-fix behavior).
     */
    public function save(Attachment $attachment): void
    {
        if (!AttachmentActiveInvariant::isActive($attachment)) {
            $this->entityRepository->save($attachment);

            return;
        }

        $id = $attachment->id();

        $transaction = $this->database->transaction();
        try {
            AttachmentActiveInvariant::demoteSiblings(
                $this->database,
                (string) $attachment->get('parent_entity_type'),
                (string) $attachment->get('parent_entity_id'),
                $id !== null ? (string) $id : null,
            );

            $this->entityRepository->save($attachment);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Removes an Attachment entity by its ID.
     *
     * No-op if the attachment does not exist.
     */
    public function delete(string $attachmentId): void
    {
        $entity = $this->entityRepository->find($attachmentId);
        if ($entity instanceof Attachment) {
            $this->entityRepository->delete($entity);
        }
    }

    /**
     * Atomically marks the given attachment as active and clears all sibling
     * attachments for the same parent entity.
     *
     * Issues two UPDATE statements in a single database transaction:
     *   1. SET is_active = 0 on all siblings (same parent_entity_type + parent_entity_id).
     *   2. SET is_active = 1 on the target attachment.
     *
     * Both UPDATEs also stamp `updated_at` (unix timestamp — there is no
     * injectable clock convention elsewhere in the framework to mirror;
     * `time()` matches how raw-SQL writes stamp timestamps in other packages,
     * e.g. `media`'s version rows) so a row's audit trail reflects the
     * `is_active` change these UPDATEs make, on both the demoted siblings
     * and the newly-activated row.
     *
     * Entity events are NOT fired for deactivated siblings — intentional.
     *
     * @throws AttachmentNotFoundException If no attachment with $attachmentId exists.
     */
    public function setActive(string $attachmentId): void
    {
        $attachment = $this->entityRepository->find($attachmentId);
        if (!$attachment instanceof Attachment) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        $parentType = (string) $attachment->get('parent_entity_type');
        $parentId = (string) $attachment->get('parent_entity_id');
        $now = time();

        $transaction = $this->database->transaction();
        try {
            // Clear active flag on all attachments for this parent.
            $this->database->update('attachment')
                ->fields(['is_active' => 0, 'updated_at' => $now])
                ->condition('parent_entity_type', $parentType)
                ->condition('parent_entity_id', $parentId)
                ->execute();

            // Set active flag on the target attachment.
            $this->database->update('attachment')
                ->fields(['is_active' => 1, 'updated_at' => $now])
                ->condition('id', $attachmentId)
                ->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
