<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/** @api */
enum QueryFieldOperation: string
{
    case Predicate = 'predicate';
    case Sort = 'sort';
    case Aggregate = 'aggregate';
    case Count = 'count';
    case Exists = 'exists';
}
