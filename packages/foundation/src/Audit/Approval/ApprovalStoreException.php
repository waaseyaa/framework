<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * Raised when the approval store cannot answer or record durably, or when a
 * request is not in a state the operation is defined for (#2177 F1).
 *
 * Like {@see \Waaseyaa\Foundation\Audit\StrictAuditLedgerException}, receiving
 * this means the caller MUST NOT proceed with the guarded side effect. The
 * message is operator-facing and sanitized — it never carries driver detail,
 * SQL, or argument content; the underlying cause travels as `$previous` for
 * operator logs only.
 *
 * @api
 */
class ApprovalStoreException extends \RuntimeException {}
