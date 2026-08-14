<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

/** @internal */
enum OccurrenceDispatchResult: string
{
    case Dispatched = 'dispatched';
    case AlreadyDispatched = 'already_dispatched';
    case Failed = 'failed';
}
