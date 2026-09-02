<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

/** Explicit compatibility context for direct SQL-column projections. */
enum FieldStorageSchemaContext: string
{
    /** Former SqlSchemaHandler/base-bundle derivation path. */
    case BaseTable = 'base-table';

    /** Former ColumnSpecMap primary/revision/translation paths. */
    case ColumnSpecMap = 'column-spec-map';
}
