<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Access\\AccessPolicyInterface', 'disposition' => 'public', 'purpose' => 'Checks entity-level access for view, update, delete, and (M-006 / ADR 017) `\'translate\'` operations'],
        ['fqcn' => 'Waaseyaa\\Access\\AccessStatus', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\AccountInterface', 'disposition' => 'public', 'purpose' => 'Represents a user account for access checking: ID, roles, and permission checks'],
        ['fqcn' => 'Waaseyaa\\Access\\AccountPrincipalFactory', 'disposition' => 'public', 'purpose' => 'Principal/entity-account snapshotter that refuses lossy conversion of arbitrary plain accounts'],
        ['fqcn' => 'Waaseyaa\\Access\\AccountPrincipalFactoryInterface', 'disposition' => 'public', 'purpose' => 'Closed bootstrap seam for passing through principals or snapshotting an entity-backed account through the audited reader'],
        ['fqcn' => 'Waaseyaa\\Access\\AuthorizationPrincipalBootstrapReaderInterface', 'disposition' => 'public', 'purpose' => 'Closed bridge for strictly audited immutable principal construction from an entity-backed account'],
        ['fqcn' => 'Waaseyaa\\Access\\AuthorizationPrincipalInterface', 'disposition' => 'public', 'purpose' => 'Immutable account-facing claims used by protected field-read policies without reading an acting User entity'],
        ['fqcn' => 'Waaseyaa\\Access\\Capability\\CapabilityActorSemantics', 'disposition' => 'public', 'purpose' => 'Explicit account, anonymous, system-service, or no-acting-context attribution for capability issuance and ledger reservations'],
        ['fqcn' => 'Waaseyaa\\Access\\Capability\\CapabilityExecutionBoundary', 'disposition' => 'public', 'purpose' => 'Opaque, non-serializable proof whose registry-owned identity must be live and match at capability issuance and use'],
        ['fqcn' => 'Waaseyaa\\Access\\Capability\\CapabilityReason', 'disposition' => 'public', 'purpose' => 'Closed reason vocabulary for privileged field reads'],
        ['fqcn' => 'Waaseyaa\\Access\\Capability\\CapabilityRegistryInterface', 'disposition' => 'public', 'purpose' => 'Kernel registry for reviewed, exact value-read and query-read capability declarations and one-boundary handles'],
        ['fqcn' => 'Waaseyaa\\Access\\Capability\\QueryFieldOperation', 'disposition' => 'public', 'purpose' => 'Closed non-public query operation vocabulary: predicate, sort, aggregate, count, exists'],
        ['fqcn' => 'Waaseyaa\\Access\\ClassifiedProtectedEntityReadPolicyInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Access\\ContextAwareAccessPolicyInterface', 'disposition' => 'public', 'purpose' => 'Companion to `AccessPolicyInterface` accepting a `$context` array (carries `langcode` for the `\'translate\'` operation and read-time langcode for `view`/`update`) (M-006, WP09)'],
        ['fqcn' => 'Waaseyaa\\Access\\Context\\AccountContextInterface', 'disposition' => 'public', 'ref' => '#1644'],
        ['fqcn' => 'Waaseyaa\\Access\\Context\\AccountFieldReadScopeInterface', 'disposition' => 'public', 'purpose' => 'Fiber-local, nested account principal scope restored in `finally`; it carries no privileged authority'],
        ['fqcn' => 'Waaseyaa\\Access\\Context\\FastAccountFieldReadScopeInterface', 'disposition' => 'internal', 'purpose' => 'Internal compiled-read fast path exposing the current immutable account context without widening public authority'],
        ['fqcn' => 'Waaseyaa\\Access\\ContextualAccountPrincipalFactoryInterface', 'disposition' => 'public', 'purpose' => 'HTTP companion that binds resolved tenant/community dimensions to the immutable principal snapshot'],
        ['fqcn' => 'Waaseyaa\\Access\\ContextualProtectedEntityReadPolicyInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Access\\DelegatingAuthorizationPrincipal', 'disposition' => 'public', 'purpose' => 'Explicit legacy migration principal with provider-owned claims metadata and verbatim delegated account authorization behavior'],
        ['fqcn' => 'Waaseyaa\\Access\\EntityViewProtectedFieldReadPolicyInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Access\\ErrorPageRendererInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Access\\FieldAccessPolicyInterface', 'disposition' => 'public', 'purpose' => 'Checks field-level access on an entity; open-by-default (Forbidden restricts, Neutral allows)'],
        ['fqcn' => 'Waaseyaa\\Access\\Gate\\GateInterface', 'disposition' => 'public', 'purpose' => 'Resolves the policy for a subject and checks whether a user has a given ability'],
        ['fqcn' => 'Waaseyaa\\Access\\Gate\\ListingFastPathProbeInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\Gate\\RevisionAccessRouter', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\Middleware\\FieldReadContextMiddleware', 'disposition' => 'public', 'purpose' => 'Priority-15 HTTP seam that installs/restores the immutable principal after identity resolution and wraps deferred streams'],
        ['fqcn' => 'Waaseyaa\\Access\\PermissionHandlerInterface', 'disposition' => 'public', 'purpose' => 'Manages the registry of available permissions and their metadata'],
        ['fqcn' => 'Waaseyaa\\Access\\PolicySubjectViewInterface', 'disposition' => 'public', 'purpose' => 'Closed view limited to compiled `authorizationInput` subject fields'],
        ['fqcn' => 'Waaseyaa\\Access\\Policy\\RevisionPolicyComposition', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\ProjectedProtectedEntityReadPolicyInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Access\\ProtectedEntityReadPolicyInterface', 'disposition' => 'public', 'purpose' => 'Fail-closed V2 entity-read policy over immutable principal, structural identity, and exact compiled subject inputs'],
        ['fqcn' => 'Waaseyaa\\Access\\ProtectedFieldReadPolicyInterface', 'disposition' => 'public', 'purpose' => 'Dedicated fail-closed Protected read policy; only explicit Allowed will release a value after activation'],
        ['fqcn' => 'Waaseyaa\\Access\\ProtectedReadPolicyProviderInterface', 'disposition' => 'public', 'purpose' => 'Additive companion through which a discovered legacy policy exposes its entity and field V2 read policies'],
        ['fqcn' => 'Waaseyaa\\Access\\Query\\QueryFieldReadRequest', 'disposition' => 'public', 'purpose' => 'Metadata-only query compiler input retaining exact fields/operations and an irreversible normalized-shape fingerprint'],
        ['fqcn' => 'Waaseyaa\\Access\\Read\\AuthorizationInputReader', 'disposition' => 'public', 'purpose' => 'Generalizes the bound-closure authorizationInput read pattern to any entity, for generated access policies with no entity-specific reader class', 'ref' => '#2788'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserAuthorizationSnapshot', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserCredentialSnapshot', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserIdentityLookupInterface', 'disposition' => 'public', 'purpose' => 'Closed audited active-login, mail-only recovery, and mail-existence query boundary'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserInternalFieldReaderInterface', 'disposition' => 'public', 'purpose' => 'Narrow reason-specific User credential, session, mail, verification, 2FA, and maintenance read boundary'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserMailSnapshot', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserSelfProfileReaderInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserSessionSnapshot', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserTwoFactorSnapshot', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Access\\User\\UserVerificationSnapshot', 'disposition' => 'public'],
    ],
    'notes' => [
        '`User*Snapshot` (final readonly classes): Typed exact User internal inputs returned without exposing arbitrary field-name authority',
    ],
];
