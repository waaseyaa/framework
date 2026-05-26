<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Audit;

/**
 * Immutable read-model DTO for a single audit event (JSON:API resource).
 *
 * Field names are camelCase (JSON:API convention). Maps 1:1 to the
 * `audit_event` table columns as returned by `ApiAuditQueryAdapter`.
 *
 * @api
 */
final readonly class AuditEventResource
{
    /**
     * @param array<string, mixed> $attributes Freeform JSON-serialisable metadata.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $eventKind,
        public readonly int $accountUid,
        public readonly ?string $entityType,
        public readonly ?string $entityUuid,
        public readonly string $subjectUri,
        public readonly string $outcome,
        public readonly string $severity,
        public readonly array $attributes,
        public readonly string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'eventKind'   => $this->eventKind,
            'accountUid'  => $this->accountUid,
            'entityType'  => $this->entityType,
            'entityUuid'  => $this->entityUuid,
            'subjectUri'  => $this->subjectUri,
            'outcome'     => $this->outcome,
            'severity'    => $this->severity,
            'attributes'  => $this->attributes,
            'createdAt'   => $this->createdAt,
        ];
    }
}
