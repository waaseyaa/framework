<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Field\\AbstractFieldType', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\Classification\\ClassificationClearanceCheckerInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\Classification\\ClassificationLabelRegistryInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\Classification\\ClassificationParentResolverInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldDefinitionInterface', 'disposition' => 'public', 'purpose' => 'Describes a field: type, label, cardinality, settings, and constraints'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldFormatterInterface', 'disposition' => 'public', 'purpose' => 'Plugin interface for rendering a field item list for display'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldReadDefinitionInterface', 'disposition' => 'public', 'purpose' => 'Additive companion exposing nullable read classification without changing third-party `FieldDefinitionInterface` implementations'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldReadMetadataSource', 'disposition' => 'public', 'purpose' => 'Records whether classification came from a definition, legacy internal setting, site artifact, or remains unclassified'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldStorage', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldStorageSchemaContext', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldTypeInterface', 'disposition' => 'public', 'purpose' => 'Plugin interface for field type implementations providing column and property schemas'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldTypeManagerInterface', 'disposition' => 'public', 'purpose' => 'Discovers field type plugins and provides their default settings and column definitions'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldValueKind', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldValueKindProviderInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\FieldValueKindResolverInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\Item\\LabeledCase', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Field\\ViewModeConfigInterface', 'disposition' => 'public', 'purpose' => 'Configures which fields and formatters are active for a given view mode'],
    ],
    'notes' => [
        '`FieldItemInterface` (interface): A single typed value within a field list, with property accessors and emptiness check',
        '`FieldItemListInterface` (interface): An ordered list of `FieldItemInterface` values for one field on one entity',
        '`FieldDefinition::translatable(bool $translatable = true): self` (builder method): Marks a field as translatable (per-langcode value). Calling on a non-translatable `EntityType`\'s field fails at boot (M-006, WP03)',
        '`FieldDefinition::isTranslatable(): bool` (reader): Returns whether the field carries per-language values (M-006, WP03)',
        '`FieldItemBase` (abstract class): Base field item implementation combining plugin and typed-data behavior',
    ],
];
