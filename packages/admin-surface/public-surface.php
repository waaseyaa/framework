<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Action\\SurfaceActionHandlerInterface', 'disposition' => 'public', 'purpose' => 'Handles a custom admin surface action for a given entity type and payload'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\AdminDestinationPaths', 'disposition' => 'public', 'purpose' => 'Canonical Admin SPA destination URL authority', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\AdminSurfaceRoutePaths', 'disposition' => 'public', 'purpose' => 'Canonical Admin Surface HTTP route path authority', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Catalog\\ActionDefinition', 'disposition' => 'public', 'purpose' => 'Catalog action declaration returned through custom host composition', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Catalog\\CatalogBuilder', 'disposition' => 'public', 'purpose' => 'Application catalog construction API used by custom Admin Surface hosts', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Catalog\\EntityDefinition', 'disposition' => 'public', 'purpose' => 'Catalog entity declaration returned through custom host composition', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Catalog\\FieldDefinition', 'disposition' => 'public', 'purpose' => 'Catalog field declaration returned through custom host composition', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AbstractAdminSurfaceHost', 'disposition' => 'public', 'purpose' => 'Base class applications extend to integrate with the admin SPA (session, catalog, entity ops)'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminPublicationFieldReaderInterface', 'disposition' => 'public', 'purpose' => 'Closed application-wiring boundary for authorized node publication metadata in admin lists'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminRevisionPreviewAuthorityInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminRevisionPreviewGrantData', 'disposition' => 'public', 'purpose' => 'Crossing value for an application-authorized exact-revision preview grant', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminSurfaceHostFactoryInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminSurfaceResultData', 'disposition' => 'public', 'purpose' => 'Canonical Admin Surface success and refusal envelope value', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminSurfaceSessionData', 'disposition' => 'public', 'purpose' => 'Canonical session payload value returned by custom hosts', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminSurfaceUiPayload', 'disposition' => 'public', 'purpose' => 'Optional Admin UI customization payload supplied by custom hosts', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\BatchAdminPublicationFieldReaderInterface', 'disposition' => 'public', 'purpose' => 'Cardinality-preserving batch extension that projects an authorized list scope transactionally'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\List\\ListFormatter', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\List\\ListMetadata', 'disposition' => 'public', 'purpose' => 'Validated list-metadata declaration shared by hosts and query enforcement', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\List\\SurfaceQueryPolicy', 'disposition' => 'public', 'purpose' => 'Enforces declared list filters and sorting before host delegation', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\PageBuilder\\GenericPageBuilderSurfaceHost', 'disposition' => 'public', 'purpose' => 'Framework default page-builder host for generated and application composition', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\PageBuilder\\PageBuilderSurfaceHostInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\PageBuilder\\PageBuilderSurfaceRequest', 'disposition' => 'public', 'purpose' => 'Authenticated principal and body value passed to page-builder host ports', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Query\\SurfaceQuery', 'disposition' => 'public', 'purpose' => 'Parsed and validated Admin list query crossing value', 'ref' => '#3074'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Query\\SurfaceFilterOperator', 'disposition' => 'public'],
    ],
];
