<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\User\\Authentication\\AuthenticationEligibilityInterface', 'disposition' => 'public', 'ref' => '#2757'],
        ['fqcn' => 'Waaseyaa\\User\\Authentication\\AuthenticationStage', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\User\\RegisteredRoleAssignment', 'disposition' => 'public', 'purpose' => 'Canonical registered-role membership and flattened permission union'],
        ['fqcn' => 'Waaseyaa\\User\\RegisteredRoleAssignmentService', 'disposition' => 'public', 'purpose' => 'Shared registered-role replacement/removal and permission-union authority', 'ref' => '#3046'],
    ],
];
