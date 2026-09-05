<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Testing\\Clock\\MutableEntityClock', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Database\\TemporarySqliteDatabase', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Factory\\AuthorizationPrincipalFactory', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Factory\\EntityFactory', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Factory\\EntityTypeFactory', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Factory\\EntityTypeFixtureValues', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Filesystem\\TemporaryDirectory', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Kernel\\KernelServicesFixture', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Testing\\Traits\\CreatesApplication', 'disposition' => 'public', 'purpose' => 'Bootstraps a Waaseyaa application instance for test suites'],
        ['fqcn' => 'Waaseyaa\\Testing\\Traits\\InteractsWithApi', 'disposition' => 'public', 'purpose' => 'HTTP request helpers for making API calls in tests'],
        ['fqcn' => 'Waaseyaa\\Testing\\Traits\\InteractsWithAuth', 'disposition' => 'public', 'purpose' => 'Simulates acting as a specific user without a full auth subsystem'],
        ['fqcn' => 'Waaseyaa\\Testing\\Traits\\InteractsWithEvents', 'disposition' => 'public', 'purpose' => 'Captures and asserts on dispatched domain events in tests'],
        ['fqcn' => 'Waaseyaa\\Testing\\Traits\\RefreshDatabase', 'disposition' => 'public', 'purpose' => 'Wraps each test in a transaction and rolls back after, keeping the database clean'],
        ['fqcn' => 'Waaseyaa\\Testing\\WaaseyaaTestCase', 'disposition' => 'internal'],
    ],
];
