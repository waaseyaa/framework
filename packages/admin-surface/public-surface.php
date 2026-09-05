<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Action\\SurfaceActionHandlerInterface', 'disposition' => 'public', 'purpose' => 'Handles a custom admin surface action for a given entity type and payload'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AbstractAdminSurfaceHost', 'disposition' => 'public', 'purpose' => 'Base class applications extend to integrate with the admin SPA (session, catalog, entity ops)'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminPublicationFieldReaderInterface', 'disposition' => 'public', 'purpose' => 'Closed application-wiring boundary for authorized node publication metadata in admin lists'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminRevisionPreviewAuthorityInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\AdminSurfaceHostFactoryInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Host\\BatchAdminPublicationFieldReaderInterface', 'disposition' => 'public', 'purpose' => 'Cardinality-preserving batch extension that projects an authorized list scope transactionally'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\List\\ListFormatter', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\PageBuilder\\PageBuilderSurfaceHostInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\AdminSurface\\Query\\SurfaceFilterOperator', 'disposition' => 'public'],
    ],
];
