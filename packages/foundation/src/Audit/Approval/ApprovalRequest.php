<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * Read model of one durable approval request (#2177 F1).
 *
 * Assembled by {@see OperationApprovalStoreInterface::find()} from the
 * append-only event rows; never persisted as a row itself. Every field is safe
 * to expose to an operator UI: `$safeArguments` is the tool's own redacted
 * projection, and the raw arguments exist here only as the tuple's fingerprint.
 *
 * @api
 */
final readonly class ApprovalRequest
{
    /** Opaque request ids are `apr_` + 32 lowercase hex characters (16 random bytes). */
    private const string ID_PATTERN = '/^apr_[0-9a-f]{32}$/';

    /** Conservative ceiling for the single-line human decision reason. */
    public const int MAX_DECISION_REASON_LENGTH = 500;

    /**
     * @param array<string, mixed> $safeArguments  the tool's redacted arguments — never raw params
     * @param ?int                 $decidedByUid   server-derived operator uid; required for any
     *                                             decided status, null while pending/expired-undecided
     * @param ?string              $decisionReason optional operator-supplied reason, already in the
     *                                             {@see normalizeDecisionReason()} normal form; only
     *                                             ever present alongside a decision
     */
    public function __construct(
        public string $id,
        public ApprovalTuple $tuple,
        public ApprovalStatus $status,
        public string $correlationId,
        public array $safeArguments,
        public \DateTimeImmutable $requestedAt,
        public \DateTimeImmutable $expiresAt,
        public ?int $decidedByUid = null,
        public ?\DateTimeImmutable $decidedAt = null,
        public ?string $decisionReason = null,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(
                'An approval request id must be "apr_" followed by 32 lowercase hex characters.',
            );
        }
        if ($correlationId === '') {
            throw new \InvalidArgumentException('An approval request requires a correlation id.');
        }
        if ($expiresAt <= $requestedAt) {
            throw new \InvalidArgumentException('An approval request must expire after it was requested.');
        }

        $decided = \in_array($status, [ApprovalStatus::Approved, ApprovalStatus::Denied, ApprovalStatus::Consumed], true);
        if ($decided && ($decidedByUid === null || $decidedAt === null)) {
            throw new \InvalidArgumentException(
                sprintf('A%s approval request requires the deciding operator uid and decision time.', $status === ApprovalStatus::Approved ? 'n approved' : ' ' . $status->value),
            );
        }
        if ($decisionReason !== null) {
            if ($decidedAt === null) {
                throw new \InvalidArgumentException('A decision reason requires a decision to attach to.');
            }
            if (self::normalizeDecisionReason($decisionReason) !== $decisionReason) {
                throw new \InvalidArgumentException(
                    'A decision reason must already be in its normal form: trimmed and non-blank.',
                );
            }
        }
    }

    /**
     * Normalize an operator-supplied decision reason into its canonical form.
     *
     * Trims surrounding whitespace and collapses a blank reason to null — "no
     * reason given". A surviving reason is a single line of durable incident
     * evidence: at most {@see MAX_DECISION_REASON_LENGTH} Unicode characters,
     * with every ASCII control character (including CR, LF and tab) rejected
     * rather than stripped, so the stored value is exactly what the operator
     * wrote.
     *
     * @throws \InvalidArgumentException when the reason is too long or not single-line
     */
    public static function normalizeDecisionReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);
        if ($reason === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new \InvalidArgumentException(
                'A decision reason is a single line and cannot contain control characters.',
            );
        }
        if (mb_strlen($reason, 'UTF-8') > self::MAX_DECISION_REASON_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'A decision reason cannot exceed %d characters.',
                self::MAX_DECISION_REASON_LENGTH,
            ));
        }

        return $reason;
    }

    /**
     * Whether the request is expired at the given instant.
     *
     * Inclusive at the boundary (`now >= expiresAt`) and instant-based, so
     * there is no sub-second or timezone window in which an expired approval
     * still consumes. Every store-side expiry check MUST go through this one
     * comparison.
     */
    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
