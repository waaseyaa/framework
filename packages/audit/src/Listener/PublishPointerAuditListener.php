<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Subscribes to revision pointer moves and appends OCAP audit records —
 * publish/revert operations were previously invisible to the audit log
 * (mission revision-audit-provenance-01KTWY5V, FR-006).
 *
 * {@see RevisionPointerMovedEvent} is dispatched by FQCN from
 * `EntityRepository::setPublishedRevision()` (operation `publish`) and
 * `EntityRepository::setCurrentRevision()` (operation `revert`), after the
 * pointer transaction commits. The typed import from entity-storage is
 * layer-clean: audit already requires waaseyaa/entity-storage (L1 → L1).
 *
 * Recorded kinds: `revision.publish` / `revision.revert`, with attributes
 * `{entity_id, operation, from_revision_id, to_revision_id}`.
 *
 * Actor source: the event's `actorUid` (resolved at dispatch time; `0` means
 * the anonymous account acted) → acting account from
 * {@see AccountContextInterface} → null. Never coerced to 0 (#1645).
 *
 * Best-effort: exceptions are caught and logged; the publish/revert
 * operation is never disrupted (NFR-001, contract clause 14).
 *
 * @api
 */
final class PublishPointerAuditListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AccountContextInterface $accountContext = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            RevisionPointerMovedEvent::class => 'onPointerMoved',
        ];
    }

    public function onPointerMoved(RevisionPointerMovedEvent $event): void
    {
        try {
            $kind = $event->operation === 'revert'
                ? AuditEventKind::RevisionRevert
                : AuditEventKind::RevisionPublish;

            $this->writer->record(new AuditEventDescriptor(
                kind: $kind,
                accountUid: $this->resolveActorUid($event),
                subjectUri: sprintf('/entities/%s/%s', $event->entityTypeId, $event->entityId),
                outcome: 'allowed',
                severity: 'notice',
                entityTypeId: $event->entityTypeId,
                attributes: [
                    'entity_id'        => $event->entityId,
                    'operation'        => $event->operation,
                    'from_revision_id' => $event->fromRevisionId,
                    'to_revision_id'   => $event->toRevisionId,
                ],
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => $event->operation === 'revert'
                    ? AuditEventKind::RevisionRevert->value
                    : AuditEventKind::RevisionPublish->value,
            ]);
        }
    }

    /**
     * Prefer the actor resolved at dispatch time (`0` is a real value — the
     * anonymous account acted); fall back to the acting-account context;
     * else null ("no acting context") — never coerced to 0.
     */
    private function resolveActorUid(RevisionPointerMovedEvent $event): ?int
    {
        if ($event->actorUid !== null) {
            return $event->actorUid;
        }

        $account = $this->accountContext?->current();

        return $account !== null ? (int) $account->id() : null;
    }
}
