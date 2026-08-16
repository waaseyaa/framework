<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Upgrade;

/**
 * Terminal result of the read-only upgrade compatibility preflight.
 *
 * @api
 */
enum UpgradePreflightDecision: string
{
    case Ready = 'ready';
    case Blocked = 'blocked';
    case Unsupported = 'unsupported';
    case Invalid = 'invalid';
}
