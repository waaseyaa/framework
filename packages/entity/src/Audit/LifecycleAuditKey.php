<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

enum LifecycleAuditKey: string
{
    case EntityTypeId = 'entity_type_id';
    case Action = 'action';
    case ActorId = 'actor_id';
    case Timestamp = 'timestamp';
    case TenantId = 'tenant_id';
}
