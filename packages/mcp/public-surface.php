<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Mcp\\Admin\\RecentInvocationsQueryInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Mcp\\Auth\\McpAuthInterface', 'disposition' => 'public', 'purpose' => 'Authenticates MCP requests and resolves the immutable acting authorization principal'],
        ['fqcn' => 'Waaseyaa\\Mcp\\Auth\\OAuthAccessTokenValidatorInterface', 'disposition' => 'public', 'purpose' => 'Validates OAuth access tokens for one exact MCP resource and returns an active scoped principal'],
        ['fqcn' => 'Waaseyaa\\Mcp\\Auth\\ScopedMcpAuthInterface', 'disposition' => 'public', 'ref' => '#2177'],
        ['fqcn' => 'Waaseyaa\\Mcp\\Auth\\WriteTierAuthInterface', 'disposition' => 'public'],
    ],
    'notes' => [
        '`ToolExecutorInterface` (interface): Executes an MCP tool call by name with arguments and returns structured content',
        '`ToolRegistryInterface` (interface): Provides the full list of MCP tool definitions for the protocol manifest',
    ],
];
