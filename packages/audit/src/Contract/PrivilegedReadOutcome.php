<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

/** @api */
enum PrivilegedReadOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
