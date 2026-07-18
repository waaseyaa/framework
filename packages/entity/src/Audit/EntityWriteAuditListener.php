<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

/**
 * Subscribes to entity lifecycle events and appends audit entries.
 *
 * PRE_SAVE captures whether the entity is new (before storage mutates state).
 * POST_SAVE uses the captured flag to log 'create' or 'update'.
 * POST_DELETE logs 'delete'.
 */
final class EntityWriteAuditListener implements EventSubscriberInterface
{
    private bool $pendingIsNew = false;

    private readonly EntityWriteAuditSubjectReader $subjectReader;

    public function __construct(
        private readonly EntityAuditLogger $logger,
        ?EntityWriteAuditSubjectReader $subjectReader = null,
    ) {
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
        $this->pendingIsNew = $event->entity->isNew();
    }

    public function onPostSave(EntityEvent $event): void
    {
        $entity   = $event->entity;
        $action   = $this->pendingIsNew ? 'create' : 'update';
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

    private function resolveActor(EntityWriteAuditSubject $subject): string
    {
        if ($subject->authorId !== null && $subject->authorId !== '') {
            return 'uid:' . $subject->authorId;
        }

        return 'system';
    }
}
