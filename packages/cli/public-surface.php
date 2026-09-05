<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\CLI\\AdminBuild\\AdminBuildPlatform', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\AdminBuild\\AdminBuildProcessResult', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\AdminBuild\\AdminBuildProcessRunnerInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigDiffCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigExportCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigImportCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigManifestSignCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigResetCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigStatusCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Config\\ConfigValidateCommand', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\HandlerArgumentMode', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\HandlerOptionMode', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Make\\AbstractMakeHandler', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Migration\\BackfillHelper', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Migration\\BackfillRowCountMismatchException', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Migration\\StorageMigrationEmitter', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Migration\\StorageMigrationTemplate', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Command\\Migration\\UnmappedFieldTypeException', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Handler\\MakeStorageMigrationHandler', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Handler\\MutationAuthorityBackfillHandler', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Io\\StdinSource', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Provider\\MakeStorageMigrationServiceProvider', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\CLI\\Scaffold\\CliInstallPathResolverInterface', 'disposition' => 'internal', 'ref' => '#2833'],
        ['fqcn' => 'Waaseyaa\\CLI\\Security\\CliFieldReadCapabilityDeclaration', 'disposition' => 'public', 'purpose' => 'Exact command scope and closed CLI-valid privileged-read reason'],
        ['fqcn' => 'Waaseyaa\\CLI\\Security\\CliFieldReadCapabilityIssuer', 'disposition' => 'public', 'purpose' => 'Issues null-actor NoActingContext capabilities from command metadata'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\SiteHostPlatform', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\SitePathContainment', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\SitePreset', 'disposition' => 'internal', 'ref' => '#2442'],
    ],
];
