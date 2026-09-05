<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\TypedData\\Coercion\\CoercionException', 'disposition' => 'public', 'purpose' => 'Thrown when entity-parity primitive/JSON-array coercion fails (#1185)'],
        ['fqcn' => 'Waaseyaa\\TypedData\\Coercion\\EntityCastCoercion', 'disposition' => 'public', 'purpose' => 'Storage ↔ domain coercion for `int`/`float`/`bool`/`string`/`array` casts (#1185)'],
        ['fqcn' => 'Waaseyaa\\TypedData\\DataDefinitionInterface', 'disposition' => 'public', 'purpose' => 'Describes a typed data property: type, label, required, read-only, constraints (extended by `FieldDefinitionInterface`)', 'ref' => 'audit C-24'],
    ],
];
