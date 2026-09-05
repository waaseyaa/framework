<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Database\\ConsistentReadDatabaseInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Database\\DatabaseIdentityProviderInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Database\\DatabaseInterface', 'disposition' => 'public', 'purpose' => 'Doctrine DBAL abstraction: query builder entry point for select, insert, update, delete'],
        ['fqcn' => 'Waaseyaa\\Database\\DeleteInterface', 'disposition' => 'public', 'purpose' => 'Fluent DELETE query builder with conditions'],
        ['fqcn' => 'Waaseyaa\\Database\\Exception\\TransactionCompletionException', 'disposition' => 'public', 'purpose' => 'Reports completion-effect failures after the database has committed'],
        ['fqcn' => 'Waaseyaa\\Database\\ForeignKeySchemaInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Database\\InsertInterface', 'disposition' => 'public', 'purpose' => 'Fluent INSERT query builder'],
        ['fqcn' => 'Waaseyaa\\Database\\SchemaInterface', 'disposition' => 'public', 'purpose' => 'DDL operations: create/alter/drop tables and columns'],
        ['fqcn' => 'Waaseyaa\\Database\\SelectInterface', 'disposition' => 'public', 'purpose' => 'Fluent SELECT query builder with conditions, joins, ordering, and pagination', 'ref' => '#1816'],
        ['fqcn' => 'Waaseyaa\\Database\\TransactionCompletionInterface', 'disposition' => 'public', 'purpose' => 'Defers registered callbacks through nested managed transactions to the outermost commit'],
        ['fqcn' => 'Waaseyaa\\Database\\TransactionInterface', 'disposition' => 'public', 'purpose' => 'Wraps database operations in a named transaction with commit/rollback'],
        ['fqcn' => 'Waaseyaa\\Database\\UpdateInterface', 'disposition' => 'public', 'purpose' => 'Fluent UPDATE query builder with conditions'],
    ],
];
