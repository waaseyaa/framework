<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Gives `EntityRepository::rollback()` its first-ever audit coverage
 * (CW-v1 WP-2 task 2.5, #1920). `rollback()` copies a prior revision forward
 * as a brand-new revision — unlike `setCurrentRevision()` /
 * `setPublishedRevision()`, it never dispatches
 * {@see \Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent} (that event
 * is reserved for pointer moves WITHOUT a new revision — see its own
 * docblock), so {@see PublishPointerAuditListener} — which subscribes only to
 * that event — never sees a rollback.
 *
 * Discriminator: `rollback()` is the ONLY pointer-move path that dispatches
 * BOTH `EntityEvents::REVISION_CREATED` and `EntityEvents::REVISION_REVERTED`,
 * back-to-back, for the SAME entity type/id/revision (both post-write —
 * `EntityRepository` dispatches them only after the transaction commits, so a
 * denied/aborted rollback never reaches either). Ordinary revision-creating
 * saves dispatch `REVISION_CREATED` alone; `setCurrentRevision()` /
 * `setPublishedRevision()` dispatch `REVISION_REVERTED` alone. A single
 * pending "just-created revision" slot (cleared/overwritten on every check,
 * so it never grows) lets `onRevisionReverted()` recognize the rollback
 * pairing without double-recording the other two paths, which
 * `PublishPointerAuditListener` already covers.
 *
 * Best-effort: exceptions are caught and logged; the rollback operation is
 * never disrupted (NFR-001, mirroring {@see PublishPointerAuditListener}).
 *
 * @api
 */
final class RollbackAuditListener implements EventSubscriberInterface
{
    /**
     * @var array{entityTypeId: string, entityId: string, revisionId: int|string}|null
     */
    private ?array $pendingCreatedRevision = null;

    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AccountContextInterface $accountContext = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::REVISION_CREATED->value  => 'onRevisionCreated',
            EntityEvents::REVISION_REVERTED->value => 'onRevisionReverted',
        ];
    }

    public function onRevisionCreated(EntityEvent $event): void
    {
        $entity = $event->entity;
        if (!$entity instanceof RevisionableEntityInterface || $entity->revisionId() === null) {
            $this->pendingCreatedRevision = null;

            return;
        }

        $this->pendingCreatedRevision = [
            'entityTypeId' => $entity->getEntityTypeId(),
            'entityId'     => (string) $entity->id(),
            'revisionId'   => $entity->revisionId(),
        ];
    }

    public function onRevisionReverted(EntityEvent $event): void
    {
        $pending = $this->pendingCreatedRevision;
        // Consume unconditionally: a stale pending entry must never match a
        // later, unrelated REVISION_REVERTED.
        $this->pendingCreatedRevision = null;

        $entity = $event->entity;
        if ($pending === null || !$entity instanceof RevisionableEntityInterface) {
            return;
        }

        if ($pending['entityTypeId'] !== $entity->getEntityTypeId()
            || $pending['entityId'] !== (string) $entity->id()
            || $pending['revisionId'] !== $entity->revisionId()
        ) {
            // Same-request REVISION_CREATED for a different entity/revision —
            // not the rollback pairing (e.g. setCurrentRevision()/
            // setPublishedRevision(), already audited via RevisionPointerMovedEvent).
            return;
        }

        try {
            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::RevisionRollback,
                accountUid: $this->resolveActorUid(),
                subjectUri: \sprintf('/entities/%s/%s', $entity->getEntityTypeId(), (string) $entity->id()),
                outcome: 'allowed',
                severity: 'notice',
                entityTypeId: $entity->getEntityTypeId(),
                attributes: [
                    'entity_id'      => (string) $entity->id(),
                    'operation'      => 'rollback',
                    'to_revision_id' => $entity->revisionId(),
                ],
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => AuditEventKind::RevisionRollback->value,
            ]);
        }
    }

    /**
     * Rollback's REVISION_CREATED/REVISION_REVERTED dispatches carry no
     * per-event actor (unlike RevisionPointerMovedEvent's `actorUid`) — the
     * acting-account context is the only available source. Null when no
     * acting context exists; never coerced to 0.
     */
    private function resolveActorUid(): ?int
    {
        $account = $this->accountContext?->current();

        return $account !== null ? (int) $account->id() : null;
    }
}
