<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Search\\BatchSearchIndexerInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Search\\Projection\\EntitySearchDocumentId', 'disposition' => 'public', 'purpose' => 'Creates stable search document identifiers for projected entities'],
        ['fqcn' => 'Waaseyaa\\Search\\Projection\\EntitySearchProjectionRegistry', 'disposition' => 'public', 'purpose' => 'Selects the first application or framework projector that supports an entity'],
        ['fqcn' => 'Waaseyaa\\Search\\Projection\\EntitySearchProjectorInterface', 'disposition' => 'public', 'purpose' => 'Projects a normal entity (Node) into an indexable search document without an upward search dependency', 'ref' => '#2270'],
        ['fqcn' => 'Waaseyaa\\Search\\Projection\\NodeSearchProjector', 'disposition' => 'public', 'purpose' => 'Provides the framework default projection for public Node content'],
        ['fqcn' => 'Waaseyaa\\Search\\Projection\\SearchTextNormalizer', 'disposition' => 'public', 'purpose' => 'Converts CMS field values into inert plain searchable text'],
        ['fqcn' => 'Waaseyaa\\Search\\ProvidesEntitySearchProjectorsInterface', 'disposition' => 'public', 'purpose' => 'Contributes application entity search projectors ordered ahead of the built-in node default'],
        ['fqcn' => 'Waaseyaa\\Search\\ProvidesSearchSourceResolversInterface', 'disposition' => 'public', 'purpose' => 'Contributes exact-namespace resolvers for canonical non-entity search sources'],
        ['fqcn' => 'Waaseyaa\\Search\\SearchCandidateResolverInterface', 'disposition' => 'public', 'purpose' => 'Resolves an opaque index pointer to a canonical principal-safe projection'],
        ['fqcn' => 'Waaseyaa\\Search\\SearchContentCatalogueInterface', 'disposition' => 'public', 'purpose' => 'Lists and reads bounded canonical content projections under an explicit immutable principal'],
        ['fqcn' => 'Waaseyaa\\Search\\SearchIndexableInterface', 'disposition' => 'public', 'purpose' => 'Marks an entity as searchable and provides its document ID and text fields'],
        ['fqcn' => 'Waaseyaa\\Search\\SearchIndexerInterface', 'disposition' => 'public', 'purpose' => 'Adds, updates, and removes documents from the search index'],
        ['fqcn' => 'Waaseyaa\\Search\\SearchProviderInterface', 'disposition' => 'public', 'purpose' => 'Executes principal-scoped full-text search queries and returns safe ranked results'],
    ],
];
