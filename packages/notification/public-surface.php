<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Notification\\ChannelInterface', 'disposition' => 'public', 'purpose' => 'Delivers a notification to a notifiable recipient via one transport'],
        ['fqcn' => 'Waaseyaa\\Notification\\NotifiableInterface', 'disposition' => 'public', 'purpose' => 'Marks a recipient as notification-capable and provides channel routing'],
        ['fqcn' => 'Waaseyaa\\Notification\\NotifiableTrait', 'disposition' => 'public', 'purpose' => 'Default `NotifiableInterface` implementation routing by channel for entity classes'],
        ['fqcn' => 'Waaseyaa\\Notification\\NotificationInterface', 'disposition' => 'public', 'purpose' => 'Defines which channels to deliver through and provides channel-specific payloads'],
    ],
];
