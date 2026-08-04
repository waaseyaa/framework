<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched AFTER a human operator's approval decision has been made durable
 * in `mcp_approval_event` (#2177 F1 C1b).
 *
 * The best-effort audit projection tier subscribes by the string event name
 * ({@see \Waaseyaa\Audit\Listener\McpApprovalDecisionAuditListener} duck-reads
 * the public properties, so the audit package never imports this class).
 * Carries ONLY safe join fields — never raw call arguments, credentials, or
 * store internals.
 *
 * @api the public properties are the cross-package projection contract,
 *      consumed reflectively (duck-typed) by the audit listener
 */
final class McpApprovalDecisionRecorded extends Event
{
    /** Canonical event name; the audit listener subscribes to this string. */
    public const EVENT_NAME = 'waaseyaa.mcp.approval_decision';

    /**
     * @param string  $requestId     the durable approval request id (`apr_…`)
     * @param int     $operatorUid   the server-derived uid of the deciding operator
     * @param bool    $approved      true for `approve`, false for `deny`
     * @param ?string $reason        the operator's optional normalized reason
     * @param string  $correlationId the original request's correlation id
     */
    public function __construct(
        public readonly string $requestId,
        public readonly int $operatorUid,
        public readonly bool $approved,
        public readonly ?string $reason,
        public readonly string $correlationId,
    ) {}
}
