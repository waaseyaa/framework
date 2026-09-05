<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Api\\Audit\\AuditQueryReadModelInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Api\\ContentSearch\\ContentSearchRateLimiterInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Api\\ContentSearch\\ContentSearchReadModelInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Api\\McpAdmin\\ServerConfigReadModelInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Api\\McpAdmin\\ToolRegistryReadModelInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Api\\Media\\MediaVersionReadModelInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Api\\MercureMonitor\\ChannelInspectorInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Api\\MercureMonitor\\EventStreamReadModelInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Api\\MercureMonitor\\SubscriberObserverInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Api\\MutableTranslatableInterface', 'disposition' => 'public', 'purpose' => 'Extends `TranslatableInterface` with `addTranslation()` for explicit translation creation'],
    ],
];
