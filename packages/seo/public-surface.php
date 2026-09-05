<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Seo\\Discovery\\CrawlEligibilityPolicyInterface', 'disposition' => 'public', 'purpose' => 'Application-owned restriction of which entity types may be crawled'],
        ['fqcn' => 'Waaseyaa\\Seo\\Discovery\\DiscoveryFailurePolicy', 'disposition' => 'public', 'purpose' => 'Selects empty-document degradation or propagation for a failed crawler surface'],
        ['fqcn' => 'Waaseyaa\\Seo\\Discovery\\Exception\\DiscoveryConfigurationException', 'disposition' => 'public', 'purpose' => 'Canonical URL policy is bound but no trusted public origin is configured'],
        ['fqcn' => 'Waaseyaa\\Seo\\Discovery\\NonPublicEntityTypes', 'disposition' => 'public', 'purpose' => 'Framework-owned floor of entity types that are never crawled; applications may narrow it, never widen it'],
        ['fqcn' => 'Waaseyaa\\Seo\\Discovery\\PublicUrlPolicyInterface', 'disposition' => 'public', 'purpose' => 'Application-owned canonical and Markdown-representation paths for the crawler-facing surfaces', 'ref' => '#2501'],
        ['fqcn' => 'Waaseyaa\\Seo\\Discovery\\SitemapContributorInterface', 'disposition' => 'public', 'purpose' => 'Contributes non-entity sitemap URLs without replacing the SEO controller'],
        ['fqcn' => 'Waaseyaa\\Seo\\Discovery\\SitemapPath', 'disposition' => 'public', 'purpose' => 'Validated root-relative sitemap entry returned by a contributor'],
    ],
];
