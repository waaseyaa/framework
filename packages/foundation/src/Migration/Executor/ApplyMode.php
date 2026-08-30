<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

/**
 * How a v2 node reached its applied state.
 *
 * Recorded in the ledger's nullable `apply_mode` column. It is audit evidence,
 * never identity: `checksum` and `diff_hash` are identical in both cases, so
 * replay guarding and verification are unaffected.
 *
 * @see docs/change-records/FW-2701.md — C3 already satisfied
 */
enum ApplyMode: string
{
    /** At least one operation issued SQL. */
    case Applied = 'applied';

    /** Every operation was already exactly satisfied; no SQL was issued. */
    case AlreadySatisfied = 'already_satisfied';
}
