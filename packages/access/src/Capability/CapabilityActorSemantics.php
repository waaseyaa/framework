<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/** @api */
enum CapabilityActorSemantics: string
{
    case Account = 'account';
    case Anonymous = 'anonymous';
    case System = 'system';
    case NoActingContext = 'no_acting_context';
}
