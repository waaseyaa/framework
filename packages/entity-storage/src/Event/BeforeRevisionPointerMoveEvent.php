<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * @api
 *
 * Dispatched BEFORE any write, on every revision POINTER-MOVE path — the
 * bypass choke point CW-v1 WP-2 task 2.4 (#1920) adds so that a workflow
 * guard (WP-2 task 2.5) can validate these paths the same way WP-1's
 * `WorkflowStateGuard` validates ordinary saves.
 *
 * WP-1's guard only observes `EntityRepository::save()` (via
 * `EntityEvents::PRE_SAVE` / {@see BeforeSaveEvent}). But `rollback()`,
 * `setCurrentRevision()`, `setPublishedRevision()`, and the
 * `saveTranslationRevision()` / `saveTranslationRevisions()` / `saveTranslation()`
 * trio move the base-row pointer (or write a new revision) WITHOUT going
 * through `doSave()`, so none of them was previously observable to a
 * save-time guard — a state change made through any of these methods bypassed
 * workflow validation entirely. This event is the fix: every one of those six
 * methods dispatches it before touching storage.
 *
 * Extends Symfony's `Event` (not merely stoppable) because a subscriber
 * denies by THROWING {@see AbortOperationException} — same convention as
 * {@see BeforeSaveEvent} — not by calling `stopPropagation()`. Dispatched (by
 * FQCN) synchronously, before any backend write, so a thrown abort leaves
 * storage completely untouched; no {@see RevisionPointerMovedEvent} /
 * `EntityEvents::REVISION_*` follow-up fires either, and (for the
 * transactional multi-write paths — `saveTranslationRevisions()`,
 * `saveTranslation()`) any write already attempted earlier in the same
 * transaction is rolled back.
 *
 * Deliberately NOT {@see BeforeSaveEvent} / `EntityEvents::PRE_SAVE`: these
 * six call sites are pointer moves (or translation-axis writes), not
 * `doSave()` saves — reusing the save event name would silently activate
 * save-semantics listeners that assume a `SaveContext` and a `doSave()` write
 * shape neither of these paths has.
 *
 * `$revisionValues` is the TARGET revision's raw values array — the revision
 * the pointer is about to point at, or (for the translation trio, where the
 * write creates a brand-new revision rather than re-pointing at an existing
 * one) the new content about to be recorded — so a subscriber can read
 * `workflow_state` (or any other field) without a second load.
 *
 * `$toRevisionId` is `null` whenever the mutation creates a NEW revision
 * whose id is only assigned by the write itself (`rollback()` and the
 * translation trio) — it is not yet knowable at pre-write dispatch time.
 * `setCurrentRevision()` / `setPublishedRevision()` re-point at an EXISTING
 * revision id supplied by the caller, so `$toRevisionId` is always populated
 * for those two operations.
 */
final class BeforeRevisionPointerMoveEvent extends Event
{
    /**
     * @param 'rollback'|'revert'|'publish'|'translation_save' $operation
     * @param array<string, mixed> $revisionValues
     */
    public function __construct(
        public readonly string $entityTypeId,
        public readonly string $entityId,
        public readonly string $operation,
        public readonly ?int $fromRevisionId,
        public readonly ?int $toRevisionId,
        public readonly ?int $actorUid,
        public readonly array $revisionValues,
    ) {}
}
