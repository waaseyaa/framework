<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/** @api */
enum MappingDecision: string
{
    case Create = 'create';
    case Update = 'update';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
}
