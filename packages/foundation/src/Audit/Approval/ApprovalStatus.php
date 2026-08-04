<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * The honestly-derived state of an approval request (#2177 F1).
 *
 * Storage is append-only events (`requested` / `decided` / `consumed`); status
 * is never a mutable column. {@see OperationApprovalStoreInterface::find()}
 * derives it from the events plus the clock, so a stale row can never claim to
 * be actionable: an undecided request past its expiry reads `Expired`, and so
 * does an approved-but-unconsumed one — an approval that was never used inside
 * its window is spent, not latent.
 *
 * @api
 */
enum ApprovalStatus: string
{
    /** Requested, undecided, and still inside its expiry window. */
    case Pending = 'pending';

    /** Approved by an operator and still consumable (unexpired, unconsumed). */
    case Approved = 'approved';

    /** Denied by an operator. Terminal. */
    case Denied = 'denied';

    /** An approved request was consumed by exactly one retry. Terminal. */
    case Consumed = 'consumed';

    /** The expiry window passed before a decision or before consumption. Terminal. */
    case Expired = 'expired';
}
