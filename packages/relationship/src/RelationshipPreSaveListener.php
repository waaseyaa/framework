<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\Event\EntityEvent;

final class RelationshipPreSaveListener
{
    private readonly RelationshipTopologyReader $topologyReader;

    private readonly RelationshipMaintenanceReader $maintenanceReader;

    public function __construct(
        private readonly RelationshipValidator $validator,
        ?RelationshipTopologyReader $topologyReader = null,
        ?RelationshipMaintenanceReader $maintenanceReader = null,
    ) {
        $this->topologyReader = $topologyReader ?? new RelationshipTopologyReader();
        $this->maintenanceReader = $maintenanceReader ?? new RelationshipMaintenanceReader();
    }

    public function __invoke(EntityEvent $event): void
    {
        if ($event->entity->getEntityTypeId() !== 'relationship') {
            return;
        }

        if (!$event->entity instanceof Relationship) {
            throw new \LogicException('Framework relationship maintenance requires the canonical Relationship entity.');
        }
        $topology = $this->topologyReader->read($event->entity);
        $normalized = $this->validator->normalize([
            'relationship_type' => $event->entity->get('relationship_type'),
            'from_entity_type' => $topology->fromType,
            'from_entity_id' => $topology->fromId,
            'to_entity_type' => $topology->toType,
            'to_entity_id' => $topology->toId,
            ...$this->maintenanceReader->read($event->entity)->values(),
        ]);
        $this->validator->assertValid($normalized);

        foreach ($normalized as $field => $value) {
            if ($field === 'relationship_type') {
                continue;
            }
            $event->entity->set($field, $value);
        }
    }
}
