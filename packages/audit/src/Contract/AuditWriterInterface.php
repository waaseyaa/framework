<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

/**
 * Write-side contract for the OCAP audit log.
 *
 * Implementations MUST be best-effort: {@see record()} must swallow all
 * internal exceptions and log via LoggerInterface. It MUST NOT throw (FR-005,
 * NFR-001). The caller's request lifecycle must never be disrupted by an
 * audit-write failure.
 *
 * @api
 */
interface AuditWriterInterface
{
    /**
     * Record an audit event. Best-effort — never throws.
     */
    public function record(AuditEventDescriptor $descriptor): void;
}
