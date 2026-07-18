<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

/** @api */
enum PrivilegedReadKind: string
{
    case Value = 'value';
    case Query = 'query';
}
