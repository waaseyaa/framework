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
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
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
 * Discriminator (task 2.5 review fix): the ARM signal is
 * {@see BeforeRevisionPointerMoveEvent} with `operation === 'rollback'` —
 * that event/operation pair is dispatched by exactly ONE code path,
 * `EntityRepository::rollback()`, pre-write, so it is unambiguous by
 * construction. The earlier design (pairing `REVISION_CREATED` +
 * `REVISION_REVERTED` on entity/revision identity) fabricated rollback
 * records for the forward-draft publish flow: an ordinary save creates
 * revision N, then a legitimate `setPublishedRevision($id, N)` promotes that
 * SAME revision N — identity matched, spurious record. Keying the arm on the
 * pre-event kills that class of bug: no other operation can arm the slot.
 *
 * The armed slot is consumed by the next `EntityEvents::REVISION_REVERTED`
 * for the same entity (rollback dispatches it post-commit — a rolled-back /
 * guard-aborted rollback never does). Staleness is provably bounded: EVERY
 * pointer operation (`rollback`/`revert`/`publish`/`translation_save`)
 * dispatches {@see BeforeRevisionPointerMoveEvent} before any write, and
 * `onBeforePointerMove()` RE-SETS the slot on every dispatch (armed only for
 * `rollback`, cleared for every other operation). So an aborted rollback's
 * stale arm cannot survive to a later `setCurrentRevision()` /
 * `setPublishedRevision()` — their own pre-event clears it first. The
 * REVISION_REVERTED consume also clears unconditionally and additionally
 * requires the entity identity to match the armed pair.
 *
 * Best-effort: exceptions in the recording path are caught and logged; the
 * rollback operation is never disrupted (NFR-001, mirroring
 * {@see PublishPointerAuditListener}). The arm/clear path is plain state
 * assignment — it cannot throw, which matters because a throwing
 * `BeforeRevisionPointerMoveEvent` subscriber would abort the operation.
 *
 * L1 → L1: audit already requires `waaseyaa/entity-storage`
 * ({@see PublishPointerAuditListener} imports `RevisionPointerMovedEvent`
 * from it) — no new manifest edge.
 *
 * @api
 */
final class RollbackAuditListener implements EventSubscriberInterface
{
    /**
     * Armed by a `rollback` pre-pointer-move event; null otherwise.
     *
     * @var array{entityTypeId: string, entityId: string, fromRevisionId: ?int, sourceRevisionId: ?int, actorUid: ?int}|null
     */
    private ?array $armedRollback = null;

    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AccountContextInterface $accountContext = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeRevisionPointerMoveEvent::class  => 'onBeforePointerMove',
            EntityEvents::REVISION_REVERTED->value => 'onRevisionReverted',
        ];
    }

    /**
     * Every pointer operation re-sets the slot: armed for `rollback`
     * (dispatched only by `EntityRepository::rollback()`), cleared for every
     * other operation — this is what makes a stale arm from an aborted
     * rollback unable to correlate with a later unrelated REVISION_REVERTED
     * (see class docblock). Never throws: a throwing subscriber on this
     * event would abort the pointer operation itself.
     */
    public function onBeforePointerMove(BeforeRevisionPointerMoveEvent $event): void
    {
        if ($event->operation !== 'rollback') {
            $this->armedRollback = null;

            return;
        }

        $this->armedRollback = [
            'entityTypeId'   => $event->entityTypeId,
            'entityId'       => $event->entityId,
            'fromRevisionId' => $event->fromRevisionId,
            'sourceRevisionId' => $event->sourceRevisionId,
            'actorUid'       => $event->actorUid,
        ];
    }

    public function onRevisionReverted(EntityEvent $event): void
    {
        $armed = $this->armedRollback;
        // Consume unconditionally: an armed slot must never survive past the
        // first post-write revert signal, matching or not.
        $this->armedRollback = null;

        $entity = $event->entity;
        if ($armed === null || !$entity instanceof RevisionableEntityInterface) {
            return;
        }

        if ($armed['entityTypeId'] !== $entity->getEntityTypeId()
            || $armed['entityId'] !== (string) $entity->id()
        ) {
            // Defensive: a REVISION_REVERTED for a different entity than the
            // armed rollback (cannot happen through EntityRepository today —
            // every pointer path re-sets the slot via its own pre-event —
            // but never record against the wrong subject).
            return;
        }

        try {
            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::RevisionRollback,
                accountUid: $this->resolveActorUid($armed['actorUid']),
                subjectUri: \sprintf('/entities/%s/%s', $entity->getEntityTypeId(), (string) $entity->id()),
                outcome: 'allowed',
                severity: 'notice',
                entityTypeId: $entity->getEntityTypeId(),
                attributes: [
                    'entity_id'        => (string) $entity->id(),
                    'operation'        => 'rollback',
                    'from_revision_id' => $armed['fromRevisionId'],
                    'source_revision_id' => $armed['sourceRevisionId'],
                    // The NEW revision rollback created, known only post-write
                    // (the pre-event's toRevisionId is null for rollback).
                    'to_revision_id'   => $entity->revisionId(),
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
     * Prefer the actor resolved at pre-event dispatch time (`0` is a real
     * value — the anonymous account acted); fall back to the acting-account
     * context; else null ("no acting context") — never coerced to 0. Same
     * three-state contract as {@see PublishPointerAuditListener}.
     */
    private function resolveActorUid(?int $eventActorUid): ?int
    {
        if ($eventActorUid !== null) {
            return $eventActorUid;
        }

        $account = $this->accountContext?->current();

        return $account !== null ? (int) $account->id() : null;
    }
}
