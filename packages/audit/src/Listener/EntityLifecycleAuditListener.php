<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Entity\AuditEvent;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Subscribes to entity lifecycle events and appends OCAP audit records.
 *
 * POST_SAVE → entity.write (kind distinguishes create vs update via isNew).
 * POST_DELETE → entity.delete.
 *
 * Actor source: the acting account from {@see AccountContextInterface}
 * (account N / anonymous 0 / null when no acting context exists — CLI,
 * queue, bootstrap). The saved entity's own `uid` field is NEVER consulted:
 * a user-A session saving a node owned by user B records actor A (#1645).
 *
 * Best-effort: exceptions are caught and logged; the primary request is
 * never disrupted (NFR-001).
 *
 * PRE_SAVE isNew capture is keyed on the entity object ({@see \WeakMap}),
 * not a single listener-wide slot. Batch `saveMany()` dispatches every
 * PRE_SAVE inside the transaction and buffers POST_SAVE until after commit,
 * so a boolean slot would attribute every row from the last PRE event
 * (#1856). The matching POST consumes that entity's entry even when the
 * writer throws, so leftovers cannot poison a later save.
 *
 * @api
 */
final class EntityLifecycleAuditListener implements EventSubscriberInterface
{
    /** @var \WeakMap<EntityInterface, bool> */
    private \WeakMap $pendingIsNew;

    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AccountContextInterface $accountContext = null,
    ) {
        $this->pendingIsNew = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::PRE_SAVE->value    => 'onPreSave',
            EntityEvents::POST_SAVE->value   => 'onPostSave',
            EntityEvents::POST_DELETE->value => 'onPostDelete',
        ];
    }

    public function onPreSave(EntityEvent $event): void
    {
        try {
            $this->pendingIsNew[$event->entity] = $event->entity->isNew();
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function onPostSave(EntityEvent $event): void
    {
        $isNew = $this->consumePendingIsNew($event->entity);

        // Guard (#1587): auditing an AuditEvent re-enters the writer → save →
        // POST_SAVE, causing unbounded recursion. The audit log never audits itself.
        if ($event->entity instanceof AuditEvent) {
            return;
        }

        try {
            $entity = $event->entity;
            $accountUid = $this->resolveActorUid();

            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::EntityWrite,
                accountUid: $accountUid,
                subjectUri: sprintf('/entities/%s/%s', $entity->getEntityTypeId(), $entity->uuid() !== '' ? $entity->uuid() : (string) $entity->id()),
                outcome: 'allowed',
                severity: 'info',
                entityTypeId: $entity->getEntityTypeId(),
                entityUuid: $entity->uuid() !== '' ? $entity->uuid() : null,
                attributes: [
                    'entity_type'  => $entity->getEntityTypeId(),
                    'entity_id'    => (string) ($entity->id() ?? ''),
                    'is_new'       => $isNew,
                    'dirty_fields' => method_exists($entity, 'getDirty') ? $entity->getDirty() : [],
                ],
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => AuditEventKind::EntityWrite->value,
            ]);
        }
    }

    public function onPostDelete(EntityEvent $event): void
    {
        // Guard (#1587): mirror the recursion guard from onPostSave. AuditEvent is
        // append-only, but stay defensive against any future delete path.
        if ($event->entity instanceof AuditEvent) {
            return;
        }

        try {
            $entity = $event->entity;
            $accountUid = $this->resolveActorUid();

            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::EntityDelete,
                accountUid: $accountUid,
                subjectUri: sprintf('/entities/%s/%s', $entity->getEntityTypeId(), $entity->uuid() !== '' ? $entity->uuid() : (string) $entity->id()),
                outcome: 'allowed',
                severity: 'notice',
                entityTypeId: $entity->getEntityTypeId(),
                entityUuid: $entity->uuid() !== '' ? $entity->uuid() : null,
                attributes: [
                    'entity_type' => $entity->getEntityTypeId(),
                    'entity_id'   => (string) ($entity->id() ?? ''),
                ],
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => AuditEventKind::EntityDelete->value,
            ]);
        }
    }

    /**
     * The acting account from the request-scoped context — NOT the saved
     * entity's own `uid` field (the #1645 misattribution this replaced).
     * Null when no acting context exists; never coerced to 0.
     */
    private function resolveActorUid(): ?int
    {
        $account = $this->accountContext?->current();

        return $account !== null ? (int) $account->id() : null;
    }

    private function consumePendingIsNew(EntityInterface $entity): bool
    {
        if (!isset($this->pendingIsNew[$entity])) {
            return false;
        }

        $isNew = $this->pendingIsNew[$entity];
        unset($this->pendingIsNew[$entity]);

        return $isNew === true;
    }
}
