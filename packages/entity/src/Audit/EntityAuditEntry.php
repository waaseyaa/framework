<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Audit;

/**
 * A single entity-write audit record.
 *
 * Required fields capture who did what to which entity and when.
 * Optional replay metadata links entries back to their ingestion envelope.
 */
final class EntityAuditEntry
{
    public readonly string $timestamp;

    public function __construct(
        public readonly string $actor,
        public readonly string $action,
        public readonly string $entityId,
        public readonly string $entityType,
        public readonly string $tenantId,
        public readonly ?string $envelopeVersion = null,
        public readonly ?string $ingestSource    = null,
        public readonly ?string $ingestedAt      = null,
        string $timestamp = '',
    ) {
        $this->timestamp = $timestamp !== ''
            ? $timestamp
            : (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $entry = [
            EntityAuditKey::Actor->value      => $this->actor,
            EntityAuditKey::Action->value     => $this->action,
            EntityAuditKey::EntityId->value   => $this->entityId,
            EntityAuditKey::EntityType->value => $this->entityType,
            EntityAuditKey::TenantId->value   => $this->tenantId,
            EntityAuditKey::Timestamp->value  => $this->timestamp,
        ];

        if ($this->envelopeVersion !== null) {
            $entry[EntityAuditKey::EnvelopeVersion->value] = $this->envelopeVersion;
        }

        if ($this->ingestSource !== null) {
            $entry[EntityAuditKey::IngestSource->value] = $this->ingestSource;
        }

        if ($this->ingestedAt !== null) {
            $entry[EntityAuditKey::IngestedAt->value] = $this->ingestedAt;
        }

        return $entry;
    }
}
