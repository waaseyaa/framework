<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Listing\\EntityRepositoryRegistry', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Listing\\Exception\\ListingCoercionException', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Listing\\Exception\\UnknownListingException', 'disposition' => 'public', 'purpose' => 'Registry miss (carries listing id)'],
        ['fqcn' => 'Waaseyaa\\Listing\\Exception\\UnsupportedListingException', 'disposition' => 'public', 'purpose' => 'Definition-time validation failure (carries listing id, field name, reason)'],
        ['fqcn' => 'Waaseyaa\\Listing\\ExposedFilterCoercer', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Listing\\ExposedFilterParser', 'disposition' => 'public', 'purpose' => 'Parses query params into `ExposedFilterValues`; never throws on user input'],
        ['fqcn' => 'Waaseyaa\\Listing\\ExposedFilterValues', 'disposition' => 'public', 'purpose' => 'Typed view over parsed `$_GET` slice passed to `ListingResolver::resolve()`'],
        ['fqcn' => 'Waaseyaa\\Listing\\Filter', 'disposition' => 'public', 'purpose' => 'Sugar factories: `eq()`, `gte()`, `in()`, `isNull()`, `langcode()`, `exposed()`, etc.'],
        ['fqcn' => 'Waaseyaa\\Listing\\FilterDefinition', 'disposition' => 'public', 'purpose' => 'Field + operator + value; optional `exposedParam` for URL-driven filters'],
        ['fqcn' => 'Waaseyaa\\Listing\\HasListingsInterface', 'disposition' => 'public', 'purpose' => 'ServiceProviders implement to declare listings; mirrors `HasMigrationsInterface`'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingCacheInvalidator', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingCacheKeyBuilder', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingDefinition', 'disposition' => 'public', 'purpose' => 'Immutable listing manifest: id, entity type, filters, sorts, page size, access ops'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingDefinitionRegistry', 'disposition' => 'public', 'purpose' => '`get(string $id): ListingDefinition` — throws `UnknownListingException` on miss'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingDefinitionValidator', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingDiscoverer', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingResolver', 'disposition' => 'public', 'purpose' => 'Single public method `resolve(ListingDefinition, ?ExposedFilterValues): ListingResult`'],
        ['fqcn' => 'Waaseyaa\\Listing\\ListingResult', 'disposition' => 'public', 'purpose' => 'Resolution result: rows + pagination + cache tags + cache contexts'],
        ['fqcn' => 'Waaseyaa\\Listing\\Operator', 'disposition' => 'public', 'purpose' => 'Filter vocabulary: EQ, NEQ, LT, LTE, GT, GTE, IN, NOT_IN, IS_NULL, IS_NOT_NULL, BETWEEN, STARTS_WITH, CONTAINS'],
        ['fqcn' => 'Waaseyaa\\Listing\\Pagination', 'disposition' => 'public', 'purpose' => 'Page metadata: page, page size, total rows, total pages, hasPrev, hasNext'],
        ['fqcn' => 'Waaseyaa\\Listing\\Sort', 'disposition' => 'public', 'purpose' => 'Sugar factories: `asc()`, `desc()`'],
        ['fqcn' => 'Waaseyaa\\Listing\\SortDefinition', 'disposition' => 'public', 'purpose' => 'Field + direction; resolver appends an implicit id tie-break sort'],
        ['fqcn' => 'Waaseyaa\\Listing\\SortDirection', 'disposition' => 'public', 'purpose' => 'ASC, DESC'],
    ],
];
