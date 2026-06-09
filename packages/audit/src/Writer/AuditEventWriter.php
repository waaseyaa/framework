<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Real audit writer that appends rows to the `audit_event` OCAP log table via a
 * raw, parameterized INSERT.
 *
 * The writer is wired with {@see \Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase},
 * an append-only DatabaseInterface decorator, so the only mutation it can express
 * against `audit_event` is an insert — there is no update or delete path through
 * the writer (OCAP FR-003). `audit_event` is intentionally NOT a registered
 * content entity; it is a flat log table written directly through the query
 * builder, not the entity repository.
 *
 * Best-effort: all exceptions are caught and logged via {@see LoggerInterface}.
 * The caller's request lifecycle must never be disrupted (FR-005, NFR-001).
 *
 * @api
 */
final class AuditEventWriter implements AuditWriterInterface
{
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function record(AuditEventDescriptor $descriptor): void
    {
        try {
            $this->database->insert('audit_event')->values([
                'uuid'           => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
                'event_kind'     => $descriptor->kind->value,
                'account_uid'    => $descriptor->accountUid,
                // Schema uses NOT NULL DEFAULT '' (empty-sentinel design); non-entity
                // events (entity.read on a path, access.denied, agent.tool.execute) carry
                // no entity reference. Coalesce null → '' or the INSERT violates the
                // NOT NULL constraint and the event is silently dropped (#1587 triage).
                // The read model (AuditEvent::getEntityTypeId2/getEntityUuid) converts
                // '' back to null.
                'entity_type_id' => $descriptor->entityTypeId ?? '',
                'entity_uuid'    => $descriptor->entityUuid ?? '',
                'subject_uri'    => $descriptor->subjectUri,
                'outcome'        => $descriptor->outcome,
                'severity'       => $descriptor->severity,
                'attributes'     => json_encode($descriptor->attributes, JSON_THROW_ON_ERROR),
                'created_at'     => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            ])->execute();
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('audit.write_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => $descriptor->kind->value,
            ]);
        }
    }
}
