<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintAppliedEvidence', 'disposition' => 'public', 'purpose' => 'Closed generated-metadata evidence that a digest-bound approved application blueprint was applied', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintCheckKind', 'disposition' => 'public', 'ref' => '#2785'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintConditionKind', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintDecision', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintFieldType', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintLifecycle', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintOnDelete', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintOperation', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Blueprint\\BlueprintStorage', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Capability\\CapabilityState', 'disposition' => 'public', 'purpose' => 'Closed active, planned, and not-needed vocabulary serialized by the provider-neutral site manifest', 'ref' => '#2343'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Doctor\\FindingSeverity', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\ArtifactApplyOutcome', 'disposition' => 'public', 'purpose' => 'Closed planned/applied/no_changes/cancelled/refused apply outcome', 'ref' => '#2846'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\ArtifactSetEvolution', 'disposition' => 'public', 'purpose' => 'Closed frozen/additive declaration of whether a compiler may render a superset of its unit\'s recorded path set', 'ref' => '#2846'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\ArtifactStatus', 'disposition' => 'public', 'purpose' => 'Closed created/changed/unchanged/refused per-path evaluation outcome'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\ChangeOutcome', 'disposition' => 'public', 'purpose' => 'Closed applied/no_op/refused/failed/recovered governed-change receipt outcome'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\Exception\\GenerationErrorCode', 'disposition' => 'public', 'purpose' => 'Closed GEN001-GEN015 refusal ids for the generation execution and plan boundary, reserved by ADR-025 D-5', 'ref' => '#2846'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\GenerationUnitDisposition', 'disposition' => 'public', 'purpose' => 'Closed managed/seeded vocabulary for how a generation unit\'s artifacts are treated after publication'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\GeneratorFeatureNegotiation', 'disposition' => 'public', 'purpose' => 'Fail-closed generator-feature negotiation refusing an unadvertised required token before any render, lock, journal or write', 'ref' => '#2787'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\ObservedTargetMode', 'disposition' => 'public', 'purpose' => 'Closed 0644/0755/other/unknown record of the permission bits evaluation observed'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\ObservedTargetState', 'disposition' => 'public', 'purpose' => 'Closed absent/file/other record of what evaluation observed at one target path'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Generation\\SiteRecipeRendererInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\ManifestShapeReader', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\SiteContract\\Version\\ManifestVersionDisposition', 'disposition' => 'public', 'purpose' => 'Closed current, migration-required, and unsupported-future schema-version decision'],
    ],
];
