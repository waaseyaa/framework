<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\OAuthProvider\\OAuthProviderInterface', 'disposition' => 'public', 'purpose' => 'OAuth 2.0 provider abstraction: authorization URL, code exchange, token refresh, user profile'],
        ['fqcn' => 'Waaseyaa\\OAuthProvider\\SessionInterface', 'disposition' => 'public', 'purpose' => 'Manages OAuth session state (CSRF state token and post-auth redirect)'],
    ],
];
