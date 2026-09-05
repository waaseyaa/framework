<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Command\\EditCommand', 'disposition' => 'public', 'ref' => '#2344'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Draft\\AdvisoryAwareLayoutDraftGatewayInterface', 'disposition' => 'public', 'ref' => '#2473'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Draft\\Exception\\LayoutSaveAdvisoryException', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Draft\\Exception\\UnsupportedLayoutSaveAdvisoryAcknowledgementException', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Draft\\InitialLayoutDocumentProviderInterface', 'disposition' => 'public', 'ref' => '#2556'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Draft\\LayoutDraftGatewayInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Draft\\LayoutSaveAdvisoryAcknowledgementDispatcher', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Preview\\RevisionPreviewGatewayInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Preview\\RevisionPreviewUrlGeneratorInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\PageBuilder\\Revision\\PageBuilderRevisionGatewayInterface', 'disposition' => 'public'],
    ],
];
