<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Audit\\AuditedFieldRead', 'disposition' => 'public', 'purpose' => 'Exact-declaration privileged reader that reserves strict-ledger metadata before accessor evaluation and finalizes its outcome'],
        ['fqcn' => 'Waaseyaa\\Audit\\AuditedQueryFieldRead', 'disposition' => 'public', 'purpose' => 'Dormant exact-capability compiler boundary that reserves non-public query metadata before execution'],
        ['fqcn' => 'Waaseyaa\\Audit\\AuditedQueryReservation', 'disposition' => 'public', 'purpose' => 'One-shot explicit success/failure finalizer for a reserved query operation'],
        ['fqcn' => 'Waaseyaa\\Audit\\Bootstrap\\AuditedUserIdentityLookup', 'disposition' => 'public', 'purpose' => 'Reserves and finalizes exact non-public login/mail repository queries'],
        ['fqcn' => 'Waaseyaa\\Audit\\Bootstrap\\AuditedUserInternalFieldReader', 'disposition' => 'public', 'purpose' => 'Issues, consumes, and revokes exact framework User value-read capabilities'],
        ['fqcn' => 'Waaseyaa\\Audit\\Bootstrap\\CredentialBootstrapReader', 'disposition' => 'public', 'purpose' => 'Reason-constrained strictly audited credential verification reader'],
        ['fqcn' => 'Waaseyaa\\Audit\\Bootstrap\\IdentityBootstrapReader', 'disposition' => 'public', 'purpose' => 'Per-snapshot capability issuer that builds immutable principals and revokes bootstrap authority in `finally`'],
        ['fqcn' => 'Waaseyaa\\Audit\\Bootstrap\\SessionBootstrapReader', 'disposition' => 'public', 'purpose' => 'Reason-constrained strictly audited session and authorization-claims reader'],
        ['fqcn' => 'Waaseyaa\\Audit\\Contract\\AuditQueryInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Audit\\Contract\\AuditWriteFailureObserver', 'disposition' => 'public', 'ref' => '#1792'],
        ['fqcn' => 'Waaseyaa\\Audit\\Contract\\AuditWriterInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Audit\\Contract\\BatchStrictPrivilegedReadLedgerInterface', 'disposition' => 'public', 'purpose' => 'Transactional batch extension that keeps descriptors entity-scoped while making related reservations and outcomes all-or-nothing'],
        ['fqcn' => 'Waaseyaa\\Audit\\Contract\\PrivilegedReadKind', 'disposition' => 'public', 'purpose' => 'Distinguishes explicit value-read reservations from query-field reservations'],
        ['fqcn' => 'Waaseyaa\\Audit\\Contract\\PrivilegedReadOutcome', 'disposition' => 'public', 'purpose' => 'Strict-ledger final outcomes: succeeded or failed; interrupted reservations remain unfinished and visible'],
        ['fqcn' => 'Waaseyaa\\Audit\\Contract\\StrictPrivilegedReadLedgerInterface', 'disposition' => 'public', 'purpose' => 'Synchronously reserves non-value metadata before a privileged read and finalizes its outcome afterward'],
        ['fqcn' => 'Waaseyaa\\Audit\\Enum\\AuditEventKind', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Audit\\Integrity\\CheckpointSink', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Audit\\ReadModel\\AuditReadModelDefinitionRegistry', 'disposition' => 'public', 'purpose' => 'Exact read classifications for every column of the deliberately unregistered flat audit tables'],
        ['fqcn' => 'Waaseyaa\\Audit\\Writer\\DatabaseStrictPrivilegedReadLedger', 'disposition' => 'public', 'purpose' => 'Durable immutable-event ledger with atomic single-finalization and caller-transaction composition'],
    ],
];
