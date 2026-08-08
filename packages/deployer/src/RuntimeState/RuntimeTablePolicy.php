<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\RuntimeState;

/** @api */
enum RuntimeTablePolicy: string
{
    case Artifact = 'artifact';
    case Preserve = 'preserve';
    case AppendOnly = 'append_only';
    case IdentityMerge = 'identity_merge';
}
