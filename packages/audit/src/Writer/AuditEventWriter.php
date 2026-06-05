<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Entity\AuditEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Real audit writer that persists {@see AuditEvent} rows via the entity repository.
 *
 * Best-effort: all exceptions are caught and logged via {@see LoggerInterface}.
 * The caller's request lifecycle must never be disrupted (FR-005, NFR-001).
 *
 * @api
 */
final class AuditEventWriter implements AuditWriterInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $repository,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function record(AuditEventDescriptor $descriptor): void
    {
        try {
            $entity = new AuditEvent([
                'event_kind'     => $descriptor->kind->value,
                'account_uid'    => $descriptor->accountUid,
                'subject_uri'    => $descriptor->subjectUri,
                'outcome'        => $descriptor->outcome,
                'severity'       => $descriptor->severity,
                // Schema uses NOT NULL DEFAULT '' (empty-sentinel design); non-entity
                // events (entity.read on a path, access.denied, agent.tool.execute) carry
                // no entity reference. Coalesce null → '' or the INSERT violates the
                // NOT NULL constraint and the event is silently dropped (#1587 triage).
                // The read model (AuditEvent::getEntityTypeId2/getEntityUuid) converts
                // '' back to null.
                'entity_type_id' => $descriptor->entityTypeId ?? '',
                'entity_uuid'    => $descriptor->entityUuid ?? '',
                'attributes'     => json_encode($descriptor->attributes, JSON_THROW_ON_ERROR),
                'created_at'     => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'uuid'           => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            ]);

            $entity->enforceIsNew();
            $this->repository->save($entity);
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.write_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => $descriptor->kind->value,
            ]);
        }
    }
}
