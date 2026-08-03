<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * Durable, append-only store of human approvals for destructive operations
 * (#2177 F1).
 *
 * The port lives in foundation for the same reason as
 * {@see \Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface}: its consumer is
 * the MCP write tier, which must not require `waaseyaa/audit` at runtime. The
 * database implementation is `Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore`.
 *
 * ## Lifecycle
 *
 * `open()` → operator `decide()` → exactly one `consume()` by the retried
 * call, all inside a fixed expiry window stamped at `open()` time. State is
 * derived from the append-only events, never stored mutably — see
 * {@see ApprovalStatus}. Once-only decision and consumption are enforced at
 * the storage layer (`UNIQUE(request_id, event_type)`), not merely in
 * application code.
 *
 * **This interface is deliberately NOT best-effort.** Every method throws
 * {@see ApprovalStoreException} when it cannot read or append durably; a
 * caller receiving it must refuse the guarded side effect.
 *
 * @api
 */
interface OperationApprovalStoreInterface
{
    /**
     * Durably open an approval request for the given tuple, or return the
     * existing one a retry should wait on.
     *
     * An unexpired, undecided pending request with an identical
     * {@see ApprovalTuple::$requestKey} is reused, so a polling retry does not
     * fan out into duplicate requests. Reuse is best-effort under concurrency:
     * two simultaneous first calls may each open a request (append-only
     * storage has nothing to lock), which is harmless — each is independently
     * decidable and each approval still consumes exactly once.
     *
     * @param string               $correlationId the original request's correlation id
     * @param array<string, mixed> $safeArguments the tool's redacted arguments — NEVER raw params
     *
     * @throws ApprovalStoreException when the request cannot be made durable
     */
    public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest;

    /**
     * Load one request by its exact opaque id, deriving its honest current
     * status, or null when no such request exists.
     *
     * @throws ApprovalStoreException when the store cannot be read
     */
    public function find(string $requestId): ?ApprovalRequest;

    /**
     * Durably record a human decision on a pending request.
     *
     * `$operatorUid` is the server-derived uid of the deciding operator —
     * derived from the authenticated session by the calling controller, never
     * accepted from request payload. It must identify a real principal
     * (positive uid).
     *
     * `$reason` is the operator's optional human explanation, persisted on the
     * `decided` event as durable incident evidence. It is normalized through
     * {@see ApprovalRequest::normalizeDecisionReason()} — trimmed, blank
     * becomes null, at most {@see ApprovalRequest::MAX_DECISION_REASON_LENGTH}
     * Unicode characters, single-line (no control characters) — and an invalid
     * reason is rejected before anything is appended.
     *
     * @throws \InvalidArgumentException       when the operator uid or reason is invalid
     * @throws ApprovalAlreadyDecidedException when the request already carries a decision
     * @throws ApprovalStoreException          when the request is unknown or expired,
     *                                         or the decision cannot be made durable
     */
    public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest;

    /**
     * Atomically consume an approved, unexpired request for exactly one
     * execution of the approved operation.
     *
     * Runs transactionally against the store's own connection: the state check
     * and the `consumed` append commit together, and the storage-level unique
     * index makes a second consumption impossible even under a race. Expiry is
     * re-checked at this boundary with the same inclusive instant comparison
     * used everywhere ({@see ApprovalRequest::isExpiredAt()}) — there is no
     * sub-second window in which an expired approval still consumes.
     *
     * Returns false — rather than throwing — when the request is not
     * consumable: unknown, pending, denied, expired, already consumed, or lost
     * to a concurrent consumer. False always means "the operation must not
     * run"; the caller re-`find()`s if it needs to know why.
     *
     * @param string $receiptId          the strict-ledger receipt of the consuming
     *                                   execution's reservation, joining the approval
     *                                   to its `strict_audit_ledger` evidence
     * @param string $retryCorrelationId the consuming (retried) request's correlation id —
     *                                   the original's is already on the `requested` event
     *
     * @throws ApprovalStoreException when the consumption outcome cannot be
     *                                determined or made durable
     */
    public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool;
}
