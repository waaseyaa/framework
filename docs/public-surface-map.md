# Waaseyaa Public Surface Map

GENERATED FILE — DO NOT EDIT BY HAND.

This document lists every governed API element in the Waaseyaa framework with its
declared disposition. Only `public` rows are stability commitments; `internal` rows,
and any element not listed here, are `@internal` and may change without notice.

Single editable authority: `packages/<pkg>/public-surface.php` (contract:
`docs/specs/public-surface-declarations.md`). Composed by `bin/generate-surface-map`.
Machine-readable derived view: `docs/public-surface-map.php`.

---

## Layer 0: Foundation

### analytics

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Transport` | interface | public | — |

### cache

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `CacheBackendInterface` | interface | public | Reads and writes cache items with optional tag and expiry support |
| `CacheFactoryInterface` | interface | public | Creates or retrieves cache backend instances by bin name |
| `CacheTagsInvalidatorInterface` | interface | public | Invalidates all cache items associated with a set of tags |
| `ContextNames` | final class | public | Canonical context-name constants (`USER_ROLES`, `USER_ID`, `LANGUAGE_CONTENT`, `LANGUAGE_INTERFACE`, `URL_QUERY_PREFIX`) |
| `ContextRegistry` | final class | public | Whitelist of canonical context names for cache-key segmentation (charter §5.9) |
| `ContextResolver` | final class | public | Resolves a context name against a `RequestContext` into a deterministic short string |
| `EntityPayloadBoundaryConfig` | final readonly class | public | Explicit dormant/enforced cache entity-payload write mode |
| `Exception\EntityProjectionWriteForbidden` | final class | public | Activated cache rejection before an entity graph can be retained |
| `Exception\InvalidCacheTagException` | final class | public | Thrown by `setWithTags()` on malformed tag strings (no silent normalisation) |
| `ProjectionDeprecationDiagnostic` | final class | public | Deduplicated dormant entity-payload diagnostic wired into first-party cache writes |
| `ProtectedCacheDimensions` | final readonly class | public | Complete protected-cache authority, bundle, language, revision, and generation key dimensions |
| `TagAwareCacheInterface` | interface | public | Cache backend that supports tag-based invalidation |
| `TaggedCacheInterface` | interface | public | Listing-pipeline tag-aware ops (`setWithTags`, `invalidateByTag`, `getTagsFor`) — charter §5.9 |

### database-legacy

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `ConsistentReadDatabaseInterface` | interface | public | — |
| `DatabaseIdentityProviderInterface` | interface | public | — |
| `DatabaseInterface` | interface | public | Doctrine DBAL abstraction: query builder entry point for select, insert, update, delete |
| `DeleteInterface` | interface | public | Fluent DELETE query builder with conditions |
| `Exception\TransactionCompletionException` | final class | public | Reports completion-effect failures after the database has committed |
| `ForeignKeySchemaInterface` | interface | public | — |
| `InsertInterface` | interface | public | Fluent INSERT query builder |
| `SchemaInterface` | interface | public | DDL operations: create/alter/drop tables and columns |
| `SelectInterface` | interface | public | Fluent SELECT query builder with conditions, joins, ordering, and pagination |
| `TransactionCompletionInterface` | interface | public | Defers registered callbacks through nested managed transactions to the outermost commit |
| `TransactionInterface` | interface | public | Wraps database operations in a named transaction with commit/rollback |
| `UpdateInterface` | interface | public | Fluent UPDATE query builder with conditions |

### error-handler

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `SolutionInterface` | interface | public | — |
| `SolutionProviderInterface` | interface | public | — |

### foundation

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Asset\AssetManagerInterface` | interface | public | Resolves source asset paths to versioned/hashed production URLs via build manifests |
| `Audit\Approval\ApprovalStatus` | enum | public | — |
| `Audit\Approval\OperationApprovalStoreInterface` | interface | public | — |
| `Audit\AuditStage` | enum | public | — |
| `Audit\StrictAuditLedgerInterface` | interface | public | — |
| `Community\CommunityContextInterface` | interface | public | — |
| `Diagnostic\DiagnosticCode` | enum | public | — |
| `Diagnostic\HealthCheckerInterface` | interface | public | Runs boot, runtime, and ingestion health checks across subsystems |
| `Event\DomainEvent` | abstract class | public | Base class for all domain events carrying aggregate identity and actor context |
| `Event\EventDispatcherInterface` | interface | public | — |
| `Exception\WaaseyaaException` | abstract class | public | Base exception for all framework errors, with HTTP status code and problem type |
| `Http\BindingAwareHttpServiceResolverInterface` | interface | internal | — |
| `Http\HttpServiceResolverInterface` | interface | public | — |
| `Http\Inbound\InboundHttpRequestInterface` | interface | public | — |
| `Http\Inertia\InertiaFullPageRendererInterface` | interface | public | — |
| `Http\Inertia\InertiaPageResultInterface` | interface | public | — |
| `Http\JsonApiResponseTrait` | trait | public | Builds JSON:API responses with correct content type and encoding options |
| `Http\LanguagePathStripperInterface` | interface | public | — |
| `Http\RequestContext` | final readonly class | public | Exposes the active request's roles, user id, languages, and query params to `ContextResolver` (charter §5.9) |
| `Http\Router\DomainRouterInterface` | interface | public | — |
| `Ingestion\IngestionErrorCode` | enum | public | — |
| `Ingestion\TraceIdGeneratorInterface` | interface | public | — |
| `Kernel\AbstractKernel` | abstract class | internal | — |
| `Kernel\Bootstrap\PolicyDependencyResolverInterface` | interface | public | — |
| `Log\Formatter\FormatterInterface` | interface | public | Formats a log record into its final string or array representation |
| `Log\Handler\HandlerInterface` | interface | public | Log handler that receives and writes formatted log records |
| `Log\LogLevel` | enum | public | — |
| `Log\LoggerInterface` | interface | public | Structured logger with PSR-3-style severity levels (framework-internal, not psr/log) |
| `Log\LoggerTrait` | trait | public | Default implementations of all log-level methods delegating to `log()` |
| `Log\Processor\ProcessorInterface` | interface | public | Enriches log records with additional context before handling |
| `Middleware\HttpHandlerInterface` | interface | public | Terminal HTTP request handler (innermost layer of the middleware onion) |
| `Middleware\HttpMiddlewareInterface` | interface | public | Wraps an HTTP handler to add cross-cutting behavior |
| `Middleware\JobHandlerInterface` | interface | public | Terminal queue job handler |
| `Middleware\JobMiddlewareInterface` | interface | public | Wraps a job handler to add cross-cutting behavior |
| `Migration\Dag\MigrationKind` | enum | public | — |
| `Migration\EntityTableMaterializerInterface` | interface | public | — |
| `Migration\Executor\ApplyMode` | enum | public | — |
| `Migration\Executor\OpPrecondition` | enum | internal | — |
| `Migration\Migration` | abstract class | public | Base class for database migrations with optional rollback and ordering |
| `Migration\SchemaAuthorityManifest` | final readonly class | public | — |
| `Migration\VerifyResult` | enum | public | — |
| `RateLimit\RateLimiterInterface` | interface | public | Checks and records attempt counts for rate limiting |
| `Runtime\RuntimeEpochInterface` | interface | public | — |
| `Schema\Compiler\CompiledStep` | interface | public | — |
| `Schema\Compiler\Sqlite\SqliteDiagnosticCode` | enum | public | — |
| `Schema\Compiler\Validation\ValidationDiagnosticCode` | enum | public | — |
| `Schema\Compiler\Validation\ValidationException` | abstract class | public | — |
| `Schema\Diff\OpKind` | enum | public | — |
| `Schema\Diff\SchemaDiffOp` | interface | public | — |
| `Schema\Migration\MigrationInterfaceV2` | interface | public | — |
| `Schema\SchemaRegistryInterface` | interface | public | Stores and retrieves JSON Schema entries by entity type ID |
| `Security\ApplicationMasterPurposeStrategy` | enum | public | Closed transition strategies for versioned application-master purposes |
| `Security\ApplicationMasterSymmetricOperation` | trait | internal | — |
| `Security\Rekey\ApplicationMasterRekeyAdapterInterface` | interface | public | Same-database joint-owner inventory, transition, verification, and rollback seam |
| `Security\Rekey\ApplicationMasterRekeyGate` | enum | public | Closed fleet, cache, rollback, and retained-backup revocation gates |
| `Security\Rekey\ApplicationMasterRekeyState` | enum | public | Closed persisted prepare-through-revocation and forward-rollback states |
| `Security\SecretClass` | enum | public | Closed secret classifications used by typed references, policies, and guarded consumers |
| `Security\SecretConsumerInterface` | interface | public | Purpose-specific guarded endpoint for resolved secret bytes |
| `Security\SecretConsumptionCode` | enum | public | — |
| `Security\SecretProviderInterface` | interface | public | External secret-provider resolution seam |
| `Security\SecretResolutionCode` | enum | public | — |
| `ServiceProvider\Capability\AcceptsAgentToolProvidersInterface` | interface | public | — |
| `ServiceProvider\Capability\AcceptsAiCatalogEntryProvidersInterface` | interface | public | Receives the deterministic installed-provider set used to assemble experimental AI Catalog discovery |
| `ServiceProvider\Capability\AcceptsApiCatalogEntryProvidersInterface` | interface | public | Receives the deterministic installed-provider set used to assemble RFC 9727 discovery |
| `ServiceProvider\Capability\AcceptsContentModelProvidersInterface` | interface | public | — |
| `ServiceProvider\Capability\AcceptsMigrationProvidersInterface` | interface | public | — |
| `ServiceProvider\Capability\ConfiguresHttpKernelInterface` | interface | public | — |
| `ServiceProvider\Capability\FinalizesProviderBootInterface` | interface | public | — |
| `ServiceProvider\Capability\HasGraphqlMutationOverridesInterface` | interface | public | — |
| `ServiceProvider\Capability\HasHttpDomainRoutersInterface` | interface | public | — |
| `ServiceProvider\Capability\HasMiddlewareInterface` | interface | public | — |
| `ServiceProvider\Capability\HasRenderCacheListenersInterface` | interface | public | — |
| `ServiceProvider\Capability\ProvidesAiCatalogEntriesInterface` | interface | public | Contributes same-origin, intentionally public AI artifact references without deployment-specific language |
| `ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface` | interface | public | Contributes same-origin, intentionally public API endpoints and bounded description links |
| `ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface` | interface | public | — |
| `ServiceProvider\Capability\ProvidesCapabilitiesInterface` | interface | public | — |
| `ServiceProvider\Capability\ProvidesConsoleCommandsInterface` | interface | public | — |
| `ServiceProvider\Capability\ProvidesRolesInterface` | interface | public | — |
| `ServiceProvider\Capability\RequiresCapabilitiesInterface` | interface | public | — |
| `ServiceProvider\Capability\RequiresOptionalPackagesInterface` | interface | public | — |
| `ServiceProvider\KernelServicesInterface` | interface | public | — |
| `ServiceProvider\ServiceProvider` | abstract class | public | Base class for service providers with DI binding and resolution helpers |
| `ServiceProvider\ServiceProviderInterface` | interface | public | Contract for packages to register and boot their services |
| `Sovereignty\SovereigntyConfigInterface` | interface | public | — |
| `Sovereignty\SovereigntyProfile` | enum | public | — |
| `Tenant\TenantResolverInterface` | interface | internal | — |
| `Upgrade\UpgradePreflightContract` | final class | public | — |
| `Upgrade\UpgradePreflightDecision` | enum | public | — |
| `Upgrade\UpgradePreflightEvaluator` | final class | public | — |
| `Upgrade\UpgradePreflightResult` | final readonly class | public | — |

