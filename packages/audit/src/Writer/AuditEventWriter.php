<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriteFailureObserver;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
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
 * Best-effort: all exceptions are caught and handled internally. The caller's
 * request lifecycle must never be disrupted (FR-005, NFR-001).
 *
 * On a write failure (design §10.4 — marker + metric, NOT hard fail-closed):
 *  1. The {@see AuditWriteFailureObserver} seam is called — real implementations
 *     at L6 increment a Prometheus counter or fire a structured alert.
 *  2. One best-effort marker INSERT is attempted ({@see AuditEventKind::AuditWriteDegraded})
 *     so the tamper-evidence gap is attested in the log rather than invisible.
 *  3. If the marker ALSO fails, just log — no recursion, no re-entry into
 *     record(), no exception escaping to the caller.
 *
 * @api
 */
final class AuditEventWriter implements AuditWriterInterface
{
    private readonly LoggerInterface $logger;
    private readonly AuditWriteFailureObserver $observer;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
        ?AuditWriteFailureObserver $observer = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->observer = $observer ?? new NullAuditWriteFailureObserver();
    }

    public function record(AuditEventDescriptor $descriptor): void
    {
        try {
            $this->insertRow($descriptor);
        } catch (\Throwable $e) {
            $this->logger->warning('audit.write_failed', [
                'listener' => self::class,
                'error'    => $e->getMessage(),
                'kind'     => $descriptor->kind->value,
            ]);

            // Loud metric seam: notify the observer (e.g. Prometheus counter at L6).
            $this->observer->writeFailed($descriptor, $e);

            // Attested degraded window: one best-effort marker INSERT so the gap
            // appears in the chain rather than disappearing silently.
            $this->writeMarker($descriptor, $e);
        }
    }

    /**
     * Raw INSERT — no try-catch. Both record() and writeMarker() call this so
     * that the INSERT logic lives in exactly one place.
     */
    private function insertRow(AuditEventDescriptor $descriptor): void
    {
        $this->database->insert('audit_event')->values([
            'uuid'           => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'event_kind'     => $descriptor->kind->value,
            // actor_uid is the authoritative three-state actor (N / 0 / NULL):
            // a null descriptor actor is preserved as SQL NULL — "no acting
            // context" stays distinct from the anonymous account 0 (FR-004).
            // account_uid keeps its legacy NOT NULL 0-sentinel semantics so
            // existing dashboards/filters keep working byte-for-byte (C-004).
            'actor_uid'      => $descriptor->accountUid,
            'account_uid'    => $descriptor->accountUid ?? 0,
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
    }

    /**
     * Best-effort marker INSERT: writes one `audit.write_degraded` row to attest
     * the degraded window when the primary INSERT failed. Has its own try-catch —
     * if the marker ALSO fails, just log; never re-enter record() or recurse.
     */
    private function writeMarker(AuditEventDescriptor $dropped, \Throwable $cause): void
    {
        try {
            $marker = new AuditEventDescriptor(
                kind: AuditEventKind::AuditWriteDegraded,
                accountUid: $dropped->accountUid,
                subjectUri: $dropped->subjectUri,
                outcome: 'error',
                severity: 'warning',
                entityTypeId: $dropped->entityTypeId,
                entityUuid: $dropped->entityUuid,
                attributes: [
                    'dropped_kind'  => $dropped->kind->value,
                    'error_class'   => $cause::class,
                    'error_message' => $cause->getMessage(),
                ],
            );

            $this->insertRow($marker);
        } catch (\Throwable $markerError) {
            $this->logger->warning('audit.marker_write_failed', [
                'listener'     => self::class,
                'error'        => $markerError->getMessage(),
                'dropped_kind' => $dropped->kind->value,
            ]);
        }
    }
}
