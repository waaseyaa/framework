<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Migration\\ContentModel\\DerivesContentModelInterface', 'disposition' => 'public', 'ref' => '#1940'],
        ['fqcn' => 'Waaseyaa\\Migration\\Discovery\\HasMigrationsInterface', 'disposition' => 'public', 'purpose' => 'Marker for service providers contributing migration manifests (FR-003, WP02)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\DestinationWriteException', 'disposition' => 'public', 'purpose' => 'Destination plugin failed to write; carries `$reason` code (WP05)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\MigrationAbortedException', 'disposition' => 'public', 'purpose' => 'Operator-triggered abort surfaced from runner / signal handler (WP06)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\MigrationConcurrencyException', 'disposition' => 'public', 'purpose' => 'Per-migration lock contention; carries `holdingPid` + `lockPath` (FR-061, WP09)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\MigrationCycleException', 'disposition' => 'public', 'purpose' => 'Dependency-graph cycle detected during discovery (WP02)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\MigrationDependencyMissingException', 'disposition' => 'public', 'purpose' => 'Migration depends on an unknown id (WP02)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\MigrationPluginCollisionException', 'disposition' => 'public', 'purpose' => 'Two plugins claim the same id; reserved-id collisions set `$isReserved=true` (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\ProcessException', 'disposition' => 'public', 'purpose' => 'Process plugin raised during per-field transformation (WP03)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Exception\\SourceReadException', 'disposition' => 'public', 'purpose' => 'Source plugin failed to read a record / opened-file errors (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Log\\Channels', 'disposition' => 'public', 'purpose' => 'Logger channel constants: `MIGRATION_DEPRECATION`, `MIGRATION_DISCOVERY` (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\MigrationDefinition', 'disposition' => 'public', 'purpose' => 'Immutable migration definition: id, source, processors, destination, dependencies, stability (WP02)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\DestinationPluginInterface', 'disposition' => 'public', 'purpose' => 'Destination plugin SPI: `write`, `rollback`, `lookup` per source id (FR-006, WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\DestinationRecord', 'disposition' => 'public', 'purpose' => 'DTO carrying processed fields to a destination plugin: `entityType`, `bundle`, `fields`, `sourceId`, `sourceRecordHash` (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Destination\\EntityDestination', 'disposition' => 'public', 'purpose' => 'Built-in destination writing to Waaseyaa entities via `EntityRepository` (FR-018..FR-029, WP05/WP08)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Destination\\EntityDestinationFactory', 'disposition' => 'public', 'purpose' => 'Constructs `EntityDestination` instances bound to a migration id (WP05)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\ProcessContext', 'disposition' => 'public', 'purpose' => 'Per-field processor context carrying current record + run metadata (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\ProcessPluginInterface', 'disposition' => 'public', 'purpose' => 'Per-field record transformer SPI (FR-005, WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Process\\ConcatProcessor', 'disposition' => 'public', 'purpose' => 'Reference processor: concatenates multiple source fields (WP03)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Process\\DefaultValueProcessor', 'disposition' => 'public', 'purpose' => 'Reference processor: substitutes a default when the input is null/empty (WP03)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Process\\HtmlSanitizeProcessor', 'disposition' => 'public', 'purpose' => 'Reference processor: sanitises HTML field values (WP03)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Process\\LookupProcessor', 'disposition' => 'public', 'purpose' => 'Reference processor: resolves cross-migration lookups via `MigrationIdMap` (FR-028, WP03)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Process\\PassThroughProcessor', 'disposition' => 'public', 'purpose' => 'Reference processor: emits the input value unchanged (WP03)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\Process\\TypeCoerceProcessor', 'disposition' => 'public', 'purpose' => 'Reference processor: coerces strings to int/float/bool (WP03)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\ReservedPluginIds', 'disposition' => 'public', 'purpose' => 'Constants for framework-reserved plugin ids; collision raises `MigrationPluginCollisionException` (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\SourcePluginInterface', 'disposition' => 'public', 'purpose' => 'Source plugin SPI: streams `SourceRecord` instances and assigns `SourceId`s (FR-049, WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\SourceRecord', 'disposition' => 'public', 'purpose' => 'DTO carrying a raw row from a source plugin: `sourceType`, `fields` (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Plugin\\WriteResult', 'disposition' => 'public', 'purpose' => 'Destination write outcome: `destinationEntityType`, `destinationUuid`, `sourceRecordHash`, `runId`, `writtenAt` (FR-006, WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Schema\\MigrationIdMapSchema', 'disposition' => 'public', 'purpose' => 'DDL builder for the `migration_id_map` table (FR-029, WP04)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Security\\MigrationAuditedFieldReader', 'disposition' => 'public', 'purpose' => 'Explicit `AuditedFieldRead::read` call site supplied to migration code'],
        ['fqcn' => 'Waaseyaa\\Migration\\Security\\MigrationFieldReadCapabilityIssuer', 'disposition' => 'public', 'purpose' => 'Issues NoActingContext MigrationImport capabilities from manifests'],
        ['fqcn' => 'Waaseyaa\\Migration\\Security\\MigrationFieldReadManifest', 'disposition' => 'public', 'purpose' => 'Exact privileged field reads reviewed for one migration id'],
        ['fqcn' => 'Waaseyaa\\Migration\\SourceId', 'disposition' => 'public', 'purpose' => 'Stable composite key identifying a source record across re-runs (WP01)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Testing\\DestinationConformanceTestCase', 'disposition' => 'public', 'purpose' => 'Conformance harness third-party destination plugins extend (FR-050/FR-051, WP10; autoload-dev)'],
        ['fqcn' => 'Waaseyaa\\Migration\\Testing\\SourceConformanceTestCase', 'disposition' => 'public', 'purpose' => 'Conformance harness third-party source plugins extend (FR-052, WP10; autoload-dev)'],
    ],
];
