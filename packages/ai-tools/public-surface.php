<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\AbstractAgentTool', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\AgentTool', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\AgentToolInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\AgentToolResult', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\Attribute\\AsAgentTool', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\Content\\AssetStoreInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\Dispatch\\ToolDispatcherInterface', 'disposition' => 'public', 'ref' => '#2657'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\ProvidesAgentToolsInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\Resource\\ContentResourceProviderInterface', 'disposition' => 'public', 'purpose' => 'Contributes bounded principal-explicit content resources without coupling MCP to content packages'],
        ['fqcn' => 'Waaseyaa\\AI\\Tools\\ToolRegistryInterface', 'disposition' => 'public'],
    ],
];