- `Security\ApplicationMasterPurposePolicy` (final readonly class): Immutable owner, retention, adapter, strategy, and rollback metadata for one derived purpose
- `Security\ApplicationMasterPurposeRegistry` (final class): Deterministic boot-time frozen registry of application-master purposes
- `Security\ApplicationMasterEnvelope` (final readonly class): Strict XChaCha20-Poly1305 envelope binding master version, purpose, record, and schema identity
- `Security\ApplicationMasterKeyring` (final class): Active-write and bounded legacy-read custody over externally resolved versioned masters
- `Security\Rekey\ApplicationMasterRekeyRequest` (final readonly class): Immutable non-secret request, authorization digest, versions, and rollback/retention horizons
- `Security\Rekey\ApplicationMasterRekeyStore` (final class): Migration-backed CAS projections and append-only hash-chained rekey evidence
- `Security\Rekey\ApplicationMasterRekeyCoordinator` (final class): Restart-safe same-transaction executor for composed owner adapters
- `Security\Rekey\ApplicationMasterRekeyContext` (final readonly class): Exact immutable request, guarded keyring, and store transaction authority passed to adapters
- `Security\Rekey\ApplicationMasterInventorySnapshot` (final readonly class): Immutable inventory count and SHA-256 commitment
- `Security\Rekey\ApplicationMasterBatchResult` (final readonly class): Next cursor, transitioned count, per-purpose deltas, and batch commitment
- `Security\Rekey\ApplicationMasterPurposeVerification` (final readonly class): Per-purpose verified count and commitment
- `Security\Rekey\ApplicationMasterAdapterProgress` (final readonly class): Restart-safe joint-owner snapshot, cursor, counts, and commitment projection
- `Security\Rekey\ApplicationMasterRekeyEvent` (final readonly class): Verifiable immutable non-secret ledger event

### http-client

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `HttpClientInterface` | interface | internal | — |
| `SseLineStreamInterface` | interface | public | — |

### i18n

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `LanguageManagerInterface` | interface | public | Manages the set of available languages and their default |
| `TranslatorInterface` | interface | public | Translates keys with optional parameter substitution and locale override |

### ingestion

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `EnvelopeValidator` | abstract class | internal | — |
| `PayloadValidatorInterface` | interface | internal | — |

### mail

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `MailerInterface` | interface | public | — |
| `Transport\TransportInterface` | interface | internal | — |

### oauth-provider

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `OAuthProviderInterface` | interface | public | OAuth 2.0 provider abstraction: authorization URL, code exchange, token refresh, user profile |
| `SessionInterface` | interface | public | Manages OAuth session state (CSRF state token and post-auth redirect) |

### plugin

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Discovery\PluginDiscoveryInterface` | interface | internal | — |
| `Extension\KnowledgeToolingExtensionInterface` | interface | internal | — |
| `Factory\PluginFactoryInterface` | interface | internal | — |
| `PluginBase` | abstract class | public | Base implementation of `PluginInspectionInterface` for all plugin types |
| `PluginInspectionInterface` | interface | public | Provides read access to a plugin's ID and definition |
| `PluginManagerInterface` | interface | public | Discovers, retrieves, and instantiates plugins by ID |

### queue

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Envelope\NoAuthorityQueueRuntime` | final readonly class | public | Dormant runtime that installs no account or capability authority |
| `Envelope\QueueAuthorityRuntimeInterface` | interface | public | Confines envelope authority resolution and installation to one handler invocation |
| `Envelope\QueueAuthorityScopeInterface` | interface | public | Closeable handler-only authority installation used by queue runtimes |
| `Envelope\QueueEnvelopeFactoryInterface` | interface | public | Dispatch-bound factory for an explicit actor/system authority envelope |
| `Envelope\QueueEnvelopeV1` | final readonly class | public | Dormant versioned authority envelope carrying exactly one actor or system authority plus tenant/community and correlation dimensions |
| `Envelope\QueueSystemReason` | enum | public | Closed reason vocabulary for system-owned queue authority |
| `Envelope\ScopedQueueAuthorityRuntime` | final readonly class | public | Resolves and closes one handler-only authority scope in `finally` |
| `Envelope\SystemQueueEnvelopeFactory` | final readonly class | public | Explicit reviewed system-authority envelope factory; never the generic dispatch default |
| `Exception\InvalidPersistentPayload` | final class | public | Exact replay payload failed authentication |
| `FailedJobRepositoryInterface` | interface | internal | — |
| `Handler\HandlerInterface` | interface | internal | — |
| `Job` | abstract class | internal | — |
| `OccurrenceQueueInterface` | interface | public | — |
| `Occurrence\OccurrenceAwareMessageInterface` | interface | public | — |
| `Occurrence\OccurrenceContextInterface` | interface | public | — |
| `Occurrence\OccurrenceRunResult` | enum | public | — |
| `Occurrence\OccurrenceRuntimeInterface` | interface | public | — |
| `PersistentPayloadReplayInterface` | interface | public | Replays the exact authenticated persistent payload without replacing its envelope metadata |
| `PersistentQueueBoundaryConfig` | final readonly class | public | Explicit dormant/enforced persistent dispatch and legacy-envelope mode |
| `QueueInterface` | interface | public | Dispatches messages to the queue for asynchronous processing |
| `QueuePayloadDeprecationDiagnostic` | final class | public | Bounded nested entity-payload diagnostic for persistent dispatch |
| `Transport\TransportInterface` | interface | internal | — |

### scheduler

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Execution\LeaseAwareCommandInterface` | interface | public | — |
| `Execution\LeaseExecutionContext` | final class | public | — |
| `Fence\FenceGuardInterface` | interface | internal | — |
| `Lease\LeaseAuthorityInterface` | interface | internal | — |
| `Occurrence\OccurrenceDispatchResult` | enum | internal | — |
| `Occurrence\OccurrenceRepositoryInterface` | interface | internal | — |
| `ScheduleEntriesInterface` | interface | public | — |
| `ScheduleInterface` | interface | internal | — |

### site-contract

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Blueprint\BlueprintCheckKind` | enum | public | — |
| `Blueprint\BlueprintConditionKind` | enum | public | — |
| `Blueprint\BlueprintDecision` | enum | public | — |
| `Blueprint\BlueprintFieldType` | enum | public | — |
| `Blueprint\BlueprintLifecycle` | enum | public | — |
| `Blueprint\BlueprintOnDelete` | enum | public | — |
| `Blueprint\BlueprintOperation` | enum | public | — |
| `Blueprint\BlueprintStorage` | enum | public | — |
| `Capability\CapabilityState` | enum | public | Closed active, planned, and not-needed vocabulary serialized by the provider-neutral site manifest |
| `Doctor\FindingSeverity` | enum | public | — |
| `Generation\ArtifactApplyOutcome` | enum | public | Closed planned/applied/no_changes/cancelled/refused apply outcome |
| `Generation\ArtifactSetEvolution` | enum | public | Closed frozen/additive declaration of whether a compiler may render a superset of its unit's recorded path set |
| `Generation\ArtifactStatus` | enum | public | Closed created/changed/unchanged/refused per-path evaluation outcome |
| `Generation\ChangeOutcome` | enum | public | Closed applied/no_op/refused/failed/recovered governed-change receipt outcome |
| `Generation\Exception\GenerationErrorCode` | enum | public | Closed GEN001-GEN015 refusal ids for the generation execution and plan boundary, reserved by ADR-025 D-5 |
| `Generation\GenerationUnitDisposition` | enum | public | Closed managed/seeded vocabulary for how a generation unit's artifacts are treated after publication |
| `Generation\ObservedTargetMode` | enum | public | Closed 0644/0755/other/unknown record of the permission bits evaluation observed |
| `Generation\ObservedTargetState` | enum | public | Closed absent/file/other record of what evaluation observed at one target path |
| `Generation\SiteRecipeRendererInterface` | interface | public | — |
| `ManifestShapeReader` | trait | internal | — |
| `Version\ManifestVersionDisposition` | enum | public | Closed current, migration-required, and unsupported-future schema-version decision |

