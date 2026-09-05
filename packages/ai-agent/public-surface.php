<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Account\\InitiatorAccountLoaderInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\AgentDefinition', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\AgentDefinitionRegistry', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Attribute\\AsAgentDefinition', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Broadcast\\AgentRunBroadcasterInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Enum\\EventType', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Enum\\HitlMode', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Enum\\RunStatus', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorAccountContextGuard', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorPrincipal', 'disposition' => 'public', 'ref' => '#2658'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorRefusal', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorToolProfile', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorTransportAttestation', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Provider\\ProviderException', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Provider\\ProviderInterface', 'disposition' => 'public', 'purpose' => 'AI model provider: sends messages and returns a structured response'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Provider\\StreamingProviderInterface', 'disposition' => 'public', 'purpose' => 'Provider variant that streams partial response chunks as they arrive'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Security\\AgentRunAccountProjectionReaderInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Security\\AgentRunWorkerReaderInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AI\\Agent\\Tool\\Wayfinding\\AbstractTrailTool', 'disposition' => 'internal'],
    ],
    'notes' => [
        '`ToolRegistryInterface` (interface): Provides the set of tools available to an AI agent',
    ],
];
