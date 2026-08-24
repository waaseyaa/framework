<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

/**
 * Subscribes to entity lifecycle events and appends audit entries.
 *
 * PRE_SAVE captures whether the entity is new (before storage mutates state).
 * POST_SAVE uses the captured flag to log 'create' or 'update'.
 * POST_DELETE logs 'delete'.
 *
 * Capture is keyed on the entity object ({@see \WeakMap}), not a single
 * listener-wide slot. Batch `saveMany()` dispatches every PRE_SAVE inside
 * the transaction and buffers POST_SAVE until after commit
 * (`pre1, pre2, …, post1, post2, …`), so a boolean slot would attribute
 * every row from the last PRE event (#1856). The matching POST consumes
 * that entity's entry; leftovers cannot survive a later save of a
 * different object.
 */
final class EntityWriteAuditListener implements EventSubscriberInterface
{
    /** @var \WeakMap<EntityInterface, bool> */
    private \WeakMap $pendingIsNew;

    private readonly EntityWriteAuditSubjectReader $subjectReader;

    public function __construct(
        private readonly EntityAuditLogger $logger,
        ?EntityWriteAuditSubjectReader $subjectReader = null,
    ) {
        $this->pendingIsNew = new \WeakMap();
        $this->subjectReader = $subjectReader ?? new EntityWriteAuditSubjectReader();
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
        $this->pendingIsNew[$event->entity] = $event->entity->isNew();
    }

    public function onPostSave(EntityEvent $event): void
    {
        $entity = $event->entity;
        $action = $this->consumePendingIsNew($entity) ? 'create' : 'update';
        $subject = $this->subjectReader->read($entity);

        $this->logger->append(new EntityAuditEntry(
            actor: $this->resolveActor($subject),
            action: $action,
            entityId: (string) ($entity->id() ?? ''),
            entityType: $entity->getEntityTypeId(),
            tenantId: $subject->tenantId ?? '',
        ));
    }

    public function onPostDelete(EntityEvent $event): void
    {
        $entity   = $event->entity;
        $subject = $this->subjectReader->read($entity);

        $this->logger->append(new EntityAuditEntry(
            actor: $this->resolveActor($subject),
            action: 'delete',
            entityId: (string) ($entity->id() ?? ''),
            entityType: $entity->getEntityTypeId(),
            tenantId: $subject->tenantId ?? '',
        ));
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

    private function resolveActor(EntityWriteAuditSubject $subject): string
    {
        if ($subject->authorId !== null && $subject->authorId !== '') {
            return 'uid:' . $subject->authorId;
        }

        return 'system';
    }
}
