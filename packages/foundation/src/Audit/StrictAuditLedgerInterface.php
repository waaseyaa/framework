<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit;

/**
 * A fail-closed reserve/finalize audit ledger.
 *
 * Modelled on `Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface`,
 * which established this pattern for privileged reads. The port lives in
 * foundation (layer 0) rather than in `waaseyaa/audit` because its first
 * consumer — the MCP write tier — must not require `waaseyaa/audit` at runtime
 * (see `Waaseyaa\Mcp\Event\McpDispatchEvent`, contract clause 18). Foundation is
 * the one package both the consumer and the implementation already depend on.
 *
 * **This interface is deliberately NOT best-effort.** It is the opposite of
 * {@see \Waaseyaa\Audit\Contract\AuditWriterInterface}, whose contract requires
 * swallowing every exception. Here, `reserve()` and `finalize()` MUST throw
 * {@see StrictAuditLedgerException} when they cannot make a record durable, so
 * a caller can refuse to proceed. An implementation that swallows failures
 * silently defeats the entire point.
 *
 * ## The guarantee, and its exact limit
 *
 * `reserve()` must be durable **before** the caller performs the side effect.
 * That yields: *no side effect can occur without a durable record of the
 * attempt.*
 *
 * It does **not** yield atomicity between the side effect and `finalize()`.
 * The two are separate commits, and in the general case they cannot be joined:
 * the operation being audited owns its own transaction boundary and commits
 * internally, `DBALTransaction` has no savepoint support (so a nested domain
 * rollback would poison an enclosing audit write), and the operation's storage
 * may resolve to a different connection entirely.
 *
 * A crash between the side effect and `finalize()` therefore leaves a
 * **dangling reservation** — a reserved record with no finalized record. That
 * state is detectable by query and MUST be treated as "outcome unknown, side
 * effect may have happened". It must never trigger a blind retry or rollback:
 * the side effect already occurred, and repeating it would duplicate it.
 *
 * @api
 */
interface StrictAuditLedgerInterface
{
    /**
     * Durably record an intent before it is acted on.
     *
     * @throws StrictAuditLedgerException when the record cannot be made durable.
     *         The caller MUST NOT proceed with the side effect.
     */
    public function reserve(StrictAuditReservation $reservation): StrictAuditReceipt;

    /**
     * Durably record the real outcome of a reserved intent.
     *
     * Implementations must reject finalizing a receipt that was never reserved
     * or has already been finalized — a double-finalize is a programming error,
     * not a retry.
     *
     * @param array<string, mixed> $metadata Additional safe metadata. Never exception detail.
     *
     * @throws StrictAuditLedgerException when the outcome cannot be made durable.
     */
    public function finalize(StrictAuditReceipt $receipt, AuditStage $stage, array $metadata = []): void;

    /**
     * Durably record a single-shot terminal stage that never reaches execution —
     * an authentication rejection, a rate-limit refusal, a tool-lookup refusal.
     *
     * There is nothing to finalize because nothing will be attempted, so this is
     * one record rather than a pair.
     *
     * @throws StrictAuditLedgerException when the record cannot be made durable.
     */
    public function record(StrictAuditReservation $reservation, AuditStage $stage): void;
}
