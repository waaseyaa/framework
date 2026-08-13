<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Capability;

/** @api */
enum CapabilityState: string
{
    case Active = 'active';
    case Planned = 'planned';
    case NotNeeded = 'not_needed';
}
