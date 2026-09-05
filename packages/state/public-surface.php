<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\State\\EntityPayloadBoundaryConfig', 'disposition' => 'public', 'purpose' => 'Explicit dormant/enforced state entity-payload write mode'],
        ['fqcn' => 'Waaseyaa\\State\\Exception\\EntityProjectionWriteForbidden', 'disposition' => 'public', 'purpose' => 'Activated state rejection before an entity graph can be retained'],
        ['fqcn' => 'Waaseyaa\\State\\ProjectionDeprecationDiagnostic', 'disposition' => 'public', 'purpose' => 'Deduplicated dormant entity-payload diagnostic wired into memory/SQL state writes'],
        ['fqcn' => 'Waaseyaa\\State\\PublicStateProjection', 'disposition' => 'public', 'purpose' => 'Identifier plus explicitly Public scalar/array values; grants no protected/internal authority'],
        ['fqcn' => 'Waaseyaa\\State\\StateInterface', 'disposition' => 'internal'],
    ],
];