### state

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `EntityPayloadBoundaryConfig` | final readonly class | public | Explicit dormant/enforced state entity-payload write mode |
| `Exception\EntityProjectionWriteForbidden` | final class | public | Activated state rejection before an entity graph can be retained |
| `ProjectionDeprecationDiagnostic` | final class | public | Deduplicated dormant entity-payload diagnostic wired into memory/SQL state writes |
| `PublicStateProjection` | final readonly class | public | Identifier plus explicitly Public scalar/array values; grants no protected/internal authority |
| `StateInterface` | interface | internal | — |

### typed-data

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Coercion\CoercionException` | final class | public | Thrown when entity-parity primitive/JSON-array coercion fails (#1185) |
| `Coercion\EntityCastCoercion` | final class | public | Storage ↔ domain coercion for `int`/`float`/`bool`/`string`/`array` casts (#1185) |
| `DataDefinitionInterface` | interface | public | Describes a typed data property: type, label, required, read-only, constraints (extended by `FieldDefinitionInterface`) |

## Layer 1: Core Data

### access

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AccessPolicyInterface` | interface | public | Checks entity-level access for view, update, delete, and (M-006 / ADR 017) `'translate'` operations |
| `AccessStatus` | enum | public | — |
| `AccountInterface` | interface | public | Represents a user account for access checking: ID, roles, and permission checks |
| `AccountPrincipalFactory` | final readonly class | public | Principal/entity-account snapshotter that refuses lossy conversion of arbitrary plain accounts |
| `AccountPrincipalFactoryInterface` | interface | public | Closed bootstrap seam for passing through principals or snapshotting an entity-backed account through the audited reader |
| `AuthorizationPrincipalBootstrapReaderInterface` | interface | public | Closed bridge for strictly audited immutable principal construction from an entity-backed account |
| `AuthorizationPrincipalInterface` | interface | public | Immutable account-facing claims used by protected field-read policies without reading an acting User entity |
| `Capability\CapabilityActorSemantics` | enum | public | Explicit account, anonymous, system-service, or no-acting-context attribution for capability issuance and ledger reservations |
| `Capability\CapabilityExecutionBoundary` | final readonly class | public | Opaque, non-serializable proof whose registry-owned identity must be live and match at capability issuance and use |
| `Capability\CapabilityReason` | enum | public | Closed reason vocabulary for privileged field reads |
| `Capability\CapabilityRegistryInterface` | interface | public | Kernel registry for reviewed, exact value-read and query-read capability declarations and one-boundary handles |
| `Capability\QueryFieldOperation` | enum | public | Closed non-public query operation vocabulary: predicate, sort, aggregate, count, exists |
| `ClassifiedProtectedEntityReadPolicyInterface` | interface | internal | — |
| `ContextAwareAccessPolicyInterface` | interface | public | Companion to `AccessPolicyInterface` accepting a `$context` array (carries `langcode` for the `'translate'` operation and read-time langcode for `view`/`update`) (M-006, WP09) |
| `Context\AccountContextInterface` | interface | public | — |
| `Context\AccountFieldReadScopeInterface` | interface | public | Fiber-local, nested account principal scope restored in `finally`; it carries no privileged authority |
| `Context\FastAccountFieldReadScopeInterface` | interface | internal | Internal compiled-read fast path exposing the current immutable account context without widening public authority |
| `ContextualAccountPrincipalFactoryInterface` | interface | public | HTTP companion that binds resolved tenant/community dimensions to the immutable principal snapshot |
| `ContextualProtectedEntityReadPolicyInterface` | interface | internal | — |
| `DelegatingAuthorizationPrincipal` | final readonly class | public | Explicit legacy migration principal with provider-owned claims metadata and verbatim delegated account authorization behavior |
| `EntityViewProtectedFieldReadPolicyInterface` | interface | internal | — |
| `ErrorPageRendererInterface` | interface | internal | — |
| `FieldAccessPolicyInterface` | interface | public | Checks field-level access on an entity; open-by-default (Forbidden restricts, Neutral allows) |
| `Gate\GateInterface` | interface | public | Resolves the policy for a subject and checks whether a user has a given ability |
| `Gate\ListingFastPathProbeInterface` | interface | public | — |
| `Gate\RevisionAccessRouter` | final class | public | — |
| `Middleware\FieldReadContextMiddleware` | final readonly class | public | Priority-15 HTTP seam that installs/restores the immutable principal after identity resolution and wraps deferred streams |
| `PermissionHandlerInterface` | interface | public | Manages the registry of available permissions and their metadata |
| `PolicySubjectViewInterface` | interface | public | Closed view limited to compiled `authorizationInput` subject fields |
| `Policy\RevisionPolicyComposition` | final readonly class | public | — |
| `ProjectedProtectedEntityReadPolicyInterface` | interface | internal | — |
| `ProtectedEntityReadPolicyInterface` | interface | public | Fail-closed V2 entity-read policy over immutable principal, structural identity, and exact compiled subject inputs |
| `ProtectedFieldReadPolicyInterface` | interface | public | Dedicated fail-closed Protected read policy; only explicit Allowed will release a value after activation |
| `ProtectedReadPolicyProviderInterface` | interface | public | Additive companion through which a discovered legacy policy exposes its entity and field V2 read policies |
| `Query\QueryFieldReadRequest` | final readonly class | public | Metadata-only query compiler input retaining exact fields/operations and an irreversible normalized-shape fingerprint |
| `User\UserAuthorizationSnapshot` | final readonly class | public | — |
| `User\UserCredentialSnapshot` | final readonly class | public | — |
| `User\UserIdentityLookupInterface` | interface | public | Closed audited active-login, mail-only recovery, and mail-existence query boundary |
| `User\UserInternalFieldReaderInterface` | interface | public | Narrow reason-specific User credential, session, mail, verification, 2FA, and maintenance read boundary |
| `User\UserMailSnapshot` | final readonly class | public | — |
| `User\UserSelfProfileReaderInterface` | interface | public | — |
| `User\UserSessionSnapshot` | final readonly class | public | — |
| `User\UserTwoFactorSnapshot` | final readonly class | public | — |
| `User\UserVerificationSnapshot` | final readonly class | public | — |

- `User*Snapshot` (final readonly classes): Typed exact User internal inputs returned without exposing arbitrary field-name authority

### audit

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AuditedFieldRead` | final readonly class | public | Exact-declaration privileged reader that reserves strict-ledger metadata before accessor evaluation and finalizes its outcome |
| `AuditedQueryFieldRead` | final readonly class | public | Dormant exact-capability compiler boundary that reserves non-public query metadata before execution |
| `AuditedQueryReservation` | final class | public | One-shot explicit success/failure finalizer for a reserved query operation |
| `Bootstrap\AuditedUserIdentityLookup` | final readonly class | public | Reserves and finalizes exact non-public login/mail repository queries |
| `Bootstrap\AuditedUserInternalFieldReader` | final readonly class | public | Issues, consumes, and revokes exact framework User value-read capabilities |
| `Bootstrap\CredentialBootstrapReader` | final readonly class | public | Reason-constrained strictly audited credential verification reader |
| `Bootstrap\IdentityBootstrapReader` | final readonly class | public | Per-snapshot capability issuer that builds immutable principals and revokes bootstrap authority in `finally` |
| `Bootstrap\SessionBootstrapReader` | final readonly class | public | Reason-constrained strictly audited session and authorization-claims reader |
| `Contract\AuditQueryInterface` | interface | public | — |
| `Contract\AuditWriteFailureObserver` | interface | public | — |
| `Contract\AuditWriterInterface` | interface | public | — |
| `Contract\BatchStrictPrivilegedReadLedgerInterface` | interface | public | Transactional batch extension that keeps descriptors entity-scoped while making related reservations and outcomes all-or-nothing |
| `Contract\PrivilegedReadKind` | enum | public | Distinguishes explicit value-read reservations from query-field reservations |
| `Contract\PrivilegedReadOutcome` | enum | public | Strict-ledger final outcomes: succeeded or failed; interrupted reservations remain unfinished and visible |
| `Contract\StrictPrivilegedReadLedgerInterface` | interface | public | Synchronously reserves non-value metadata before a privileged read and finalizes its outcome afterward |
| `Enum\AuditEventKind` | enum | public | — |
| `Integrity\CheckpointSink` | interface | public | — |
| `ReadModel\AuditReadModelDefinitionRegistry` | final class | public | Exact read classifications for every column of the deliberately unregistered flat audit tables |
| `Writer\DatabaseStrictPrivilegedReadLedger` | final readonly class | public | Durable immutable-event ledger with atomic single-finalization and caller-transaction composition |

### auth

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AtomicRateLimiterInterface` | interface | public | — |
| `Config\MailMissingPolicy` | enum | public | — |
| `Extension\AuthMailContentPolicyInterface` | interface | public | — |
| `Extension\AuthRedirectPolicyInterface` | interface | public | — |
| `Extension\InitialRolePolicyInterface` | interface | public | — |
| `Extension\ProvidesAuthExtensionsInterface` | interface | public | — |
| `Extension\RegistrationPolicyInterface` | interface | public | — |
| `Extension\RegistrationProfileHandlerInterface` | interface | public | — |
| `Password\LegacyPasswordVerifierInterface` | interface | public | — |
| `RateLimiterInterface` | interface | internal | — |
| `Token\AuthTokenRepositoryInterface` | interface | internal | — |
| `Token\Bearer\BearerTokenStoreInterface` | interface | public | — |

