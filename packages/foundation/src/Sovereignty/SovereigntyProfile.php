<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Sovereignty;

enum SovereigntyProfile: string
{
    case Local = 'local';
    case SelfHosted = 'self_hosted';
    case NorthOps = 'northops';
}
