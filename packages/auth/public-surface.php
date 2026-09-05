<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Auth\\AtomicRateLimiterInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Auth\\Config\\MailMissingPolicy', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Auth\\Extension\\AuthMailContentPolicyInterface', 'disposition' => 'public', 'ref' => '#2437'],
        ['fqcn' => 'Waaseyaa\\Auth\\Extension\\AuthRedirectPolicyInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Auth\\Extension\\InitialRolePolicyInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Auth\\Extension\\ProvidesAuthExtensionsInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Auth\\Extension\\RegistrationPolicyInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Auth\\Extension\\RegistrationProfileHandlerInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Auth\\Password\\LegacyPasswordVerifierInterface', 'disposition' => 'public', 'ref' => '#2544'],
        ['fqcn' => 'Waaseyaa\\Auth\\RateLimiterInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Auth\\Token\\AuthTokenRepositoryInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Auth\\Token\\Bearer\\BearerTokenStoreInterface', 'disposition' => 'public', 'ref' => '#2177'],
    ],
];
