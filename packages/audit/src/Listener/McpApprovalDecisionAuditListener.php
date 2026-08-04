<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Listener;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Records an OCAP audit entry when a human operator decides a pending MCP
 * write-tier approval request (#2177 F1 C1b).
 *
 * The event name `waaseyaa.mcp.approval_decision` is dispatched by the
 * approval decision controller AFTER the decision has been made durable in
 * `mcp_approval_event`. This projection is the ordinary best-effort tier: a
 * failure here is logged and swallowed — it must never roll back, or
 * misreport, the already-durable decision (the `decided` row is the evidence
 * of record; this row is the queryable convenience view).
 *
 * The listener is duck-typed on the event object (like
 * {@see McpDispatchAuditListener}) so the audit package never imports the
 * higher-layer event class. It projects ONLY safe join fields — request id,
 * operator uid, decision, optional normalized reason, correlation id — and
 * deliberately ignores any argument- or credential-shaped members an event
 * might carry.
 *
 * @api
 */
final class McpApprovalDecisionAuditListener
{
    /** Canonical event name dispatched by the approval decision controller. */
    public const EVENT_NAME = 'waaseyaa.mcp.approval_decision';

    public function __construct(
        private readonly AuditWriterInterface $writer,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param object $event Expects properties: requestId (string), operatorUid (int),
     *                      approved (bool), reason (?string), correlationId (string).
     */
    public function onApprovalDecision(object $event): void
    {
        try {
            $requestId = property_exists($event, 'requestId') ? (string) $event->requestId : 'unknown';
            $operatorUid = property_exists($event, 'operatorUid') && \is_int($event->operatorUid)
                ? $event->operatorUid
                : null;
            $approved = property_exists($event, 'approved') && $event->approved === true;
            $reason = property_exists($event, 'reason') && \is_string($event->reason) && $event->reason !== ''
                ? $event->reason
                : null;

            $attributes = [
                'request_id' => $requestId,
                'decision' => $approved ? 'approved' : 'denied',
            ];
            if ($reason !== null) {
                $attributes['reason'] = $reason;
            }
            if (property_exists($event, 'correlationId') && \is_string($event->correlationId) && $event->correlationId !== '') {
                $attributes['correlation_id'] = $event->correlationId;
            }

            $this->writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::McpApprovalDecision,
                accountUid: $operatorUid,
                subjectUri: sprintf('/api/mcp/approvals/%s/decision', $requestId),
                outcome: $approved ? 'allowed' : 'denied',
                severity: 'notice',
                attributes: $attributes,
            ));
        } catch (\Throwable $e) {
            // Safe metadata only — the exception message may carry driver
            // detail (DSNs, credentials) and never reaches the log.
            ($this->logger ?? new NullLogger())->warning('audit.listener_failed', [
                'listener' => self::class,
                'exception_class' => $e::class,
                'kind' => AuditEventKind::McpApprovalDecision->value,
            ]);
        }
    }
}
