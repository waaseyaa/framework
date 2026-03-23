<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

enum EntityAuditKey: string
{
    case Actor = 'actor';
    case Action = 'action';
    case EntityId = 'entity_id';
    case EntityType = 'entity_type';
    case TenantId = 'tenant_id';
    case Timestamp = 'timestamp';
    case EnvelopeVersion = 'envelope_version';
    case IngestSource = 'ingest_source';
    case IngestedAt = 'ingested_at';
}
