<?php

declare(strict_types=1);

namespace Waaseyaa\StructuredImport\Mapping;

/** @api */
enum MappingConflictCode: string
{
    case DuplicateSourceIdentity = 'duplicate_source_identity';
    case DuplicateTargetIdentity = 'duplicate_target_identity';
    case UnmappedSourceField = 'unmapped_source_field';
    case UniqueFieldConflict = 'unique_field_conflict';
}
