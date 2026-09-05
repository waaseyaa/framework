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
        ['fqcn' => 'Waaseyaa\\CLI\\Security\\CliFieldReadCapabilityDeclaration', 'disposition' => 'public', 'purpose' => 'Exact command scope and closed CLI-valid privileged-read reason'],
        ['fqcn' => 'Waaseyaa\\CLI\\Security\\CliFieldReadCapabilityIssuer', 'disposition' => 'public', 'purpose' => 'Issues null-actor NoActingContext capabilities from command metadata'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler', 'disposition' => 'public', 'purpose' => 'Pure root compiler composing the manifest renderer with the blueprint emitter roster; unreachable from the CLI until the eligibility-gated 01D-2 slice', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompilerFactory', 'disposition' => 'public', 'purpose' => 'Single composition root for the blueprint compiler and its emitter roster', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\Blueprint\\Emitter\\BlueprintArtifactEmitterInterface', 'disposition' => 'public', 'purpose' => 'Pure emitter seam contributing artifacts, registrations, and companion tests to the blueprint compiler', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\Blueprint\\Emitter\\BlueprintEmission', 'disposition' => 'public', 'purpose' => 'Immutable output of one blueprint artifact emitter', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\Blueprint\\Emitter\\EntityClassEmitter', 'disposition' => 'public', 'purpose' => 'Emits one content entity class per blueprint entity', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\Blueprint\\Emitter\\ProviderRegistrationEmitter', 'disposition' => 'public', 'purpose' => 'Emits the generated application blueprint service provider and its Composer registration', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\Blueprint\\Emitter\\RelationshipEmitter', 'disposition' => 'public', 'purpose' => 'Emits the deterministic blueprint relationship registry consumed for referential integrity', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\SiteHostPlatform', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\SitePathContainment', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\CLI\\Site\\SitePreset', 'disposition' => 'internal', 'ref' => '#2442'],
    ],
];
