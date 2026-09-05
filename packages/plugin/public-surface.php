<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Plugin\\Discovery\\PluginDiscoveryInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Plugin\\Extension\\KnowledgeToolingExtensionInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Plugin\\Factory\\PluginFactoryInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Plugin\\PluginBase', 'disposition' => 'public', 'purpose' => 'Base implementation of `PluginInspectionInterface` for all plugin types'],
        ['fqcn' => 'Waaseyaa\\Plugin\\PluginInspectionInterface', 'disposition' => 'public', 'purpose' => 'Provides read access to a plugin\'s ID and definition'],
        ['fqcn' => 'Waaseyaa\\Plugin\\PluginManagerInterface', 'disposition' => 'public', 'purpose' => 'Discovers, retrieves, and instantiates plugins by ID'],
    ],
];