### config

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Activation\ConfigurationActivationAuthorizerInterface` | interface | public | — |
| `Activation\ConfigurationActivatorInterface` | interface | public | — |
| `Activation\ConfigurationCandidateMaintenanceInterface` | interface | public | — |
| `Activation\ConfigurationCandidateSweepAuthorizerInterface` | interface | public | — |
| `Activation\ConfigurationGenesisActivatorInterface` | interface | public | — |
| `Activation\ConfigurationRollbackValidatorInterface` | interface | public | — |
| `Audit\ConfigAuditChannel` | final class | public | `CHANNEL` constant = `'config.audit'` (charter §4.4 amendment) (M-003, WP07) |
| `Audit\ConfigAuditEvent` | final readonly class | public | Entity-type, id, operation, actor, before-after diff summary (M-003, WP07) |
| `Authority\ActiveConfigurationBridgeInterface` | interface | public | — |
| `Authority\ConfigurationGenerationResolverInterface` | interface | public | — |
| `Backend\BackendRestrictionEnforcer` | final class | public | Boot-time enforcement: config entities must declare `sql-blob` or `sql-column`; `ALLOWED_BACKEND_IDS` constant (M-003, WP08) |
| `ConfigFactoryInterface` | interface | public | Creates and caches `ConfigInterface` instances by name |
| `ConfigInterface` | interface | public | Read/write access to a named configuration object with key-path addressing |
| `ConfigManagerInterface` | interface | public | Manages config storage backends and export/import lifecycle |
| `Dependency\ConfigDependencyInterface` | interface | public | Config-entity contract: `configDependencies(): string[]` returns `<entity_type>.<entity_id>` ids consumed by the DAG (M-003, WP01) |
| `Dependency\DependencyGraph` | final readonly class | internal | — |
| `Dependency\DependencyResolver` | final class | internal | — |
| `Dependency\Exception\ConfigDependencyCycleException` | final class | public | Raised when the sync-store DAG contains a cycle; carries the full cycle path (M-003) |
| `Dependency\Exception\ConfigDependencyMissingException` | final class | public | Raised when a `_meta.dependencies` entry references a config id absent from both stores (M-003) |
| `Drift\ConfigDriftSnapshotReaderInterface` | interface | public | — |
| `Event\ConfigEvents` | enum | public | — |
| `Exception\ConfigCommandCollisionException` | final class | public | Raised at boot when an app/extension command claims a reserved `config:*` sub-verb (M-003, WP09) |
| `Exception\ConfigImportFailedException` | final class | public | Raised per-entity during `config:import`; carries entity id + cause (M-003, WP04) |
| `Exception\ConfigSerializationException` | final class | public | Raised on `_meta.entity_type` mismatch or other YAML format errors (M-003, WP02) |
| `Exception\ImmutableConfigException` | final class | public | — |
| `Exception\InvalidConfigBackendException` | final class | public | Raised when a config entity declares `vector` / `remote` / other disallowed backend (M-003, WP08) |
| `Manifest\ConfigManifestBundleSigner` | final readonly class | public | — |
| `Manifest\ConfigManifestEd25519Signer` | final class | public | — |
| `Manifest\ConfigManifestEd25519Verifier` | final readonly class | public | — |
| `Manifest\ConfigManifestEnvelopeFile` | final class | public | — |
| `Manifest\ConfigManifestSignatureVerifierInterface` | interface | public | — |
| `Manifest\ConfigManifestSignerInterface` | interface | public | — |
| `Manifest\ConfigManifestSigningResult` | final readonly class | public | — |
| `Manifest\ConfigManifestTrustPolicy` | final readonly class | public | — |
| `Manifest\ConfigReplayStateReaderInterface` | interface | public | — |
| `Schema\Ai\McpAuthMode` | enum | public | — |
| `Schema\Ai\McpAvailability` | enum | public | — |
| `Schema\ConfigSemanticValidatorInterface` | interface | public | — |
| `StorageInterface` | interface | public | Reads and writes raw configuration data arrays by name |
| `Sync\ConfigDiffer` | final class | public | Unified-diff renderer with UUID-tracked rename detection; backs `config:diff` (M-003, WP05) |
| `Sync\ConfigExportFileResult` | final readonly class | internal | — |
| `Sync\ConfigExportResult` | final readonly class | internal | — |
| `Sync\ConfigExporter` | final class | public | Active → sync; backs `config:export`; honours `--diff` and `--dry-run` (M-003, WP03) |
| `Sync\ConfigImportApplyHookInterface` | interface | public | Cross-cutting hook fired per applied entity during `config:import` (extension point) (M-003, WP04) |
| `Sync\ConfigImportEntryResult` | final readonly class | internal | — |
| `Sync\ConfigImportPreflightInterface` | interface | public | — |
| `Sync\ConfigImportResult` | final readonly class | internal | — |
| `Sync\ConfigImporter` | final class | public | Sync → active in topological order; per-entity transaction; orphan-warn default (M-003, WP04) |
| `Sync\ConfigManifestEntry` | final readonly class | public | Per-entity manifest row consumed by exporter/importer dashboards (M-003) |
| `Sync\ConfigResetter` | final class | public | Single-entity rollback from sync store; logs to `config.audit`; backs `config:reset` (M-003, WP07) |
| `Sync\ConfigStatusReporter` | final class | public | Computes in-sync / drift / sync-only / active-only counts; backs `config:status` (M-003, WP05) |
| `Sync\ConfigSyncDeserializer` | final class | public | YAML → `ConfigSyncFile`; validates `_meta.entity_type` matches filename prefix (M-003, WP02) |
| `Sync\ConfigSyncFile` | final readonly class | public | In-memory parsed sync file: `_meta` block + field values (M-003, WP02) |
| `Sync\ConfigSyncFileSourceInterface` | interface | public | Extension point: alternative sync-file sources (e.g. in-memory test sources) (M-003) |
| `Sync\ConfigSyncRepository` | final class | public | Filesystem read/write under `config.sync_path` (default `storage/config-sync/`) (M-003, WP02) |
| `Sync\ConfigSyncSerializer` | final class | public | Entity → YAML; sorts keys alphabetically; emits leading `_meta` block (M-003, WP02) |
| `Sync\ConfigSyncValidator` | final class | public | Runs `FieldDefinition::validators()` over each sync file; powers `config:validate` (M-003, WP06) |
| `Sync\ConfigValidateEntry` | final readonly class | internal | — |
| `Sync\ConfigValidateResult` | final readonly class | internal | — |
| `Sync\DiffResult` | final readonly class | internal | — |
| `Sync\FieldValueMapper` | final class | internal | — |
| `Sync\FieldViolation` | final readonly class | internal | — |
| `Sync\SignedEnvelopeConfigImportPreflight` | final readonly class | public | — |
| `Sync\StatusEntry` | final readonly class | internal | — |
| `Sync\StatusReport` | final readonly class | internal | — |
| `TranslatableConfigFactoryInterface` | interface | public | Creates language-specific overrides of configuration objects |

- `Waaseyaa\CLI\Command\Config\ConfigCommand` (abstract class): Base for the seven `config:*` commands; exposes `RESERVED_VERBS`, `RESERVED_FULL_VERBS`, `RESERVED_FQCNS` constants for collision checks (M-003, WP09)
- `Waaseyaa\CLI\Command\Config\ConfigExportCommand` (command): `bin/waaseyaa config:export [--diff] [--dry-run]` (M-003, WP03)
- `Waaseyaa\CLI\Command\Config\ConfigImportCommand` (command): `bin/waaseyaa config:import [--dry-run] [--delete-orphans] [--halt-on-error] [--no-dependency-check]` (M-003, WP04)
- `Waaseyaa\CLI\Command\Config\ConfigDiffCommand` (command): `bin/waaseyaa config:diff [<entity-type>.<id>]` (M-003, WP05)
- `Waaseyaa\CLI\Command\Config\ConfigStatusCommand` (command): `bin/waaseyaa config:status [--format=plain|json]` (M-003, WP05)
- `Waaseyaa\CLI\Command\Config\ConfigValidateCommand` (command): `bin/waaseyaa config:validate` (M-003, WP06)
- `Waaseyaa\CLI\Command\Config\ConfigResetCommand` (command): `bin/waaseyaa config:reset <entity-type>.<id> [--yes]` (M-003, WP07)

### entity

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `ApiExposableEntityTypeInterface` | interface | public | — |
| `Audit\EntityAuditKey` | enum | public | — |
| `Audit\LifecycleAuditKey` | enum | public | — |
| `Cast\FromArrayEntityValueInterface` | interface | public | — |
| `Community\HasCommunityInterface` | interface | public | — |
| `Community\HasCommunityTrait` | trait | public | — |
| `ConfigEntityBase` | abstract class | public | Base for configuration entities with string machine-name IDs and enable/disable lifecycle |
| `ConfigEntityInterface` | interface | public | Contract for configuration entities including status and enabled/disabled state |
| `ContentEntityBase` | abstract class | public | Fieldable entity base supporting dynamic field values and per-language translation |
| `ContentEntityInterface` | interface | public | Marker combining `EntityInterface` and `FieldableInterface` for content entities |
| `DateTime\EntityClockInterface` | interface | public | — |
| `DateTime\FixedEntityClock` | final class | public | — |
| `DateTime\TimestampFieldConvention` | final class | public | — |
| `DateTime\UtcEntityClock` | final class | public | — |
| `DefinesEntityType` | interface | public | — |
| `EntityBase` | abstract class | public | Default implementations of `EntityInterface`; subclasses hardcode entity type ID and keys |
| `EntityInterface` | interface | public | Core contract for all entity types: identity, label, type ID, and value access |
| `EntitySerializationBoundary` | final readonly class | public | Explicit dormant/enforced PHP serialization boundary for value-bearing entities |
| `EntitySerializationBoundaryConfig` | final readonly class | public | Exact activation toggle for entity PHP serialization rejection |
| `EntityTypeForeignKeyDefinitionInterface` | interface | public | — |
| `EntityTypeInterface` | interface | public | Describes an entity type: ID, label, class, keys, field definitions, and constraints |
| `EntityTypeManagerInterface` | interface | public | Registers entity types and provides storage instances for each |
| `EntityTypeStorageSchemaTransitionDefinitionInterface` | interface | internal | — |
| `EntityTypeStorageUniqueKeyDefinitionInterface` | interface | internal | — |
| `EntityValueReadGuardInterface` | interface | internal | Internal sealed-entity adapter for protected-field read decisions and decision-cache invalidation |
| `EntityValues` | final class | public | — |
| `Event\EntityEvent` | class | public | Lifecycle event base; non-`final` so `TranslationEvent` may extend it (M-006, WP08 — public-surface change documented in mission reconciliation note) |
| `Event\EntityEventFactoryInterface` | interface | public | Creates `EntityEvent` instances with optional before/after snapshots |
| `Event\EntityEvents` | enum | public | — |
| `Event\TranslationEvent` | final class | public | Translation lifecycle event extending `EntityEvent`; carries entity, target langcode, and (for updates/deletes) prior values (M-006, WP08). Event-name constants: `PRE_TRANSLATION_INSERT`, `POST_TRANSLATION_INSERT`, `PRE_TRANSLATION_UPDATE`, `POST_TRANSLATION_UPDATE`, `PRE_TRANSLATION_DELETE`, `POST_TRANSLATION_DELETE` |
| `Exception\EntityTranslationException` | final class | public | Translation persistence error with named-constructor factories `langcodeRequired`, `cannotRemoveDefault`, `translationAlreadyExists`, `translationNotFound` (M-006, WP01) |
| `Exception\FieldAccessActivationBlocked` | final class | public | Checksum-backed hard failure when preflight blockers remain |
| `FieldReadLevel` | enum | public | Additive `public` / `protected` / `internal` definition metadata; dormant until the no-shim activation work package |
| `Field\BundleStorageUniqueKeyRegistryInterface` | interface | internal | — |
| `Field\FieldDefinitionRegistryInterface` | interface | public | — |
| `Field\FieldReadLayoutGenerationSourceInterface` | interface | internal | — |
| `FieldableInterface` | interface | public | Marks an entity as supporting named field access and definition retrieval |
| `Hydration\FallbackChainResolver` | final readonly class | public | — |
| `Hydration\HydratableFromStorageInterface` | interface | public | — |
| `Hydration\HydrationContext` | final readonly class | public | — |
| `Repository\EntityRepositoryInterface` | interface | public | High-level CRUD API handling hydration, event dispatch, and language fallback. Adds `findTranslations(EntityInterface): array<string, EntityInterface>` (M-006, WP10) |
| `RevisionMetadata` | final readonly class | public | — |
| `RevisionableEntityInterface` | interface | public | — |
| `RevisionableEntityTrait` | trait | public | Default implementation of `RevisionableInterface` using `$values` and `$entityKeys` |
| `RevisionableInterface` | interface | public | Adds revision ID tracking and new-revision control to an entity |
| `Snapshot\EntityValuesSnapshot` | final readonly class | public | — |
| `Storage\EntityQueryInterface` | interface | public | Fluent query builder for filtering and loading entities by field conditions |
| `Storage\EntityStorageInterface` | interface | public | Lower-level storage operations: load, save, delete, query |
| `Storage\RevisionableStorageInterface` | interface | public | Extends entity storage with load, delete, and list operations for specific revisions |
| `Testing\Translation\TranslatableEntityContractTest` | abstract class | public | — |
| `TranslatableEntityTrait` | trait | public | — |
| `TranslatableInterface` | interface | public | Per-language translation access for a translatable entity (M-006 / ADR 017): `getTranslation`, `hasTranslation`, `addTranslation`, `removeTranslation`, `translations`, `defaultLangcode`, `activeLangcode`, `fieldLangcode`. `language()` retained as deprecated alias for `activeLangcode()` |
| `Validation\RedactedInvalidValue` | enum | internal | Internal value-free sentinel used when validation reports a restricted invalid field |
| `Validation\ValidationReadLedgerInterface` | interface | internal | Internal validation adapter that reserves an authorized restricted-field read before value access |
| `Validation\ValidationReadReservationInterface` | interface | internal | Internal one-shot reservation that records validation-read success or failure without retaining the value |

- `EntityType::__construct(...translatable: bool = false, ...)` (constructor arg): Marks an entity type as translatable; load-bearing — enforced by boot validation (M-006, WP02)

### entity-storage

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AggregateMutationRepositoryInterface` | interface | internal | — |
| `BackendResolver` | final class | public | Resolves which backend handles a given `FieldDefinition` (M-001, WP02) |
| `Backend\BackendRegistrar` | final class | public | Registers field storage backends by id for an entity type (M-001, WP01) |
| `Backend\BackendRegistrarFactory` | final class | public | Creates a `BackendRegistrar` bound to a specific entity type (M-001, WP01) |
| `Backend\FieldStorageBackendGateway` | final class | public | Registrar-owned V2 facade that reserves strict audit and never exposes the implementation (#2064 WP3) |
| `Backend\FieldStorageBackendV2Interface` | interface | public | Active fingerprinted privileged backend SPI; accepts only registrar-issued roles and opaque inputs (#2064 WP4) |
| `Backend\FieldStorageGatewayAttempt` | final readonly class | public | Value-free strict-audit descriptor reserved before invocation (#2064 WP3) |
| `Backend\FieldStorageGatewayAuditReceipt` | final readonly class | public | Opaque receipt for strict attempt finalization (#2064 WP3) |
| `Backend\FieldStorageGatewayFailure` | final readonly class | public | Value-free failure record including whether backend invocation began (#2064 WP3) |
| `Backend\FieldStorageGatewayInput` | final class | public | Opaque non-serializable boundary-bound backend input handle (#2064 WP3) |
| `Backend\FieldStorageGatewayInvocation` | final readonly class | public | Backend-only invocation exposed after successful role/boundary validation (#2064 WP3) |
| `Backend\FieldStorageGatewayOperation` | enum | public | Closed read/write/delete/query-support invocation vocabulary (#2064 WP3) |
| `Backend\FieldStorageGatewayOutput` | final class | public | Opaque non-serializable boundary-bound backend result handle (#2064 WP3) |
| `Backend\FieldStorageGatewayRole` | final class | public | Opaque registrar-issued role required to unwrap inputs and construct results (#2064 WP3) |
| `Backend\HasFieldStorageBackendsV2Interface` | interface | public | Provider capability for reviewed V2 field-storage implementations (#2064 WP3) |
| `Backend\IsFrameworkBackendProviderV2Interface` | interface | public | Marker allowing framework-owned V2 providers to claim reserved backend ids (#2064 WP3) |
| `Backend\ReservedBackendIds` | final class | public | String constants for built-in backend ids: `SQL_BLOB`, `SQL_COLUMN`, `VECTOR` (M-001, WP01) |
| `Backend\SqlBlobBackend` | final class | public | Stores field values in a JSON `_data` blob column; `supportsQuery()` always false (M-001, WP03) |
| `Backend\SqlColumnBackend` | final class | public | Stores each field in a dedicated SQL column; `supportsQuery()` true for non-vector types (M-001, WP05) |
| `Backend\SqlColumnQueryTranslator` | final class | public | Translates field-level query predicates to SQL for `SqlColumnBackend` (M-001, WP05) |
| `Backend\SqlColumnSchemaBuilder` | final class | public | Builds SQL column schema for `SqlColumnBackend` (M-001, WP05) |
| `Backend\StrictFieldStorageGatewayAuditInterface` | interface | public | Synchronous reserve/success/failure audit required for V2 gateway activation (#2064 WP3) |
| `Backend\TypeMapping` | final class | public | Maps `FieldDefinition` type strings to DBAL column types (M-001, WP05) |
| `Connection\ConnectionResolverInterface` | interface | public | Resolves named database connections; multi-tenancy seam for entity storage |
| `ContextualEntityLoaderInterface` | interface | internal | — |
| `CoordinatorLifecycleDispatcher` | final class | public | Dispatches lifecycle events from the coordinator (M-001, WP04) |
| `Driver\EntityStorageDriverInterface` | interface | public | Low-level persistence SPI: raw row I/O without hydration or event dispatch. Adds `findTranslations(EntityInterface): array<string, EntityInterface>` (M-006, WP10) |
| `Driver\EntityStorageDriverV2Interface` | interface | public | Additive boundary-bound opaque-row storage SPI: reads return `StorageRow`/`StorageRowSet` and writes accept `StorageSnapshot`; unrelated boundary tokens cannot unwrap them, and V1 remains behavior-compatible during dormant stages |
| `Driver\InMemoryStorageDriverV2` | final readonly class | public | Opaque V2 adapter over the first-party in-memory ordinary storage backend |
| `Driver\LangcodePeerStorageDriverV2Interface` | interface | public | Optional opaque-snapshot capability for tenancy-safe `(entity id, langcode)` peer writes without collapsing sibling languages |
| `Driver\RevisionableStorageDriver` | final class | public | Driver-level two-axis save/load orchestration: composes `RevisionTableBuilder` + `TranslationSchemaHandler` and honours `SaveContext::withTranslations()` for atomic multi-language revision writes (M-004, WP03 + WP04) |
| `Driver\RevisionableStorageDriverV2` | final readonly class | public | Opaque V2 adapter used by the repository for the first-party revision/langcode storage backend |
| `Driver\RevisionableStorageDriverV2Interface` | interface | public | Additive opaque revision SPI mirroring current revision and langcode operations while replacing raw read/write arrays with boundary-bound rows and snapshots |
| `Driver\SqlStorageDriverV2` | final readonly class | public | Opaque V2 adapter over the first-party SQL ordinary storage backend |
| `EntityStorageCoordinator` | final class | public | Fan-out engine dispatching read/write/delete to all registered backends (M-001, WP02) |
| `Event\AbortOperationException` | final class | public | Thrown from `BeforeSave`/`BeforeDelete` listener to abort the operation (M-001, WP04) |
| `Event\AfterDeleteEvent` | final class | public | Dispatched after all backends confirm delete (M-001, WP04) |
| `Event\AfterSaveEvent` | final class | public | Dispatched after all backends commit; not dispatched on partial failure (M-001, WP04) |
| `Event\BeforeDeleteEvent` | final class | public | Dispatched before any backend delete (M-001, WP04) |
| `Event\BeforeSaveEvent` | final class | public | Dispatched before any backend write; listeners may abort via `AbortOperationException` (M-001, WP04) |
| `Event\EntityLifecycleEventInterface` | interface | public | Marker for all four coordinator lifecycle events (M-001, WP04) |
| `Event\EntityMutationAuthorityBackfilledEvent` | final readonly class | public | — |
| `Exception\BundleAmbiguousFieldException` | final class | public | — |
| `Exception\BundleUniqueKeyConflictException` | final class | public | Stable repository conflict for a database-enforced bundle key (`BUNDLE_UNIQUE_KEY_CONFLICT`) (#2603) |
| `Exception\BundleUniqueKeyMigrationException` | final class | public | Stable schema-sync refusal when existing bundle rows duplicate a declared key (`bundle_unique_key_duplicates`) (#2603) |
| `Exception\PartialSaveException` | final class | public | Thrown on backend fan-out failure, including first-backend failure with an empty committed set; carries `$errorCode` (M-001, WP04) |
| `Exception\StorageMigrationException` | final class | public | Typed exception for storage-migration / two-axis schema failures during kernel boot, schema sync, or migration generator runs. Stable `errorCode` strings: `no_op_promotion`, `unsupported_two_axis_field` (M-004, WP04) |
| `Exception\UnknownBackendException` | final class | public | Thrown when a field references an unregistered backend id (M-001, WP02) |
| `Exception\UnknownFieldException` | final class | public | — |
| `Exception\UnsupportedListingException` | final class | public | Thrown when listing is unsupported by the active backend (M-001, WP06) |
| `Exception\UnsupportedQueryException` | final class | public | Thrown when a query operator is unsupported by the active backend (M-001, WP01) |
| `Hydration\EntityInstantiator` | final class | internal | — |
| `LegacyMutationAuthorityBackfillRepositoryInterface` | interface | internal | — |
| `Listing\TwoAxisFilterResolver` | final class | public | Resolves listing filters against two-axis storage: joins `<entity>__revision` to `<entity>__translation__revision` and applies langcode + revision-window selection (M-004, WP07) |
| `Query\DefinitionValidator` | final class | public | Validates `FieldDefinition` objects at registration time; throws `UnsupportedQueryException` (M-001, WP06) |
| `Query\EntityQuery` | interface | public | — |
| `RevisionPruningPolicy` | final class | public | Immutable value object describing how many revisions to keep (M-001, WP08) |
| `RevisionPruningReport` | final class | public | Result of a pruning run: counts of deleted and retained revisions (M-001, WP08) |
| `Revision\RevisionPruningPolicy` | final class | public | Two-axis pruning policy value object; keeps the M-001 `RevisionPruningPolicy` surface intact while extending semantics to per-langcode revision counts (M-004, WP05) |
| `SaveContext` | final class | public | Immutable value object passed to save operations; carries revision flags and translation langcode. `withLangcode(string $langcode): self` returns an immutable copy targeting a translation write (M-001, WP04 + M-006, WP07) |
| `Schema\EntityStorageSchemaTransitionInterface` | interface | internal | — |
| `Schema\RevisionTableBuilder` | final class | public | Creates the `{entity_type}_revision` schema table (M-001, WP07) |
| `Schema\TranslationSchemaHandler` | final class | public | Emits the `<entity>__translation__revision` table for two-axis entities; pairs with `RevisionTableBuilder::buildTwoAxis()` (M-004, WP02) |
| `Tenancy\CommunityTranslationPeerRepairReport` | final readonly class | public | Machine-readable examined, eligible, repaired, skipped, and dry-run counts from translation-peer repair |
| `Tenancy\CommunityTranslationPeerRepairer` | final readonly class | public | Explicit fail-closed repair for legacy translation peers with empty community discriminators |

- `EntityRepository::findTranslations(EntityInterface): array<string, EntityInterface>` (method): Returns every translation of the given entity, keyed by langcode, default-langcode first; single SQL query (M-006, WP10)
- `SaveContext::withTranslations(array $langcodes): self` (method): Immutable copy carrying a `[langcode => values]` map for atomic multi-language revision writes; rejected if empty. Pairs with `withLangcode()` for single-language writes (M-004, WP03)
- `EntityTranslationException::historicalRevisionWrite(int $vid, string $langcode): self` (factory): Raised when a write targets a historical (non-tip) revision in a two-axis entity; stable `errorCode` `historical_revision_write` (M-004, WP04)

### field

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AbstractFieldType` | abstract class | public | — |
| `Classification\ClassificationClearanceCheckerInterface` | interface | public | — |
| `Classification\ClassificationLabelRegistryInterface` | interface | public | — |
| `Classification\ClassificationParentResolverInterface` | interface | public | — |
| `FieldDefinitionInterface` | interface | public | Describes a field: type, label, cardinality, settings, and constraints |
| `FieldFormatterInterface` | interface | public | Plugin interface for rendering a field item list for display |
| `FieldReadDefinitionInterface` | interface | public | Additive companion exposing nullable read classification without changing third-party `FieldDefinitionInterface` implementations |
| `FieldReadMetadataSource` | enum | public | Records whether classification came from a definition, legacy internal setting, site artifact, or remains unclassified |
| `FieldStorage` | enum | public | — |
| `FieldStorageSchemaContext` | enum | public | — |
| `FieldTypeInterface` | interface | public | Plugin interface for field type implementations providing column and property schemas |
| `FieldTypeManagerInterface` | interface | public | Discovers field type plugins and provides their default settings and column definitions |
| `FieldValueKind` | enum | public | — |
| `FieldValueKindProviderInterface` | interface | public | — |
| `FieldValueKindResolverInterface` | interface | public | — |
| `Item\LabeledCase` | interface | public | — |
| `ViewModeConfigInterface` | interface | public | Configures which fields and formatters are active for a given view mode |

- `FieldItemInterface` (interface): A single typed value within a field list, with property accessors and emptiness check
- `FieldItemListInterface` (interface): An ordered list of `FieldItemInterface` values for one field on one entity
- `FieldDefinition::translatable(bool $translatable = true): self` (builder method): Marks a field as translatable (per-langcode value). Calling on a non-translatable `EntityType`'s field fails at boot (M-006, WP03)
- `FieldDefinition::isTranslatable(): bool` (reader): Returns whether the field carries per-language values (M-006, WP03)
- `FieldItemBase` (abstract class): Base field item implementation combining plugin and typed-data behavior

### oidc

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Key\SigningKeyEmergencyRevocationService` | final readonly class | public | — |
| `Key\SigningKeyLifecyclePolicy` | final readonly class | public | — |
| `Key\SigningKeyRepository` | final class | public | — |
| `Key\SigningKeyRevocationRecord` | final readonly class | public | — |
| `Keys\OidcKeyLoaderInterface` | interface | public | — |
| `Keys\SigningAlgorithmPolicy` | final readonly class | public | — |
| `Keys\SigningKeySignerInterface` | interface | public | — |
| `Keys\SigningKeyState` | enum | public | — |
| `Rekey\AbstractOidcTokenRekeyAdapter` | abstract class | internal | — |
| `Repository\AuthorizationCodeRepositoryInterface` | interface | public | — |
| `Token\KeyMaterialProviderInterface` | interface | public | — |

### testing

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Clock\MutableEntityClock` | final class | public | — |
| `Database\TemporarySqliteDatabase` | final class | public | — |
| `Factory\AuthorizationPrincipalFactory` | final class | public | — |
| `Factory\EntityFactory` | final class | public | — |
| `Factory\EntityTypeFactory` | final class | public | — |
| `Factory\EntityTypeFixtureValues` | final class | public | — |
| `Filesystem\TemporaryDirectory` | final class | public | — |
| `Kernel\KernelServicesFixture` | final class | public | — |
| `Traits\CreatesApplication` | trait | public | Bootstraps a Waaseyaa application instance for test suites |
| `Traits\InteractsWithApi` | trait | public | HTTP request helpers for making API calls in tests |
| `Traits\InteractsWithAuth` | trait | public | Simulates acting as a specific user without a full auth subsystem |
| `Traits\InteractsWithEvents` | trait | public | Captures and asserts on dispatched domain events in tests |
| `Traits\RefreshDatabase` | trait | public | Wraps each test in a transaction and rolls back after, keeping the database clean |
| `WaaseyaaTestCase` | abstract class | internal | — |

### user

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Authentication\AuthenticationEligibilityInterface` | interface | public | — |
| `Authentication\AuthenticationStage` | enum | public | — |

## Layer 2: Content Types

### attachment

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Http\AttachmentDownloadMetadataReaderInterface` | interface | public | — |

### groups

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `StaffDirectory\StaffDirectoryPage` | final readonly class | public | — |
| `StaffDirectory\StaffDirectoryReadDeclaration` | final readonly class | public | — |
| `StaffDirectory\StaffDirectoryReaderInterface` | interface | public | — |

### media

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `FileRepositoryInterface` | interface | public | CRUD operations for file value objects keyed by URI |
| `Http\MediaDownloadSourceReaderInterface` | interface | public | — |

### relationship

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `EntityVisibilityFilterInterface` | interface | public | — |
| `VisibilityFilterInterface` | interface | public | Filters relationship results based on viewer access |

## Layer 3: Services

### billing

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `PlanTier` | enum | public | — |
| `StripeClientInterface` | interface | internal | — |

### listing

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `EntityRepositoryRegistry` | final class | internal | — |
| `Exception\ListingCoercionException` | final class | internal | — |
| `Exception\UnknownListingException` | final class | public | Registry miss (carries listing id) |
| `Exception\UnsupportedListingException` | final class | public | Definition-time validation failure (carries listing id, field name, reason) |
| `ExposedFilterCoercer` | final class | internal | — |
| `ExposedFilterParser` | final class | public | Parses query params into `ExposedFilterValues`; never throws on user input |
| `ExposedFilterValues` | final readonly class | public | Typed view over parsed `$_GET` slice passed to `ListingResolver::resolve()` |
| `Filter` | final class | public | Sugar factories: `eq()`, `gte()`, `in()`, `isNull()`, `langcode()`, `exposed()`, etc. |
| `FilterDefinition` | final readonly class | public | Field + operator + value; optional `exposedParam` for URL-driven filters |
| `HasListingsInterface` | interface | public | ServiceProviders implement to declare listings; mirrors `HasMigrationsInterface` |
| `ListingCacheInvalidator` | final class | internal | — |
| `ListingCacheKeyBuilder` | final class | internal | — |
| `ListingDefinition` | final readonly class | public | Immutable listing manifest: id, entity type, filters, sorts, page size, access ops |
| `ListingDefinitionRegistry` | final class | public | `get(string $id): ListingDefinition` — throws `UnknownListingException` on miss |
| `ListingDefinitionValidator` | final class | internal | — |
| `ListingDiscoverer` | final class | internal | — |
| `ListingResolver` | final class | public | Single public method `resolve(ListingDefinition, ?ExposedFilterValues): ListingResult` |
| `ListingResult` | final readonly class | public | Resolution result: rows + pagination + cache tags + cache contexts |
| `Operator` | enum | public | Filter vocabulary: EQ, NEQ, LT, LTE, GT, GTE, IN, NOT_IN, IS_NULL, IS_NOT_NULL, BETWEEN, STARTS_WITH, CONTAINS |
| `Pagination` | final readonly class | public | Page metadata: page, page size, total rows, total pages, hasPrev, hasNext |
| `Sort` | final class | public | Sugar factories: `asc()`, `desc()` |
| `SortDefinition` | final readonly class | public | Field + direction; resolver appends an implicit id tie-break sort |
| `SortDirection` | enum | public | ASC, DESC |

### migration

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `ContentModel\DerivesContentModelInterface` | interface | public | — |
| `Discovery\HasMigrationsInterface` | interface | public | Marker for service providers contributing migration manifests (FR-003, WP02) |
| `Exception\DestinationWriteException` | final class | public | Destination plugin failed to write; carries `$reason` code (WP05) |
| `Exception\MigrationAbortedException` | final class | public | Operator-triggered abort surfaced from runner / signal handler (WP06) |
| `Exception\MigrationConcurrencyException` | final class | public | Per-migration lock contention; carries `holdingPid` + `lockPath` (FR-061, WP09) |
| `Exception\MigrationCycleException` | final class | public | Dependency-graph cycle detected during discovery (WP02) |
| `Exception\MigrationDependencyMissingException` | final class | public | Migration depends on an unknown id (WP02) |
| `Exception\MigrationPluginCollisionException` | final class | public | Two plugins claim the same id; reserved-id collisions set `$isReserved=true` (WP01) |
| `Exception\ProcessException` | final class | public | Process plugin raised during per-field transformation (WP03) |
| `Exception\SourceReadException` | final class | public | Source plugin failed to read a record / opened-file errors (WP01) |
| `Log\Channels` | final class | public | Logger channel constants: `MIGRATION_DEPRECATION`, `MIGRATION_DISCOVERY` (WP01) |
| `MigrationDefinition` | final readonly class | public | Immutable migration definition: id, source, processors, destination, dependencies, stability (WP02) |
| `Plugin\DestinationPluginInterface` | interface | public | Destination plugin SPI: `write`, `rollback`, `lookup` per source id (FR-006, WP01) |
| `Plugin\DestinationRecord` | final readonly class | public | DTO carrying processed fields to a destination plugin: `entityType`, `bundle`, `fields`, `sourceId`, `sourceRecordHash` (WP01) |
| `Plugin\Destination\EntityDestination` | final class | public | Built-in destination writing to Waaseyaa entities via `EntityRepository` (FR-018..FR-029, WP05/WP08) |
| `Plugin\Destination\EntityDestinationFactory` | final class | public | Constructs `EntityDestination` instances bound to a migration id (WP05) |
| `Plugin\ProcessContext` | final readonly class | public | Per-field processor context carrying current record + run metadata (WP01) |
| `Plugin\ProcessPluginInterface` | interface | public | Per-field record transformer SPI (FR-005, WP01) |
| `Plugin\Process\ConcatProcessor` | final readonly class | public | Reference processor: concatenates multiple source fields (WP03) |
| `Plugin\Process\DefaultValueProcessor` | final readonly class | public | Reference processor: substitutes a default when the input is null/empty (WP03) |
| `Plugin\Process\HtmlSanitizeProcessor` | final readonly class | public | Reference processor: sanitises HTML field values (WP03) |
| `Plugin\Process\LookupProcessor` | final readonly class | public | Reference processor: resolves cross-migration lookups via `MigrationIdMap` (FR-028, WP03) |
| `Plugin\Process\PassThroughProcessor` | final readonly class | public | Reference processor: emits the input value unchanged (WP03) |
| `Plugin\Process\TypeCoerceProcessor` | final readonly class | public | Reference processor: coerces strings to int/float/bool (WP03) |
| `Plugin\ReservedPluginIds` | final class | public | Constants for framework-reserved plugin ids; collision raises `MigrationPluginCollisionException` (WP01) |
| `Plugin\SourcePluginInterface` | interface | public | Source plugin SPI: streams `SourceRecord` instances and assigns `SourceId`s (FR-049, WP01) |
| `Plugin\SourceRecord` | final readonly class | public | DTO carrying a raw row from a source plugin: `sourceType`, `fields` (WP01) |
| `Plugin\WriteResult` | final readonly class | public | Destination write outcome: `destinationEntityType`, `destinationUuid`, `sourceRecordHash`, `runId`, `writtenAt` (FR-006, WP01) |
| `Schema\MigrationIdMapSchema` | final class | public | DDL builder for the `migration_id_map` table (FR-029, WP04) |
| `Security\MigrationAuditedFieldReader` | final readonly class | public | Explicit `AuditedFieldRead::read` call site supplied to migration code |
| `Security\MigrationFieldReadCapabilityIssuer` | final readonly class | public | Issues NoActingContext MigrationImport capabilities from manifests |
| `Security\MigrationFieldReadManifest` | final readonly class | public | Exact privileged field reads reviewed for one migration id |
| `SourceId` | final readonly class | public | Stable composite key identifying a source record across re-runs (WP01) |
| `Testing\DestinationConformanceTestCase` | abstract class | public | Conformance harness third-party destination plugins extend (FR-050/FR-051, WP10; autoload-dev) |
| `Testing\SourceConformanceTestCase` | abstract class | public | Conformance harness third-party source plugins extend (FR-052, WP10; autoload-dev) |

### notification

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `ChannelInterface` | interface | public | Delivers a notification to a notifiable recipient via one transport |
| `NotifiableInterface` | interface | public | Marks a recipient as notification-capable and provides channel routing |
| `NotifiableTrait` | trait | public | Default `NotifiableInterface` implementation routing by channel for entity classes |
| `NotificationInterface` | interface | public | Defines which channels to deliver through and provides channel-specific payloads |

### page-builder

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Command\EditCommand` | interface | public | — |
| `Draft\AdvisoryAwareLayoutDraftGatewayInterface` | interface | public | — |
| `Draft\Exception\LayoutSaveAdvisoryException` | final class | public | — |
| `Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException` | final class | public | — |
| `Draft\InitialLayoutDocumentProviderInterface` | interface | public | — |
| `Draft\LayoutDraftGatewayInterface` | interface | public | — |
| `Draft\LayoutSaveAdvisoryAcknowledgementDispatcher` | final class | public | — |
| `Preview\RevisionPreviewGatewayInterface` | interface | public | — |
| `Preview\RevisionPreviewUrlGeneratorInterface` | interface | public | — |
| `Revision\PageBuilderRevisionGatewayInterface` | interface | public | — |

### publishing

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AdvisoryAwareContentDraftMutationInterface` | interface | public | — |
| `ContentDraftMutationInterface` | interface | public | — |
| `ContentHtmlSanitizerInterface` | interface | public | — |
| `ContentPublicationTransitionerInterface` | interface | public | — |
| `ContentRevisionHistoryInterface` | interface | internal | — |
| `ContentRevisionPreviewInterface` | interface | internal | — |
| `ContentValidatorInterface` | interface | public | — |
| `Exception\ContentPublishingException` | abstract class | public | — |
| `Exception\UnsupportedSaveAdvisoryAcknowledgementException` | final class | public | — |
| `SaveAdvisoryAcknowledgementDispatcher` | final class | public | — |

### search

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `BatchSearchIndexerInterface` | interface | public | — |
| `Projection\EntitySearchDocumentId` | final class | public | Creates stable search document identifiers for projected entities |
| `Projection\EntitySearchProjectionRegistry` | final class | public | Selects the first application or framework projector that supports an entity |
| `Projection\EntitySearchProjectorInterface` | interface | public | Projects a normal entity (Node) into an indexable search document without an upward search dependency |
| `Projection\NodeSearchProjector` | final class | public | Provides the framework default projection for public Node content |
| `Projection\SearchTextNormalizer` | final class | public | Converts CMS field values into inert plain searchable text |
| `ProvidesEntitySearchProjectorsInterface` | interface | public | Contributes application entity search projectors ordered ahead of the built-in node default |
| `ProvidesSearchSourceResolversInterface` | interface | public | Contributes exact-namespace resolvers for canonical non-entity search sources |
| `SearchCandidateResolverInterface` | interface | public | Resolves an opaque index pointer to a canonical principal-safe projection |
| `SearchContentCatalogueInterface` | interface | public | Lists and reads bounded canonical content projections under an explicit immutable principal |
| `SearchIndexableInterface` | interface | public | Marks an entity as searchable and provides its document ID and text fields |
| `SearchIndexerInterface` | interface | public | Adds, updates, and removes documents from the search index |
| `SearchProviderInterface` | interface | public | Executes principal-scoped full-text search queries and returns safe ranked results |

### seo

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Discovery\CrawlEligibilityPolicyInterface` | interface | public | Application-owned restriction of which entity types may be crawled |
| `Discovery\DiscoveryFailurePolicy` | enum | public | Selects empty-document degradation or propagation for a failed crawler surface |
| `Discovery\Exception\DiscoveryConfigurationException` | final class | public | Canonical URL policy is bound but no trusted public origin is configured |
| `Discovery\NonPublicEntityTypes` | final class | public | Framework-owned floor of entity types that are never crawled; applications may narrow it, never widen it |
| `Discovery\PublicUrlPolicyInterface` | interface | public | Application-owned canonical and Markdown-representation paths for the crawler-facing surfaces |
| `Discovery\SitemapContributorInterface` | interface | public | Contributes non-entity sitemap URLs without replacing the SEO controller |
| `Discovery\SitemapPath` | final readonly class | public | Validated root-relative sitemap entry returned by a contributor |

### structured-import

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Mapping\MappingConflictCode` | enum | public | — |
| `Mapping\MappingDecision` | enum | public | — |
| `StructuredImporterInterface` | interface | public | — |
| `Xlsx\XlsxCellType` | enum | public | — |
| `Xlsx\XlsxInspectionError` | enum | public | — |

### workflows

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Event\WorkflowEvents` | enum | public | — |

## Layer 4: API

### api

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Audit\AuditQueryReadModelInterface` | interface | public | — |
| `ContentSearch\ContentSearchRateLimiterInterface` | interface | internal | — |
| `ContentSearch\ContentSearchReadModelInterface` | interface | internal | — |
| `McpAdmin\ServerConfigReadModelInterface` | interface | public | — |
| `McpAdmin\ToolRegistryReadModelInterface` | interface | public | — |
| `Media\MediaVersionReadModelInterface` | interface | public | — |
| `MercureMonitor\ChannelInspectorInterface` | interface | public | — |
| `MercureMonitor\EventStreamReadModelInterface` | interface | public | — |
| `MercureMonitor\SubscriberObserverInterface` | interface | public | — |
| `MutableTranslatableInterface` | interface | public | Extends `TranslatableInterface` with `addTranslation()` for explicit translation creation |

### bimaaji

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Graph\GraphSectionProviderInterface` | interface | public | — |
| `Install\ClientTransformerInterface` | interface | public | — |
| `Install\Client\AbstractSingleFileClientTransformer` | abstract class | public | — |
| `Install\SkillDeliveryMode` | enum | public | — |
| `Install\SkillResourceFailure` | enum | public | — |

### routing

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Controller` | abstract class | public | — |
| `Language\LanguageNegotiatorInterface` | interface | public | Detects the active language from a request via path prefix, domain, or header |

## Layer 5: AI

### ai-agent

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Account\InitiatorAccountLoaderInterface` | interface | public | — |
| `AgentDefinition` | final readonly class | public | — |
| `AgentDefinitionRegistry` | final class | public | — |
| `Attribute\AsAgentDefinition` | final class | public | — |
| `Broadcast\AgentRunBroadcasterInterface` | interface | public | — |
| `Enum\EventType` | enum | public | — |
| `Enum\HitlMode` | enum | public | — |
| `Enum\RunStatus` | enum | public | — |
| `LocalOperator\LocalOperatorAccountContextGuard` | final class | public | — |
| `LocalOperator\LocalOperatorPrincipal` | final class | public | — |
| `LocalOperator\LocalOperatorRefusal` | final class | public | — |
| `LocalOperator\LocalOperatorToolProfile` | final class | public | — |
| `LocalOperator\LocalOperatorTransportAttestation` | final class | public | — |
| `Provider\ProviderException` | abstract class | public | — |
| `Provider\ProviderInterface` | interface | public | AI model provider: sends messages and returns a structured response |
| `Provider\StreamingProviderInterface` | interface | public | Provider variant that streams partial response chunks as they arrive |
| `Security\AgentRunAccountProjectionReaderInterface` | interface | public | — |
| `Security\AgentRunWorkerReaderInterface` | interface | public | — |
| `Tool\Wayfinding\AbstractTrailTool` | abstract class | internal | — |

- `ToolRegistryInterface` (interface): Provides the set of tools available to an AI agent

### ai-observability

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Recorder\AgentRunMetricsRecorderInterface` | interface | public | — |
| `Recorder\AgentTelescopeRecorderInterface` | interface | public | — |
| `Recorder\TraceRecorderInterface` | interface | public | — |
| `Value\BudgetDecision` | enum | public | — |

### ai-tools

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AbstractAgentTool` | abstract class | public | — |
| `AgentTool` | final readonly class | public | — |
| `AgentToolInterface` | interface | public | — |
| `AgentToolResult` | final readonly class | public | — |
| `Attribute\AsAgentTool` | final class | public | — |
| `Content\AssetStoreInterface` | interface | public | — |
| `Dispatch\ToolDispatcherInterface` | interface | public | — |
| `ProvidesAgentToolsInterface` | interface | public | — |
| `Resource\ContentResourceProviderInterface` | interface | public | Contributes bounded principal-explicit content resources without coupling MCP to content packages |
| `ToolRegistryInterface` | interface | public | — |

### ai-vector

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `DistanceMetric` | enum | public | — |
| `EmbeddingInterface` | interface | public | Extends `EmbeddingProviderInterface` with batch embedding generation |
| `EmbeddingProviderInterface` | interface | public | Generates a vector embedding for a single text string |
| `EmbeddingStorageInterface` | interface | public | Stores and similarity-searches raw float vectors by entity type and ID |
| `VectorStoreInterface` | interface | public | Stores and queries entity embeddings in a vector backend (pgvector, Qdrant, etc.) |

## Layer 6: Interfaces

### admin-surface

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Action\SurfaceActionHandlerInterface` | interface | public | Handles a custom admin surface action for a given entity type and payload |
| `Host\AbstractAdminSurfaceHost` | abstract class | public | Base class applications extend to integrate with the admin SPA (session, catalog, entity ops) |
| `Host\AdminPublicationFieldReaderInterface` | interface | public | Closed application-wiring boundary for authorized node publication metadata in admin lists |
| `Host\AdminRevisionPreviewAuthorityInterface` | interface | public | — |
| `Host\AdminSurfaceHostFactoryInterface` | interface | public | — |
| `Host\BatchAdminPublicationFieldReaderInterface` | interface | public | Cardinality-preserving batch extension that projects an authorized list scope transactionally |
| `List\ListFormatter` | enum | public | — |
| `PageBuilder\PageBuilderSurfaceHostInterface` | interface | public | — |
| `Query\SurfaceFilterOperator` | enum | public | — |

### cli

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `AdminBuild\AdminBuildPlatform` | enum | internal | — |
| `AdminBuild\AdminBuildProcessResult` | final readonly class | internal | — |
| `AdminBuild\AdminBuildProcessRunnerInterface` | interface | internal | — |
| `Command\Config\ConfigCommand` | abstract class | public | — |
| `Command\Config\ConfigDiffCommand` | final class | public | — |
| `Command\Config\ConfigExportCommand` | final class | public | — |
| `Command\Config\ConfigImportCommand` | final class | public | — |
| `Command\Config\ConfigManifestSignCommand` | final class | public | — |
| `Command\Config\ConfigResetCommand` | final class | public | — |
| `Command\Config\ConfigStatusCommand` | final class | public | — |
| `Command\Config\ConfigValidateCommand` | final class | public | — |
| `Command\HandlerArgumentMode` | enum | public | — |
| `Command\HandlerOptionMode` | enum | public | — |
| `Command\Make\AbstractMakeHandler` | abstract class | internal | — |
| `Command\Migration\BackfillHelper` | final class | public | — |
| `Command\Migration\BackfillRowCountMismatchException` | final class | public | — |
| `Command\Migration\StorageMigrationEmitter` | final class | public | — |
| `Command\Migration\StorageMigrationTemplate` | final class | public | — |
| `Command\Migration\UnmappedFieldTypeException` | final class | public | — |
| `Handler\MakeStorageMigrationHandler` | final class | public | — |
| `Handler\MutationAuthorityBackfillHandler` | final readonly class | public | — |
| `Io\StdinSource` | interface | public | — |
| `Provider\MakeStorageMigrationServiceProvider` | final class | public | — |
| `Security\CliFieldReadCapabilityDeclaration` | final readonly class | public | Exact command scope and closed CLI-valid privileged-read reason |
| `Security\CliFieldReadCapabilityIssuer` | final readonly class | public | Issues null-actor NoActingContext capabilities from command metadata |
| `Site\SiteHostPlatform` | enum | internal | — |
| `Site\SitePathContainment` | final class | internal | — |
| `Site\SitePreset` | enum | internal | — |

### deployer

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `RuntimeState\RuntimeTablePolicy` | enum | public | Versioned ownership policy for framework SQLite artifact and serving-host runtime tables |

### genealogy

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Access\GenealogyInternalFieldReaderInterface` | interface | public | — |

### mcp

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Admin\RecentInvocationsQueryInterface` | interface | public | — |
| `Auth\McpAuthInterface` | interface | public | Authenticates MCP requests and resolves the immutable acting authorization principal |
| `Auth\OAuthAccessTokenValidatorInterface` | interface | public | Validates OAuth access tokens for one exact MCP resource and returns an active scoped principal |
| `Auth\ScopedMcpAuthInterface` | interface | public | — |
| `Auth\WriteTierAuthInterface` | interface | public | — |

- `ToolExecutorInterface` (interface): Executes an MCP tool call by name with arguments and returns structured content
- `ToolRegistryInterface` (interface): Provides the full list of MCP tool definitions for the protocol manifest

### ssr

| Element | Type | Disposition | Purpose |
|---------|------|-------------|---------|
| `Http\AppController\AppControllerArgumentResolver` | interface | public | — |
| `Http\AppController\AppParameterKind` | enum | public | — |
| `PageComposition\EntityPageComposerInterface` | interface | public | — |
| `ThemeInterface` | interface | public | Provides a theme's identifier and its Twig template directory paths |
