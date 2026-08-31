<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

/**
 * Whether one authored operation still has work to do against the live database.
 *
 * There is no third "skip" case on purpose. An operation is either genuinely
 * outstanding or exactly satisfied; anything else throws
 * {@see IncompatibleSchemaStateException}.
 *
 * @see docs/change-records/FW-2701.md — C3, C4
 * @internal
 */
enum OpPrecondition
{
    case NeedsApply;
    case AlreadySatisfied;
}
