<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit;

/**
 * Raised when a strict-audit record cannot be made durable.
 *
 * Receiving this means the caller MUST NOT perform (or, for finalize, MUST NOT
 * re-perform) the side effect. The message is operator-facing and is never
 * returned to a remote caller — surfaces sanitize it (see F6).
 *
 * @api
 */
final class StrictAuditLedgerException extends \RuntimeException {}
