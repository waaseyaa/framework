<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit;

/**
 * The stage of a request pipeline that a strict-audit record describes.
 *
 * Deliberately ordered as the pipeline runs, so a reader of the ledger can see
 * how far a request got. Every stage except {@see self::RequestAccepted} and
 * {@see self::ExecutionSucceeded} describes a refusal or a failure — the point
 * of the enum is that "something went wrong" is never flattened into a single
 * `allowed` outcome.
 *
 * `ApprovalRequired` / `ApprovalRefused` are declared but NOT yet emitted: the
 * human-approval gate is F1, tracked separately. They live here so the ledger's
 * stage vocabulary does not need a breaking change when F1 lands, and so a
 * reader of this enum can see where approval will sit in the order.
 *
 * @api
 */
enum AuditStage: string
{
    /** Credentials were absent, malformed, unknown, or belonged to an inactive principal. */
    case AuthenticationRejected = 'authentication_rejected';

    /** The principal authenticated but exceeded its request budget. */
    case RateLimited = 'rate_limited';

    /** The request authenticated, parsed, and was admitted for routing. */
    case RequestAccepted = 'request_accepted';

    /** No tool is visible under the requested name on this tier. */
    case ToolLookupRefused = 'tool_lookup_refused';

    /** Arguments did not satisfy the tool's declared input schema. */
    case InputValidationRefused = 'input_validation_refused';

    /** The tool refused the caller: capability or per-entity access denied. */
    case AuthorizationRefused = 'authorization_refused';

    /** Reserved for F1 — a destructive call is waiting on a human decision. */
    case ApprovalRequired = 'approval_required';

    /** Reserved for F1 — a human denied a destructive call. */
    case ApprovalRefused = 'approval_refused';

    /** The tool ran and reported success. */
    case ExecutionSucceeded = 'execution_succeeded';

    /** The tool ran and reported (or threw) a failure. */
    case ExecutionFailed = 'execution_failed';

    /**
     * The audit outcome this stage implies.
     *
     * Maps onto the existing `audit_event` outcome vocabulary
     * (`allowed` / `denied` / `error`) so the OCAP log keeps one shared
     * grammar rather than growing a second one.
     */
    public function outcome(): string
    {
        return match ($this) {
            self::RequestAccepted, self::ExecutionSucceeded => 'allowed',
            self::AuthenticationRejected,
            self::RateLimited,
            self::ToolLookupRefused,
            self::InputValidationRefused,
            self::AuthorizationRefused,
            self::ApprovalRequired,
            self::ApprovalRefused => 'denied',
            self::ExecutionFailed => 'error',
        };
    }

    /** The `audit_event` severity this stage implies. */
    public function severity(): string
    {
        return match ($this) {
            self::RequestAccepted, self::ExecutionSucceeded => 'info',
            self::ExecutionFailed => 'warning',
            default => 'notice',
        };
    }

    /** Whether this stage closes the request (nothing further will be recorded). */
    public function isTerminal(): bool
    {
        return $this !== self::RequestAccepted;
    }
}
