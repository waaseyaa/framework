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

    public function __construct(private readonly EntityAuditLogger $logger) {}

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
        $tenantId = $entity instanceof \Waaseyaa\Entity\FieldableInterface
            ? (string) ($entity->get('tenant_id') ?? '')
            : '';

        $this->logger->append(new EntityAuditEntry(
            actor: $this->resolveActor($entity),
            action: $action,
            entityId: (string) ($entity->id() ?? ''),
            entityType: $entity->getEntityTypeId(),
            tenantId: $tenantId,
        ));
    }

    public function onPostDelete(EntityEvent $event): void
    {
        $entity   = $event->entity;
        $tenantId = $entity instanceof \Waaseyaa\Entity\FieldableInterface
            ? (string) ($entity->get('tenant_id') ?? '')
            : '';

        $this->logger->append(new EntityAuditEntry(
            actor: $this->resolveActor($entity),
            action: 'delete',
            entityId: (string) ($entity->id() ?? ''),
            entityType: $entity->getEntityTypeId(),
            tenantId: $tenantId,
        ));
    }

    private function resolveActor(\Waaseyaa\Entity\EntityInterface $entity): string
    {
        if ($entity instanceof \Waaseyaa\Entity\FieldableInterface) {
            $uid = $entity->get('uid');

            if ($uid !== null && $uid !== '') {
                return 'uid:' . $uid;
            }
        }

        return 'system';
    }
}
