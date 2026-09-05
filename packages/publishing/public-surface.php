<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Publishing\\AdvisoryAwareContentDraftMutationInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Publishing\\ContentDraftMutationInterface', 'disposition' => 'public', 'ref' => '#2467'],
        ['fqcn' => 'Waaseyaa\\Publishing\\ContentHtmlSanitizerInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Publishing\\ContentPublicationTransitionerInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Publishing\\ContentRevisionHistoryInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Publishing\\ContentRevisionPreviewInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Publishing\\ContentValidatorInterface', 'disposition' => 'public', 'ref' => '#2136'],
        ['fqcn' => 'Waaseyaa\\Publishing\\Exception\\ContentPublishingException', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Publishing\\Exception\\UnsupportedSaveAdvisoryAcknowledgementException', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Publishing\\SaveAdvisoryAcknowledgementDispatcher', 'disposition' => 'public'],
    ],
];
