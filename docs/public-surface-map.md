# Waaseyaa Public Surface Map

GENERATED FILE — DO NOT EDIT BY HAND.

This document lists every intentionally public API element in the Waaseyaa framework.
Elements not listed here are `@internal` and may change without notice.

Single editable authority: `packages/<pkg>/public-surface.php` (contract:
`docs/specs/public-surface-declarations.md`). Composed by `bin/generate-surface-map`.
Machine-readable derived view: `docs/public-surface-map.php`.

---

## Layer 0: Foundation

### analytics

| Element | Type | Purpose |
|---------|------|---------|
| `Transport` | interface | — |

### cache

| Element | Type | Purpose |
|---------|------|---------|
| `CacheBackendInterface` | interface | Reads and writes cache items with optional tag and expiry support |
| `CacheFactoryInterface` | interface | Creates or retrieves cache backend instances by bin name |
| `CacheTagsInvalidatorInterface` | interface | Invalidates all cache items associated with a set of tags |
| `ContextNames` | final class | Canonical context-name constants (`USER_ROLES`, `USER_ID`, `LANGUAGE_CONTENT`, `LANGUAGE_INTERFACE`, `URL_QUERY_PREFIX`) |
| `ContextRegistry` | final class | Whitelist of canonical context names for cache-key segmentation (charter §5.9) |
| `ContextResolver` | final class | Resolves a context name against a `RequestContext` into a deterministic short string |
| `EntityPayloadBoundaryConfig` | final readonly class | Explicit dormant/enforced cache entity-payload write mode |
| `Exception\EntityProjectionWriteForbidden` | final class | Activated cache rejection before an entity graph can be retained |
| `Exception\InvalidCacheTagException` | final class | Thrown by `setWithTags()` on malformed tag strings (no silent normalisation) |
| `ProjectionDeprecationDiagnostic` | final class | Deduplicated dormant entity-payload diagnostic wired into first-party cache writes |
| `ProtectedCacheDimensions` | final readonly class | Complete protected-cache authority, bundle, language, revision, and generation key dimensions |
| `TagAwareCacheInterface` | interface | Cache backend that supports tag-based invalidation |
| `TaggedCacheInterface` | interface | Listing-pipeline tag-aware ops (`setWithTags`, `invalidateByTag`, `getTagsFor`) — charter §5.9 |

### database-legacy

| Element | Type | Purpose |
|---------|------|---------|
| `ConsistentReadDatabaseInterface` | interface | — |
| `DatabaseIdentityProviderInterface` | interface | — |
| `DatabaseInterface` | interface | Doctrine DBAL abstraction: query builder entry point for select, insert, update, delete |
| `DeleteInterface` | interface | Fluent DELETE query builder with conditions |
| `Exception\TransactionCompletionException` | final class | Reports completion-effect failures after the database has committed |
| `ForeignKeySchemaInterface` | interface | — |
| `InsertInterface` | interface | Fluent INSERT query builder |
| `SchemaInterface` | interface | DDL operations: create/alter/drop tables and columns |
| `SelectInterface` | interface | Fluent SELECT query builder with conditions, joins, ordering, and pagination |
| `TransactionCompletionInterface` | interface | Defers registered callbacks through nested managed transactions to the outermost commit |
| `TransactionInterface` | interface | Wraps database operations in a named transaction with commit/rollback |
| `UpdateInterface` | interface | Fluent UPDATE query builder with conditions |

### error-handler

| Element | Type | Purpose |
|---------|------|---------|
| `SolutionInterface` | interface | — |
| `SolutionProviderInterface` | interface | — |

### foundation

| Element | Type | Purpose |
|---------|------|---------|
| `Asset\AssetManagerInterface` | interface | Resolves source asset paths to versioned/hashed production URLs via build manifests |
| `Audit\Approval\ApprovalStatus` | enum | — |
| `Audit\Approval\OperationApprovalStoreInterface` | interface | — |
| `Audit\AuditStage` | enum | — |
| `Audit\StrictAuditLedgerInterface` | interface | — |
| `Community\CommunityContextInterface` | interface | — |
| `Diagnostic\DiagnosticCode` | enum | — |
| `Diagnostic\HealthCheckerInterface` | interface | Runs boot, runtime, and ingestion health checks across subsystems |
| `Event\DomainEvent` | abstract class | Base class for all domain events carrying aggregate identity and actor context |
| `Event\EventDispatcherInterface` | interface | — |
| `Exception\WaaseyaaException` | abstract class | Base exception for all framework errors, with HTTP status code and problem type |
| `Http\BindingAwareHttpServiceResolverInterface` | interface | — |
| `Http\HttpServiceResolverInterface` | interface | — |
| `Http\Inbound\InboundHttpRequestInterface` | interface | — |
| `Http\Inertia\InertiaFullPageRendererInterface` | interface | — |
| `Http\Inertia\InertiaPageResultInterface` | interface | — |
| `Http\JsonApiResponseTrait` | trait | Builds JSON:API responses with correct content type and encoding options |
| `Http\LanguagePathStripperInterface` | interface | — |
| `Http\RequestContext` | final readonly class | Exposes the active request's roles, user id, languages, and query params to `ContextResolver` (charter §5.9) |
| `Http\Router\DomainRouterInterface` | interface | — |
| `Ingestion\IngestionErrorCode` | enum | — |
| `Ingestion\TraceIdGeneratorInterface` | interface | — |
| `Kernel\AbstractKernel` | abstract class | — |
| `Kernel\Bootstrap\PolicyDependencyResolverInterface` | interface | — |
| `Log\Formatter\FormatterInterface` | interface | Formats a log record into its final string or array representation |
| `Log\Handler\HandlerInterface` | interface | Log handler that receives and writes formatted log records |
| `Log\LogLevel` | enum | — |
| `Log\LoggerInterface` | interface | Structured logger with PSR-3-style severity levels (framework-internal, not psr/log) |
| `Log\LoggerTrait` | trait | Default implementations of all log-level methods delegating to `log()` |
| `Log\Processor\ProcessorInterface` | interface | Enriches log records with additional context before handling |
| `Middleware\HttpHandlerInterface` | interface | Terminal HTTP request handler (innermost layer of the middleware onion) |
| `Middleware\HttpMiddlewareInterface` | interface | Wraps an HTTP handler to add cross-cutting behavior |
| `Middleware\JobHandlerInterface` | interface | Terminal queue job handler |
| `Middleware\JobMiddlewareInterface` | interface | Wraps a job handler to add cross-cutting behavior |
| `Migration\Dag\MigrationKind` | enum | — |
| `Migration\EntityTableMaterializerInterface` | interface | — |
| `Migration\Executor\ApplyMode` | enum | — |
| `Migration\Executor\OpPrecondition` | enum | — |
| `Migration\Migration` | abstract class | Base class for database migrations with optional rollback and ordering |
| `Migration\SchemaAuthorityManifest` | final readonly class | — |
| `Migration\VerifyResult` | enum | — |
| `RateLimit\RateLimiterInterface` | interface | Checks and records attempt counts for rate limiting |
| `Runtime\RuntimeEpochInterface` | interface | — |
| `Schema\Compiler\CompiledStep` | interface | — |
| `Schema\Compiler\Sqlite\SqliteDiagnosticCode` | enum | — |
| `Schema\Compiler\Validation\ValidationDiagnosticCode` | enum | — |
| `Schema\Compiler\Validation\ValidationException` | abstract class | — |
| `Schema\Diff\OpKind` | enum | — |
| `Schema\Diff\SchemaDiffOp` | interface | — |
| `Schema\Migration\MigrationInterfaceV2` | interface | — |
| `Schema\SchemaRegistryInterface` | interface | Stores and retrieves JSON Schema entries by entity type ID |
| `Security\ApplicationMasterPurposeStrategy` | enum | Closed transition strategies for versioned application-master purposes |
| `Security\ApplicationMasterSymmetricOperation` | trait | — |
| `Security\Rekey\ApplicationMasterRekeyAdapterInterface` | interface | Same-database joint-owner inventory, transition, verification, and rollback seam |
| `Security\Rekey\ApplicationMasterRekeyGate` | enum | Closed fleet, cache, rollback, and retained-backup revocation gates |
| `Security\Rekey\ApplicationMasterRekeyState` | enum | Closed persisted prepare-through-revocation and forward-rollback states |
| `Security\SecretClass` | enum | Closed secret classifications used by typed references, policies, and guarded consumers |
| `Security\SecretConsumerInterface` | interface | Purpose-specific guarded endpoint for resolved secret bytes |
| `Security\SecretConsumptionCode` | enum | — |
| `Security\SecretProviderInterface` | interface | External secret-provider resolution seam |
| `Security\SecretResolutionCode` | enum | — |
| `ServiceProvider\Capability\AcceptsAgentToolProvidersInterface` | interface | — |
| `ServiceProvider\Capability\AcceptsAiCatalogEntryProvidersInterface` | interface | Receives the deterministic installed-provider set used to assemble experimental AI Catalog discovery |
| `ServiceProvider\Capability\AcceptsApiCatalogEntryProvidersInterface` | interface | Receives the deterministic installed-provider set used to assemble RFC 9727 discovery |
| `ServiceProvider\Capability\AcceptsContentModelProvidersInterface` | interface | — |
| `ServiceProvider\Capability\AcceptsMigrationProvidersInterface` | interface | — |
| `ServiceProvider\Capability\ConfiguresHttpKernelInterface` | interface | — |
| `ServiceProvider\Capability\FinalizesProviderBootInterface` | interface | — |
| `ServiceProvider\Capability\HasGraphqlMutationOverridesInterface` | interface | — |
| `ServiceProvider\Capability\HasHttpDomainRoutersInterface` | interface | — |
| `ServiceProvider\Capability\HasMiddlewareInterface` | interface | — |
| `ServiceProvider\Capability\HasRenderCacheListenersInterface` | interface | — |
| `ServiceProvider\Capability\ProvidesAiCatalogEntriesInterface` | interface | Contributes same-origin, intentionally public AI artifact references without deployment-specific language |
| `ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface` | interface | Contributes same-origin, intentionally public API endpoints and bounded description links |
| `ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface` | interface | — |
| `ServiceProvider\Capability\ProvidesCapabilitiesInterface` | interface | — |
| `ServiceProvider\Capability\ProvidesConsoleCommandsInterface` | interface | — |
| `ServiceProvider\Capability\ProvidesRolesInterface` | interface | — |
| `ServiceProvider\Capability\RequiresCapabilitiesInterface` | interface | — |
| `ServiceProvider\Capability\RequiresOptionalPackagesInterface` | interface | — |
| `ServiceProvider\KernelServicesInterface` | interface | — |
| `ServiceProvider\ServiceProvider` | abstract class | Base class for service providers with DI binding and resolution helpers |
| `ServiceProvider\ServiceProviderInterface` | interface | Contract for packages to register and boot their services |
| `Sovereignty\SovereigntyConfigInterface` | interface | — |
| `Sovereignty\SovereigntyProfile` | enum | — |
| `Tenant\TenantResolverInterface` | interface | — |
| `Upgrade\UpgradePreflightContract` | final class | — |
| `Upgrade\UpgradePreflightDecision` | enum | — |
| `Upgrade\UpgradePreflightEvaluator` | final class | — |
| `Upgrade\UpgradePreflightResult` | final readonly class | — |

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

| Element | Type | Purpose |
|---------|------|---------|
| `HttpClientInterface` | interface | — |
| `SseLineStreamInterface` | interface | — |

### i18n

| Element | Type | Purpose |
|---------|------|---------|
| `LanguageManagerInterface` | interface | Manages the set of available languages and their default |
| `TranslatorInterface` | interface | Translates keys with optional parameter substitution and locale override |

### ingestion

| Element | Type | Purpose |
|---------|------|---------|
| `EnvelopeValidator` | abstract class | — |
| `PayloadValidatorInterface` | interface | — |

### mail

| Element | Type | Purpose |
|---------|------|---------|
| `MailerInterface` | interface | — |
| `Transport\TransportInterface` | interface | — |

### oauth-provider

| Element | Type | Purpose |
|---------|------|---------|
| `OAuthProviderInterface` | interface | OAuth 2.0 provider abstraction: authorization URL, code exchange, token refresh, user profile |
| `SessionInterface` | interface | Manages OAuth session state (CSRF state token and post-auth redirect) |

### plugin

| Element | Type | Purpose |
|---------|------|---------|
| `Discovery\PluginDiscoveryInterface` | interface | — |
| `Extension\KnowledgeToolingExtensionInterface` | interface | — |
| `Factory\PluginFactoryInterface` | interface | — |
| `PluginBase` | abstract class | Base implementation of `PluginInspectionInterface` for all plugin types |
| `PluginInspectionInterface` | interface | Provides read access to a plugin's ID and definition |
| `PluginManagerInterface` | interface | Discovers, retrieves, and instantiates plugins by ID |

### queue

| Element | Type | Purpose |
|---------|------|---------|
| `Envelope\NoAuthorityQueueRuntime` | final readonly class | Dormant runtime that installs no account or capability authority |
| `Envelope\QueueAuthorityRuntimeInterface` | interface | Confines envelope authority resolution and installation to one handler invocation |
| `Envelope\QueueAuthorityScopeInterface` | interface | Closeable handler-only authority installation used by queue runtimes |
| `Envelope\QueueEnvelopeFactoryInterface` | interface | Dispatch-bound factory for an explicit actor/system authority envelope |
| `Envelope\QueueEnvelopeV1` | final readonly class | Dormant versioned authority envelope carrying exactly one actor or system authority plus tenant/community and correlation dimensions |
| `Envelope\QueueSystemReason` | enum | Closed reason vocabulary for system-owned queue authority |
| `Envelope\ScopedQueueAuthorityRuntime` | final readonly class | Resolves and closes one handler-only authority scope in `finally` |
| `Envelope\SystemQueueEnvelopeFactory` | final readonly class | Explicit reviewed system-authority envelope factory; never the generic dispatch default |
| `Exception\InvalidPersistentPayload` | final class | Exact replay payload failed authentication |
| `FailedJobRepositoryInterface` | interface | — |
| `Handler\HandlerInterface` | interface | — |
| `Job` | abstract class | — |
| `OccurrenceQueueInterface` | interface | — |
| `Occurrence\OccurrenceAwareMessageInterface` | interface | — |
| `Occurrence\OccurrenceContextInterface` | interface | — |
| `Occurrence\OccurrenceRunResult` | enum | — |
| `Occurrence\OccurrenceRuntimeInterface` | interface | — |
| `PersistentPayloadReplayInterface` | interface | Replays the exact authenticated persistent payload without replacing its envelope metadata |
| `PersistentQueueBoundaryConfig` | final readonly class | Explicit dormant/enforced persistent dispatch and legacy-envelope mode |
| `QueueInterface` | interface | Dispatches messages to the queue for asynchronous processing |
| `QueuePayloadDeprecationDiagnostic` | final class | Bounded nested entity-payload diagnostic for persistent dispatch |
| `Transport\TransportInterface` | interface | — |

### scheduler

| Element | Type | Purpose |
|---------|------|---------|
| `Execution\LeaseAwareCommandInterface` | interface | — |
| `Execution\LeaseExecutionContext` | final class | — |
| `Fence\FenceGuardInterface` | interface | — |
| `Lease\LeaseAuthorityInterface` | interface | — |
| `Occurrence\OccurrenceDispatchResult` | enum | — |
| `Occurrence\OccurrenceRepositoryInterface` | interface | — |
| `ScheduleEntriesInterface` | interface | — |
| `ScheduleInterface` | interface | — |

### site-contract

| Element | Type | Purpose |
|---------|------|---------|
| `Blueprint\BlueprintCheckKind` | enum | — |
| `Blueprint\BlueprintConditionKind` | enum | — |
| `Blueprint\BlueprintDecision` | enum | — |
| `Blueprint\BlueprintFieldType` | enum | — |
| `Blueprint\BlueprintLifecycle` | enum | — |
| `Blueprint\BlueprintOnDelete` | enum | — |
| `Blueprint\BlueprintOperation` | enum | — |
| `Blueprint\BlueprintStorage` | enum | — |
| `Capability\CapabilityState` | enum | Closed active, planned, and not-needed vocabulary serialized by the provider-neutral site manifest |
| `Doctor\FindingSeverity` | enum | — |
| `Generation\ArtifactApplyOutcome` | enum | Closed planned/applied/no_changes/cancelled/refused apply outcome |
| `Generation\ArtifactSetEvolution` | enum | Closed frozen/additive declaration of whether a compiler may render a superset of its unit's recorded path set |
| `Generation\ArtifactStatus` | enum | Closed created/changed/unchanged/refused per-path evaluation outcome |
| `Generation\ChangeOutcome` | enum | Closed applied/no_op/refused/failed/recovered governed-change receipt outcome |
| `Generation\Exception\GenerationErrorCode` | enum | Closed GEN001-GEN015 refusal ids for the generation execution and plan boundary, reserved by ADR-025 D-5 |
| `Generation\GenerationUnitDisposition` | enum | Closed managed/seeded vocabulary for how a generation unit's artifacts are treated after publication |
| `Generation\ObservedTargetMode` | enum | Closed 0644/0755/other/unknown record of the permission bits evaluation observed |
| `Generation\ObservedTargetState` | enum | Closed absent/file/other record of what evaluation observed at one target path |
| `Generation\SiteRecipeRendererInterface` | interface | — |
| `ManifestShapeReader` | trait | — |
| `Version\ManifestVersionDisposition` | enum | Closed current, migration-required, and unsupported-future schema-version decision |

### state

| Element | Type | Purpose |
|---------|------|---------|
| `EntityPayloadBoundaryConfig` | final readonly class | Explicit dormant/enforced state entity-payload write mode |
| `Exception\EntityProjectionWriteForbidden` | final class | Activated state rejection before an entity graph can be retained |
| `ProjectionDeprecationDiagnostic` | final class | Deduplicated dormant entity-payload diagnostic wired into memory/SQL state writes |
| `PublicStateProjection` | final readonly class | Identifier plus explicitly Public scalar/array values; grants no protected/internal authority |
| `StateInterface` | interface | — |

### typed-data

| Element | Type | Purpose |
|---------|------|---------|
| `Coercion\CoercionException` | final class | Thrown when entity-parity primitive/JSON-array coercion fails (#1185) |
| `Coercion\EntityCastCoercion` | final class | Storage ↔ domain coercion for `int`/`float`/`bool`/`string`/`array` casts (#1185) |
| `DataDefinitionInterface` | interface | Describes a typed data property: type, label, required, read-only, constraints (extended by `FieldDefinitionInterface`) |

## Layer 1: Core Data

### access

| Element | Type | Purpose |
|---------|------|---------|
| `AccessPolicyInterface` | interface | Checks entity-level access for view, update, delete, and (M-006 / ADR 017) `'translate'` operations |
| `AccessStatus` | enum | — |
| `AccountInterface` | interface | Represents a user account for access checking: ID, roles, and permission checks |
| `AccountPrincipalFactory` | final readonly class | Principal/entity-account snapshotter that refuses lossy conversion of arbitrary plain accounts |
| `AccountPrincipalFactoryInterface` | interface | Closed bootstrap seam for passing through principals or snapshotting an entity-backed account through the audited reader |
| `AuthorizationPrincipalBootstrapReaderInterface` | interface | Closed bridge for strictly audited immutable principal construction from an entity-backed account |
| `AuthorizationPrincipalInterface` | interface | Immutable account-facing claims used by protected field-read policies without reading an acting User entity |
| `Capability\CapabilityActorSemantics` | enum | Explicit account, anonymous, system-service, or no-acting-context attribution for capability issuance and ledger reservations |
| `Capability\CapabilityExecutionBoundary` | final readonly class | Opaque, non-serializable proof whose registry-owned identity must be live and match at capability issuance and use |
| `Capability\CapabilityReason` | enum | Closed reason vocabulary for privileged field reads |
| `Capability\CapabilityRegistryInterface` | interface | Kernel registry for reviewed, exact value-read and query-read capability declarations and one-boundary handles |
| `Capability\QueryFieldOperation` | enum | Closed non-public query operation vocabulary: predicate, sort, aggregate, count, exists |
| `ClassifiedProtectedEntityReadPolicyInterface` | interface | — |
| `ContextAwareAccessPolicyInterface` | interface | Companion to `AccessPolicyInterface` accepting a `$context` array (carries `langcode` for the `'translate'` operation and read-time langcode for `view`/`update`) (M-006, WP09) |
| `Context\AccountContextInterface` | interface | — |
| `Context\AccountFieldReadScopeInterface` | interface | Fiber-local, nested account principal scope restored in `finally`; it carries no privileged authority |
| `Context\FastAccountFieldReadScopeInterface` | interface | Internal compiled-read fast path exposing the current immutable account context without widening public authority |
| `ContextualAccountPrincipalFactoryInterface` | interface | HTTP companion that binds resolved tenant/community dimensions to the immutable principal snapshot |
| `ContextualProtectedEntityReadPolicyInterface` | interface | — |
| `DelegatingAuthorizationPrincipal` | final readonly class | Explicit legacy migration principal with provider-owned claims metadata and verbatim delegated account authorization behavior |
| `EntityViewProtectedFieldReadPolicyInterface` | interface | — |
| `ErrorPageRendererInterface` | interface | — |
| `FieldAccessPolicyInterface` | interface | Checks field-level access on an entity; open-by-default (Forbidden restricts, Neutral allows) |
| `Gate\GateInterface` | interface | Resolves the policy for a subject and checks whether a user has a given ability |
| `Gate\ListingFastPathProbeInterface` | interface | — |
| `Gate\RevisionAccessRouter` | final class | — |
| `Middleware\FieldReadContextMiddleware` | final readonly class | Priority-15 HTTP seam that installs/restores the immutable principal after identity resolution and wraps deferred streams |
| `PermissionHandlerInterface` | interface | Manages the registry of available permissions and their metadata |
| `PolicySubjectViewInterface` | interface | Closed view limited to compiled `authorizationInput` subject fields |
| `Policy\RevisionPolicyComposition` | final readonly class | — |
| `ProjectedProtectedEntityReadPolicyInterface` | interface | — |
| `ProtectedEntityReadPolicyInterface` | interface | Fail-closed V2 entity-read policy over immutable principal, structural identity, and exact compiled subject inputs |
| `ProtectedFieldReadPolicyInterface` | interface | Dedicated fail-closed Protected read policy; only explicit Allowed will release a value after activation |
| `ProtectedReadPolicyProviderInterface` | interface | Additive companion through which a discovered legacy policy exposes its entity and field V2 read policies |
| `Query\QueryFieldReadRequest` | final readonly class | Metadata-only query compiler input retaining exact fields/operations and an irreversible normalized-shape fingerprint |
| `User\UserAuthorizationSnapshot` | final readonly class | — |
| `User\UserCredentialSnapshot` | final readonly class | — |
| `User\UserIdentityLookupInterface` | interface | Closed audited active-login, mail-only recovery, and mail-existence query boundary |
| `User\UserInternalFieldReaderInterface` | interface | Narrow reason-specific User credential, session, mail, verification, 2FA, and maintenance read boundary |
| `User\UserMailSnapshot` | final readonly class | — |
| `User\UserSelfProfileReaderInterface` | interface | — |
| `User\UserSessionSnapshot` | final readonly class | — |
| `User\UserTwoFactorSnapshot` | final readonly class | — |
| `User\UserVerificationSnapshot` | final readonly class | — |

- `User*Snapshot` (final readonly classes): Typed exact User internal inputs returned without exposing arbitrary field-name authority

### audit

| Element | Type | Purpose |
|---------|------|---------|
| `AuditedFieldRead` | final readonly class | Exact-declaration privileged reader that reserves strict-ledger metadata before accessor evaluation and finalizes its outcome |
| `AuditedQueryFieldRead` | final readonly class | Dormant exact-capability compiler boundary that reserves non-public query metadata before execution |
| `AuditedQueryReservation` | final class | One-shot explicit success/failure finalizer for a reserved query operation |
| `Bootstrap\AuditedUserIdentityLookup` | final readonly class | Reserves and finalizes exact non-public login/mail repository queries |
| `Bootstrap\AuditedUserInternalFieldReader` | final readonly class | Issues, consumes, and revokes exact framework User value-read capabilities |
| `Bootstrap\CredentialBootstrapReader` | final readonly class | Reason-constrained strictly audited credential verification reader |
| `Bootstrap\IdentityBootstrapReader` | final readonly class | Per-snapshot capability issuer that builds immutable principals and revokes bootstrap authority in `finally` |
| `Bootstrap\SessionBootstrapReader` | final readonly class | Reason-constrained strictly audited session and authorization-claims reader |
| `Contract\AuditQueryInterface` | interface | — |
| `Contract\AuditWriteFailureObserver` | interface | — |
| `Contract\AuditWriterInterface` | interface | — |
| `Contract\BatchStrictPrivilegedReadLedgerInterface` | interface | Transactional batch extension that keeps descriptors entity-scoped while making related reservations and outcomes all-or-nothing |
| `Contract\PrivilegedReadKind` | enum | Distinguishes explicit value-read reservations from query-field reservations |
| `Contract\PrivilegedReadOutcome` | enum | Strict-ledger final outcomes: succeeded or failed; interrupted reservations remain unfinished and visible |
| `Contract\StrictPrivilegedReadLedgerInterface` | interface | Synchronously reserves non-value metadata before a privileged read and finalizes its outcome afterward |
| `Enum\AuditEventKind` | enum | — |
| `Integrity\CheckpointSink` | interface | — |
| `ReadModel\AuditReadModelDefinitionRegistry` | final class | Exact read classifications for every column of the deliberately unregistered flat audit tables |
| `Writer\DatabaseStrictPrivilegedReadLedger` | final readonly class | Durable immutable-event ledger with atomic single-finalization and caller-transaction composition |

### auth

| Element | Type | Purpose |
|---------|------|---------|
| `AtomicRateLimiterInterface` | interface | — |
| `Config\MailMissingPolicy` | enum | — |
| `Extension\AuthMailContentPolicyInterface` | interface | — |
| `Extension\AuthRedirectPolicyInterface` | interface | — |
| `Extension\InitialRolePolicyInterface` | interface | — |
| `Extension\ProvidesAuthExtensionsInterface` | interface | — |
| `Extension\RegistrationPolicyInterface` | interface | — |
| `Extension\RegistrationProfileHandlerInterface` | interface | — |
| `Password\LegacyPasswordVerifierInterface` | interface | — |
| `RateLimiterInterface` | interface | — |
| `Token\AuthTokenRepositoryInterface` | interface | — |
| `Token\Bearer\BearerTokenStoreInterface` | interface | — |

### config

| Element | Type | Purpose |
|---------|------|---------|
| `Activation\ConfigurationActivationAuthorizerInterface` | interface | — |
| `Activation\ConfigurationActivatorInterface` | interface | — |
| `Activation\ConfigurationCandidateMaintenanceInterface` | interface | — |
| `Activation\ConfigurationCandidateSweepAuthorizerInterface` | interface | — |
| `Activation\ConfigurationGenesisActivatorInterface` | interface | — |
| `Activation\ConfigurationRollbackValidatorInterface` | interface | — |
| `Audit\ConfigAuditChannel` | final class | `CHANNEL` constant = `'config.audit'` (charter §4.4 amendment) (M-003, WP07) |
| `Audit\ConfigAuditEvent` | final readonly class | Entity-type, id, operation, actor, before-after diff summary (M-003, WP07) |
| `Authority\ActiveConfigurationBridgeInterface` | interface | — |
| `Authority\ConfigurationGenerationResolverInterface` | interface | — |
| `Backend\BackendRestrictionEnforcer` | final class | Boot-time enforcement: config entities must declare `sql-blob` or `sql-column`; `ALLOWED_BACKEND_IDS` constant (M-003, WP08) |
| `ConfigFactoryInterface` | interface | Creates and caches `ConfigInterface` instances by name |
| `ConfigInterface` | interface | Read/write access to a named configuration object with key-path addressing |
| `ConfigManagerInterface` | interface | Manages config storage backends and export/import lifecycle |
| `Dependency\ConfigDependencyInterface` | interface | Config-entity contract: `configDependencies(): string[]` returns `<entity_type>.<entity_id>` ids consumed by the DAG (M-003, WP01) |
| `Dependency\DependencyGraph` | final readonly class | — |
| `Dependency\DependencyResolver` | final class | — |
| `Dependency\Exception\ConfigDependencyCycleException` | final class | Raised when the sync-store DAG contains a cycle; carries the full cycle path (M-003) |
| `Dependency\Exception\ConfigDependencyMissingException` | final class | Raised when a `_meta.dependencies` entry references a config id absent from both stores (M-003) |
| `Drift\ConfigDriftSnapshotReaderInterface` | interface | — |
| `Event\ConfigEvents` | enum | — |
| `Exception\ConfigCommandCollisionException` | final class | Raised at boot when an app/extension command claims a reserved `config:*` sub-verb (M-003, WP09) |
| `Exception\ConfigImportFailedException` | final class | Raised per-entity during `config:import`; carries entity id + cause (M-003, WP04) |
| `Exception\ConfigSerializationException` | final class | Raised on `_meta.entity_type` mismatch or other YAML format errors (M-003, WP02) |
| `Exception\ImmutableConfigException` | final class | — |
| `Exception\InvalidConfigBackendException` | final class | Raised when a config entity declares `vector` / `remote` / other disallowed backend (M-003, WP08) |
| `Manifest\ConfigManifestBundleSigner` | final readonly class | — |
| `Manifest\ConfigManifestEd25519Signer` | final class | — |
| `Manifest\ConfigManifestEd25519Verifier` | final readonly class | — |
| `Manifest\ConfigManifestEnvelopeFile` | final class | — |
| `Manifest\ConfigManifestSignatureVerifierInterface` | interface | — |
| `Manifest\ConfigManifestSignerInterface` | interface | — |
| `Manifest\ConfigManifestSigningResult` | final readonly class | — |
| `Manifest\ConfigManifestTrustPolicy` | final readonly class | — |
| `Manifest\ConfigReplayStateReaderInterface` | interface | — |
| `Schema\Ai\McpAuthMode` | enum | — |
| `Schema\Ai\McpAvailability` | enum | — |
| `Schema\ConfigSemanticValidatorInterface` | interface | — |
| `StorageInterface` | interface | Reads and writes raw configuration data arrays by name |
| `Sync\ConfigDiffer` | final class | Unified-diff renderer with UUID-tracked rename detection; backs `config:diff` (M-003, WP05) |
| `Sync\ConfigExportFileResult` | final readonly class | — |
| `Sync\ConfigExportResult` | final readonly class | — |
| `Sync\ConfigExporter` | final class | Active → sync; backs `config:export`; honours `--diff` and `--dry-run` (M-003, WP03) |
| `Sync\ConfigImportApplyHookInterface` | interface | Cross-cutting hook fired per applied entity during `config:import` (extension point) (M-003, WP04) |
| `Sync\ConfigImportEntryResult` | final readonly class | — |
| `Sync\ConfigImportPreflightInterface` | interface | — |
| `Sync\ConfigImportResult` | final readonly class | — |
| `Sync\ConfigImporter` | final class | Sync → active in topological order; per-entity transaction; orphan-warn default (M-003, WP04) |
| `Sync\ConfigManifestEntry` | final readonly class | Per-entity manifest row consumed by exporter/importer dashboards (M-003) |
| `Sync\ConfigResetter` | final class | Single-entity rollback from sync store; logs to `config.audit`; backs `config:reset` (M-003, WP07) |
| `Sync\ConfigStatusReporter` | final class | Computes in-sync / drift / sync-only / active-only counts; backs `config:status` (M-003, WP05) |
| `Sync\ConfigSyncDeserializer` | final class | YAML → `ConfigSyncFile`; validates `_meta.entity_type` matches filename prefix (M-003, WP02) |
| `Sync\ConfigSyncFile` | final readonly class | In-memory parsed sync file: `_meta` block + field values (M-003, WP02) |
| `Sync\ConfigSyncFileSourceInterface` | interface | Extension point: alternative sync-file sources (e.g. in-memory test sources) (M-003) |
| `Sync\ConfigSyncRepository` | final class | Filesystem read/write under `config.sync_path` (default `storage/config-sync/`) (M-003, WP02) |
| `Sync\ConfigSyncSerializer` | final class | Entity → YAML; sorts keys alphabetically; emits leading `_meta` block (M-003, WP02) |
| `Sync\ConfigSyncValidator` | final class | Runs `FieldDefinition::validators()` over each sync file; powers `config:validate` (M-003, WP06) |
| `Sync\ConfigValidateEntry` | final readonly class | — |
| `Sync\ConfigValidateResult` | final readonly class | — |
| `Sync\DiffResult` | final readonly class | — |
| `Sync\FieldValueMapper` | final class | — |
| `Sync\FieldViolation` | final readonly class | — |
| `Sync\SignedEnvelopeConfigImportPreflight` | final readonly class | — |
| `Sync\StatusEntry` | final readonly class | — |
| `Sync\StatusReport` | final readonly class | — |
| `TranslatableConfigFactoryInterface` | interface | Creates language-specific overrides of configuration objects |

- `Waaseyaa\CLI\Command\Config\ConfigCommand` (abstract class): Base for the seven `config:*` commands; exposes `RESERVED_VERBS`, `RESERVED_FULL_VERBS`, `RESERVED_FQCNS` constants for collision checks (M-003, WP09)
- `Waaseyaa\CLI\Command\Config\ConfigExportCommand` (command): `bin/waaseyaa config:export [--diff] [--dry-run]` (M-003, WP03)
- `Waaseyaa\CLI\Command\Config\ConfigImportCommand` (command): `bin/waaseyaa config:import [--dry-run] [--delete-orphans] [--halt-on-error] [--no-dependency-check]` (M-003, WP04)
- `Waaseyaa\CLI\Command\Config\ConfigDiffCommand` (command): `bin/waaseyaa config:diff [<entity-type>.<id>]` (M-003, WP05)
- `Waaseyaa\CLI\Command\Config\ConfigStatusCommand` (command): `bin/waaseyaa config:status [--format=plain|json]` (M-003, WP05)
- `Waaseyaa\CLI\Command\Config\ConfigValidateCommand` (command): `bin/waaseyaa config:validate` (M-003, WP06)
- `Waaseyaa\CLI\Command\Config\ConfigResetCommand` (command): `bin/waaseyaa config:reset <entity-type>.<id> [--yes]` (M-003, WP07)

### entity

| Element | Type | Purpose |
|---------|------|---------|
| `ApiExposableEntityTypeInterface` | interface | — |
| `Audit\EntityAuditKey` | enum | — |
| `Audit\LifecycleAuditKey` | enum | — |
| `Cast\FromArrayEntityValueInterface` | interface | — |
| `Community\HasCommunityInterface` | interface | — |
| `Community\HasCommunityTrait` | trait | — |
| `ConfigEntityBase` | abstract class | Base for configuration entities with string machine-name IDs and enable/disable lifecycle |
| `ConfigEntityInterface` | interface | Contract for configuration entities including status and enabled/disabled state |
| `ContentEntityBase` | abstract class | Fieldable entity base supporting dynamic field values and per-language translation |
| `ContentEntityInterface` | interface | Marker combining `EntityInterface` and `FieldableInterface` for content entities |
| `DateTime\EntityClockInterface` | interface | — |
| `DateTime\FixedEntityClock` | final class | — |
| `DateTime\TimestampFieldConvention` | final class | — |
| `DateTime\UtcEntityClock` | final class | — |
| `DefinesEntityType` | interface | — |
| `EntityBase` | abstract class | Default implementations of `EntityInterface`; subclasses hardcode entity type ID and keys |
| `EntityInterface` | interface | Core contract for all entity types: identity, label, type ID, and value access |
| `EntitySerializationBoundary` | final readonly class | Explicit dormant/enforced PHP serialization boundary for value-bearing entities |
| `EntitySerializationBoundaryConfig` | final readonly class | Exact activation toggle for entity PHP serialization rejection |
| `EntityTypeForeignKeyDefinitionInterface` | interface | — |
| `EntityTypeInterface` | interface | Describes an entity type: ID, label, class, keys, field definitions, and constraints |
| `EntityTypeManagerInterface` | interface | Registers entity types and provides storage instances for each |
| `EntityTypeStorageSchemaTransitionDefinitionInterface` | interface | — |
| `EntityTypeStorageUniqueKeyDefinitionInterface` | interface | — |
| `EntityValueReadGuardInterface` | interface | Internal sealed-entity adapter for protected-field read decisions and decision-cache invalidation |
| `EntityValues` | final class | — |
| `Event\EntityEvent` | class | Lifecycle event base; non-`final` so `TranslationEvent` may extend it (M-006, WP08 — public-surface change documented in mission reconciliation note) |
| `Event\EntityEventFactoryInterface` | interface | Creates `EntityEvent` instances with optional before/after snapshots |
| `Event\EntityEvents` | enum | — |
| `Event\TranslationEvent` | final class | Translation lifecycle event extending `EntityEvent`; carries entity, target langcode, and (for updates/deletes) prior values (M-006, WP08). Event-name constants: `PRE_TRANSLATION_INSERT`, `POST_TRANSLATION_INSERT`, `PRE_TRANSLATION_UPDATE`, `POST_TRANSLATION_UPDATE`, `PRE_TRANSLATION_DELETE`, `POST_TRANSLATION_DELETE` |
| `Exception\EntityTranslationException` | final class | Translation persistence error with named-constructor factories `langcodeRequired`, `cannotRemoveDefault`, `translationAlreadyExists`, `translationNotFound` (M-006, WP01) |
| `Exception\FieldAccessActivationBlocked` | final class | Checksum-backed hard failure when preflight blockers remain |
| `FieldReadLevel` | enum | Additive `public` / `protected` / `internal` definition metadata; dormant until the no-shim activation work package |
| `Field\BundleStorageUniqueKeyRegistryInterface` | interface | — |
| `Field\FieldDefinitionRegistryInterface` | interface | — |
| `Field\FieldReadLayoutGenerationSourceInterface` | interface | — |
| `FieldableInterface` | interface | Marks an entity as supporting named field access and definition retrieval |
| `Hydration\FallbackChainResolver` | final readonly class | — |
| `Hydration\HydratableFromStorageInterface` | interface | — |
| `Hydration\HydrationContext` | final readonly class | — |
| `Repository\EntityRepositoryInterface` | interface | High-level CRUD API handling hydration, event dispatch, and language fallback. Adds `findTranslations(EntityInterface): array<string, EntityInterface>` (M-006, WP10) |
| `RevisionMetadata` | final readonly class | — |
| `RevisionableEntityInterface` | interface | — |
| `RevisionableEntityTrait` | trait | Default implementation of `RevisionableInterface` using `$values` and `$entityKeys` |
| `RevisionableInterface` | interface | Adds revision ID tracking and new-revision control to an entity |
| `Snapshot\EntityValuesSnapshot` | final readonly class | — |
| `Storage\EntityQueryInterface` | interface | Fluent query builder for filtering and loading entities by field conditions |
| `Storage\EntityStorageInterface` | interface | Lower-level storage operations: load, save, delete, query |
| `Storage\RevisionableStorageInterface` | interface | Extends entity storage with load, delete, and list operations for specific revisions |
| `Testing\Translation\TranslatableEntityContractTest` | abstract class | — |
| `TranslatableEntityTrait` | trait | — |
| `TranslatableInterface` | interface | Per-language translation access for a translatable entity (M-006 / ADR 017): `getTranslation`, `hasTranslation`, `addTranslation`, `removeTranslation`, `translations`, `defaultLangcode`, `activeLangcode`, `fieldLangcode`. `language()` retained as deprecated alias for `activeLangcode()` |
| `Validation\RedactedInvalidValue` | enum | Internal value-free sentinel used when validation reports a restricted invalid field |
| `Validation\ValidationReadLedgerInterface` | interface | Internal validation adapter that reserves an authorized restricted-field read before value access |
| `Validation\ValidationReadReservationInterface` | interface | Internal one-shot reservation that records validation-read success or failure without retaining the value |

- `EntityType::__construct(...translatable: bool = false, ...)` (constructor arg): Marks an entity type as translatable; load-bearing — enforced by boot validation (M-006, WP02)

### entity-storage

| Element | Type | Purpose |
|---------|------|---------|
| `AggregateMutationRepositoryInterface` | interface | — |
| `BackendResolver` | final class | Resolves which backend handles a given `FieldDefinition` (M-001, WP02) |
| `Backend\BackendRegistrar` | final class | Registers field storage backends by id for an entity type (M-001, WP01) |
| `Backend\BackendRegistrarFactory` | final class | Creates a `BackendRegistrar` bound to a specific entity type (M-001, WP01) |
| `Backend\FieldStorageBackendGateway` | final class | Registrar-owned V2 facade that reserves strict audit and never exposes the implementation (#2064 WP3) |
| `Backend\FieldStorageBackendV2Interface` | interface | Active fingerprinted privileged backend SPI; accepts only registrar-issued roles and opaque inputs (#2064 WP4) |
| `Backend\FieldStorageGatewayAttempt` | final readonly class | Value-free strict-audit descriptor reserved before invocation (#2064 WP3) |
| `Backend\FieldStorageGatewayAuditReceipt` | final readonly class | Opaque receipt for strict attempt finalization (#2064 WP3) |
| `Backend\FieldStorageGatewayFailure` | final readonly class | Value-free failure record including whether backend invocation began (#2064 WP3) |
| `Backend\FieldStorageGatewayInput` | final class | Opaque non-serializable boundary-bound backend input handle (#2064 WP3) |
| `Backend\FieldStorageGatewayInvocation` | final readonly class | Backend-only invocation exposed after successful role/boundary validation (#2064 WP3) |
| `Backend\FieldStorageGatewayOperation` | enum | Closed read/write/delete/query-support invocation vocabulary (#2064 WP3) |
| `Backend\FieldStorageGatewayOutput` | final class | Opaque non-serializable boundary-bound backend result handle (#2064 WP3) |
| `Backend\FieldStorageGatewayRole` | final class | Opaque registrar-issued role required to unwrap inputs and construct results (#2064 WP3) |
| `Backend\HasFieldStorageBackendsV2Interface` | interface | Provider capability for reviewed V2 field-storage implementations (#2064 WP3) |
| `Backend\IsFrameworkBackendProviderV2Interface` | interface | Marker allowing framework-owned V2 providers to claim reserved backend ids (#2064 WP3) |
| `Backend\ReservedBackendIds` | final class | String constants for built-in backend ids: `SQL_BLOB`, `SQL_COLUMN`, `VECTOR` (M-001, WP01) |
| `Backend\SqlBlobBackend` | final class | Stores field values in a JSON `_data` blob column; `supportsQuery()` always false (M-001, WP03) |
| `Backend\SqlColumnBackend` | final class | Stores each field in a dedicated SQL column; `supportsQuery()` true for non-vector types (M-001, WP05) |
| `Backend\SqlColumnQueryTranslator` | final class | Translates field-level query predicates to SQL for `SqlColumnBackend` (M-001, WP05) |
| `Backend\SqlColumnSchemaBuilder` | final class | Builds SQL column schema for `SqlColumnBackend` (M-001, WP05) |
| `Backend\StrictFieldStorageGatewayAuditInterface` | interface | Synchronous reserve/success/failure audit required for V2 gateway activation (#2064 WP3) |
| `Backend\TypeMapping` | final class | Maps `FieldDefinition` type strings to DBAL column types (M-001, WP05) |
| `Connection\ConnectionResolverInterface` | interface | Resolves named database connections; multi-tenancy seam for entity storage |
| `ContextualEntityLoaderInterface` | interface | — |
| `CoordinatorLifecycleDispatcher` | final class | Dispatches lifecycle events from the coordinator (M-001, WP04) |
| `Driver\EntityStorageDriverInterface` | interface | Low-level persistence SPI: raw row I/O without hydration or event dispatch. Adds `findTranslations(EntityInterface): array<string, EntityInterface>` (M-006, WP10) |
| `Driver\EntityStorageDriverV2Interface` | interface | Additive boundary-bound opaque-row storage SPI: reads return `StorageRow`/`StorageRowSet` and writes accept `StorageSnapshot`; unrelated boundary tokens cannot unwrap them, and V1 remains behavior-compatible during dormant stages |
| `Driver\InMemoryStorageDriverV2` | final readonly class | Opaque V2 adapter over the first-party in-memory ordinary storage backend |
| `Driver\LangcodePeerStorageDriverV2Interface` | interface | Optional opaque-snapshot capability for tenancy-safe `(entity id, langcode)` peer writes without collapsing sibling languages |
| `Driver\RevisionableStorageDriver` | final class | Driver-level two-axis save/load orchestration: composes `RevisionTableBuilder` + `TranslationSchemaHandler` and honours `SaveContext::withTranslations()` for atomic multi-language revision writes (M-004, WP03 + WP04) |
| `Driver\RevisionableStorageDriverV2` | final readonly class | Opaque V2 adapter used by the repository for the first-party revision/langcode storage backend |
| `Driver\RevisionableStorageDriverV2Interface` | interface | Additive opaque revision SPI mirroring current revision and langcode operations while replacing raw read/write arrays with boundary-bound rows and snapshots |
| `Driver\SqlStorageDriverV2` | final readonly class | Opaque V2 adapter over the first-party SQL ordinary storage backend |
| `EntityStorageCoordinator` | final class | Fan-out engine dispatching read/write/delete to all registered backends (M-001, WP02) |
| `Event\AbortOperationException` | final class | Thrown from `BeforeSave`/`BeforeDelete` listener to abort the operation (M-001, WP04) |
| `Event\AfterDeleteEvent` | final class | Dispatched after all backends confirm delete (M-001, WP04) |
| `Event\AfterSaveEvent` | final class | Dispatched after all backends commit; not dispatched on partial failure (M-001, WP04) |
| `Event\BeforeDeleteEvent` | final class | Dispatched before any backend delete (M-001, WP04) |
| `Event\BeforeSaveEvent` | final class | Dispatched before any backend write; listeners may abort via `AbortOperationException` (M-001, WP04) |
| `Event\EntityLifecycleEventInterface` | interface | Marker for all four coordinator lifecycle events (M-001, WP04) |
| `Event\EntityMutationAuthorityBackfilledEvent` | final readonly class | — |
| `Exception\BundleAmbiguousFieldException` | final class | — |
| `Exception\BundleUniqueKeyConflictException` | final class | Stable repository conflict for a database-enforced bundle key (`BUNDLE_UNIQUE_KEY_CONFLICT`) (#2603) |
| `Exception\BundleUniqueKeyMigrationException` | final class | Stable schema-sync refusal when existing bundle rows duplicate a declared key (`bundle_unique_key_duplicates`) (#2603) |
| `Exception\PartialSaveException` | final class | Thrown on backend fan-out failure, including first-backend failure with an empty committed set; carries `$errorCode` (M-001, WP04) |
| `Exception\StorageMigrationException` | final class | Typed exception for storage-migration / two-axis schema failures during kernel boot, schema sync, or migration generator runs. Stable `errorCode` strings: `no_op_promotion`, `unsupported_two_axis_field` (M-004, WP04) |
| `Exception\UnknownBackendException` | final class | Thrown when a field references an unregistered backend id (M-001, WP02) |
| `Exception\UnknownFieldException` | final class | — |
| `Exception\UnsupportedListingException` | final class | Thrown when listing is unsupported by the active backend (M-001, WP06) |
| `Exception\UnsupportedQueryException` | final class | Thrown when a query operator is unsupported by the active backend (M-001, WP01) |
| `Hydration\EntityInstantiator` | final class | — |
| `LegacyMutationAuthorityBackfillRepositoryInterface` | interface | — |
| `Listing\TwoAxisFilterResolver` | final class | Resolves listing filters against two-axis storage: joins `<entity>__revision` to `<entity>__translation__revision` and applies langcode + revision-window selection (M-004, WP07) |
| `Query\DefinitionValidator` | final class | Validates `FieldDefinition` objects at registration time; throws `UnsupportedQueryException` (M-001, WP06) |
| `Query\EntityQuery` | interface | — |
| `RevisionPruningPolicy` | final class | Immutable value object describing how many revisions to keep (M-001, WP08) |
| `RevisionPruningReport` | final class | Result of a pruning run: counts of deleted and retained revisions (M-001, WP08) |
| `Revision\RevisionPruningPolicy` | final class | Two-axis pruning policy value object; keeps the M-001 `RevisionPruningPolicy` surface intact while extending semantics to per-langcode revision counts (M-004, WP05) |
| `SaveContext` | final class | Immutable value object passed to save operations; carries revision flags and translation langcode. `withLangcode(string $langcode): self` returns an immutable copy targeting a translation write (M-001, WP04 + M-006, WP07) |
| `Schema\EntityStorageSchemaTransitionInterface` | interface | — |
| `Schema\RevisionTableBuilder` | final class | Creates the `{entity_type}_revision` schema table (M-001, WP07) |
| `Schema\TranslationSchemaHandler` | final class | Emits the `<entity>__translation__revision` table for two-axis entities; pairs with `RevisionTableBuilder::buildTwoAxis()` (M-004, WP02) |
| `Tenancy\CommunityTranslationPeerRepairReport` | final readonly class | Machine-readable examined, eligible, repaired, skipped, and dry-run counts from translation-peer repair |
| `Tenancy\CommunityTranslationPeerRepairer` | final readonly class | Explicit fail-closed repair for legacy translation peers with empty community discriminators |

- `EntityRepository::findTranslations(EntityInterface): array<string, EntityInterface>` (method): Returns every translation of the given entity, keyed by langcode, default-langcode first; single SQL query (M-006, WP10)
- `SaveContext::withTranslations(array $langcodes): self` (method): Immutable copy carrying a `[langcode => values]` map for atomic multi-language revision writes; rejected if empty. Pairs with `withLangcode()` for single-language writes (M-004, WP03)
- `EntityTranslationException::historicalRevisionWrite(int $vid, string $langcode): self` (factory): Raised when a write targets a historical (non-tip) revision in a two-axis entity; stable `errorCode` `historical_revision_write` (M-004, WP04)

### field

| Element | Type | Purpose |
|---------|------|---------|
| `AbstractFieldType` | abstract class | — |
| `Classification\ClassificationClearanceCheckerInterface` | interface | — |
| `Classification\ClassificationLabelRegistryInterface` | interface | — |
| `Classification\ClassificationParentResolverInterface` | interface | — |
| `FieldDefinitionInterface` | interface | Describes a field: type, label, cardinality, settings, and constraints |
| `FieldFormatterInterface` | interface | Plugin interface for rendering a field item list for display |
| `FieldReadDefinitionInterface` | interface | Additive companion exposing nullable read classification without changing third-party `FieldDefinitionInterface` implementations |
| `FieldReadMetadataSource` | enum | Records whether classification came from a definition, legacy internal setting, site artifact, or remains unclassified |
| `FieldStorage` | enum | — |
| `FieldStorageSchemaContext` | enum | — |
| `FieldTypeInterface` | interface | Plugin interface for field type implementations providing column and property schemas |
| `FieldTypeManagerInterface` | interface | Discovers field type plugins and provides their default settings and column definitions |
| `FieldValueKind` | enum | — |
| `FieldValueKindProviderInterface` | interface | — |
| `FieldValueKindResolverInterface` | interface | — |
| `Item\LabeledCase` | interface | — |
| `ViewModeConfigInterface` | interface | Configures which fields and formatters are active for a given view mode |

- `FieldItemInterface` (interface): A single typed value within a field list, with property accessors and emptiness check
- `FieldItemListInterface` (interface): An ordered list of `FieldItemInterface` values for one field on one entity
- `FieldDefinition::translatable(bool $translatable = true): self` (builder method): Marks a field as translatable (per-langcode value). Calling on a non-translatable `EntityType`'s field fails at boot (M-006, WP03)
- `FieldDefinition::isTranslatable(): bool` (reader): Returns whether the field carries per-language values (M-006, WP03)
- `FieldItemBase` (abstract class): Base field item implementation combining plugin and typed-data behavior

### oidc

| Element | Type | Purpose |
|---------|------|---------|
| `Key\SigningKeyEmergencyRevocationService` | final readonly class | — |
| `Key\SigningKeyLifecyclePolicy` | final readonly class | — |
| `Key\SigningKeyRepository` | final class | — |
| `Key\SigningKeyRevocationRecord` | final readonly class | — |
| `Keys\OidcKeyLoaderInterface` | interface | — |
| `Keys\SigningAlgorithmPolicy` | final readonly class | — |
| `Keys\SigningKeySignerInterface` | interface | — |
| `Keys\SigningKeyState` | enum | — |
| `Rekey\AbstractOidcTokenRekeyAdapter` | abstract class | — |
| `Repository\AuthorizationCodeRepositoryInterface` | interface | — |
| `Token\KeyMaterialProviderInterface` | interface | — |

### testing

| Element | Type | Purpose |
|---------|------|---------|
| `Clock\MutableEntityClock` | final class | — |
| `Database\TemporarySqliteDatabase` | final class | — |
| `Factory\AuthorizationPrincipalFactory` | final class | — |
| `Factory\EntityFactory` | final class | — |
| `Factory\EntityTypeFactory` | final class | — |
| `Factory\EntityTypeFixtureValues` | final class | — |
| `Filesystem\TemporaryDirectory` | final class | — |
| `Kernel\KernelServicesFixture` | final class | — |
| `Traits\CreatesApplication` | trait | Bootstraps a Waaseyaa application instance for test suites |
| `Traits\InteractsWithApi` | trait | HTTP request helpers for making API calls in tests |
| `Traits\InteractsWithAuth` | trait | Simulates acting as a specific user without a full auth subsystem |
| `Traits\InteractsWithEvents` | trait | Captures and asserts on dispatched domain events in tests |
| `Traits\RefreshDatabase` | trait | Wraps each test in a transaction and rolls back after, keeping the database clean |
| `WaaseyaaTestCase` | abstract class | — |

### user

| Element | Type | Purpose |
|---------|------|---------|
| `Authentication\AuthenticationEligibilityInterface` | interface | — |
| `Authentication\AuthenticationStage` | enum | — |

## Layer 2: Content Types

### attachment

| Element | Type | Purpose |
|---------|------|---------|
| `Http\AttachmentDownloadMetadataReaderInterface` | interface | — |

### groups

| Element | Type | Purpose |
|---------|------|---------|
| `StaffDirectory\StaffDirectoryPage` | final readonly class | — |
| `StaffDirectory\StaffDirectoryReadDeclaration` | final readonly class | — |
| `StaffDirectory\StaffDirectoryReaderInterface` | interface | — |

### media

| Element | Type | Purpose |
|---------|------|---------|
| `FileRepositoryInterface` | interface | CRUD operations for file value objects keyed by URI |
| `Http\MediaDownloadSourceReaderInterface` | interface | — |

### relationship

| Element | Type | Purpose |
|---------|------|---------|
| `EntityVisibilityFilterInterface` | interface | — |
| `VisibilityFilterInterface` | interface | Filters relationship results based on viewer access |

## Layer 3: Services

### billing

| Element | Type | Purpose |
|---------|------|---------|
| `PlanTier` | enum | — |
| `StripeClientInterface` | interface | — |

### listing

| Element | Type | Purpose |
|---------|------|---------|
| `EntityRepositoryRegistry` | final class | — |
| `Exception\ListingCoercionException` | final class | — |
| `Exception\UnknownListingException` | final class | Registry miss (carries listing id) |
| `Exception\UnsupportedListingException` | final class | Definition-time validation failure (carries listing id, field name, reason) |
| `ExposedFilterCoercer` | final class | — |
| `ExposedFilterParser` | final class | Parses query params into `ExposedFilterValues`; never throws on user input |
| `ExposedFilterValues` | final readonly class | Typed view over parsed `$_GET` slice passed to `ListingResolver::resolve()` |
| `Filter` | final class | Sugar factories: `eq()`, `gte()`, `in()`, `isNull()`, `langcode()`, `exposed()`, etc. |
| `FilterDefinition` | final readonly class | Field + operator + value; optional `exposedParam` for URL-driven filters |
| `HasListingsInterface` | interface | ServiceProviders implement to declare listings; mirrors `HasMigrationsInterface` |
| `ListingCacheInvalidator` | final class | — |
| `ListingCacheKeyBuilder` | final class | — |
| `ListingDefinition` | final readonly class | Immutable listing manifest: id, entity type, filters, sorts, page size, access ops |
| `ListingDefinitionRegistry` | final class | `get(string $id): ListingDefinition` — throws `UnknownListingException` on miss |
| `ListingDefinitionValidator` | final class | — |
| `ListingDiscoverer` | final class | — |
| `ListingResolver` | final class | Single public method `resolve(ListingDefinition, ?ExposedFilterValues): ListingResult` |
| `ListingResult` | final readonly class | Resolution result: rows + pagination + cache tags + cache contexts |
| `Operator` | enum | Filter vocabulary: EQ, NEQ, LT, LTE, GT, GTE, IN, NOT_IN, IS_NULL, IS_NOT_NULL, BETWEEN, STARTS_WITH, CONTAINS |
| `Pagination` | final readonly class | Page metadata: page, page size, total rows, total pages, hasPrev, hasNext |
| `Sort` | final class | Sugar factories: `asc()`, `desc()` |
| `SortDefinition` | final readonly class | Field + direction; resolver appends an implicit id tie-break sort |
| `SortDirection` | enum | ASC, DESC |

### migration

| Element | Type | Purpose |
|---------|------|---------|
| `ContentModel\DerivesContentModelInterface` | interface | — |
| `Discovery\HasMigrationsInterface` | interface | Marker for service providers contributing migration manifests (FR-003, WP02) |
| `Exception\DestinationWriteException` | final class | Destination plugin failed to write; carries `$reason` code (WP05) |
| `Exception\MigrationAbortedException` | final class | Operator-triggered abort surfaced from runner / signal handler (WP06) |
| `Exception\MigrationConcurrencyException` | final class | Per-migration lock contention; carries `holdingPid` + `lockPath` (FR-061, WP09) |
| `Exception\MigrationCycleException` | final class | Dependency-graph cycle detected during discovery (WP02) |
| `Exception\MigrationDependencyMissingException` | final class | Migration depends on an unknown id (WP02) |
| `Exception\MigrationPluginCollisionException` | final class | Two plugins claim the same id; reserved-id collisions set `$isReserved=true` (WP01) |
| `Exception\ProcessException` | final class | Process plugin raised during per-field transformation (WP03) |
| `Exception\SourceReadException` | final class | Source plugin failed to read a record / opened-file errors (WP01) |
| `Log\Channels` | final class | Logger channel constants: `MIGRATION_DEPRECATION`, `MIGRATION_DISCOVERY` (WP01) |
| `MigrationDefinition` | final readonly class | Immutable migration definition: id, source, processors, destination, dependencies, stability (WP02) |
| `Plugin\DestinationPluginInterface` | interface | Destination plugin SPI: `write`, `rollback`, `lookup` per source id (FR-006, WP01) |
| `Plugin\DestinationRecord` | final readonly class | DTO carrying processed fields to a destination plugin: `entityType`, `bundle`, `fields`, `sourceId`, `sourceRecordHash` (WP01) |
| `Plugin\Destination\EntityDestination` | final class | Built-in destination writing to Waaseyaa entities via `EntityRepository` (FR-018..FR-029, WP05/WP08) |
| `Plugin\Destination\EntityDestinationFactory` | final class | Constructs `EntityDestination` instances bound to a migration id (WP05) |
| `Plugin\ProcessContext` | final readonly class | Per-field processor context carrying current record + run metadata (WP01) |
| `Plugin\ProcessPluginInterface` | interface | Per-field record transformer SPI (FR-005, WP01) |
| `Plugin\Process\ConcatProcessor` | final readonly class | Reference processor: concatenates multiple source fields (WP03) |
| `Plugin\Process\DefaultValueProcessor` | final readonly class | Reference processor: substitutes a default when the input is null/empty (WP03) |
| `Plugin\Process\HtmlSanitizeProcessor` | final readonly class | Reference processor: sanitises HTML field values (WP03) |
| `Plugin\Process\LookupProcessor` | final readonly class | Reference processor: resolves cross-migration lookups via `MigrationIdMap` (FR-028, WP03) |
| `Plugin\Process\PassThroughProcessor` | final readonly class | Reference processor: emits the input value unchanged (WP03) |
| `Plugin\Process\TypeCoerceProcessor` | final readonly class | Reference processor: coerces strings to int/float/bool (WP03) |
| `Plugin\ReservedPluginIds` | final class | Constants for framework-reserved plugin ids; collision raises `MigrationPluginCollisionException` (WP01) |
| `Plugin\SourcePluginInterface` | interface | Source plugin SPI: streams `SourceRecord` instances and assigns `SourceId`s (FR-049, WP01) |
| `Plugin\SourceRecord` | final readonly class | DTO carrying a raw row from a source plugin: `sourceType`, `fields` (WP01) |
| `Plugin\WriteResult` | final readonly class | Destination write outcome: `destinationEntityType`, `destinationUuid`, `sourceRecordHash`, `runId`, `writtenAt` (FR-006, WP01) |
| `Schema\MigrationIdMapSchema` | final class | DDL builder for the `migration_id_map` table (FR-029, WP04) |
| `Security\MigrationAuditedFieldReader` | final readonly class | Explicit `AuditedFieldRead::read` call site supplied to migration code |
| `Security\MigrationFieldReadCapabilityIssuer` | final readonly class | Issues NoActingContext MigrationImport capabilities from manifests |
| `Security\MigrationFieldReadManifest` | final readonly class | Exact privileged field reads reviewed for one migration id |
| `SourceId` | final readonly class | Stable composite key identifying a source record across re-runs (WP01) |
| `Testing\DestinationConformanceTestCase` | abstract class | Conformance harness third-party destination plugins extend (FR-050/FR-051, WP10; autoload-dev) |
| `Testing\SourceConformanceTestCase` | abstract class | Conformance harness third-party source plugins extend (FR-052, WP10; autoload-dev) |

### notification

| Element | Type | Purpose |
|---------|------|---------|
| `ChannelInterface` | interface | Delivers a notification to a notifiable recipient via one transport |
| `NotifiableInterface` | interface | Marks a recipient as notification-capable and provides channel routing |
| `NotifiableTrait` | trait | Default `NotifiableInterface` implementation routing by channel for entity classes |
| `NotificationInterface` | interface | Defines which channels to deliver through and provides channel-specific payloads |

### page-builder

| Element | Type | Purpose |
|---------|------|---------|
| `Command\EditCommand` | interface | — |
| `Draft\AdvisoryAwareLayoutDraftGatewayInterface` | interface | — |
| `Draft\Exception\LayoutSaveAdvisoryException` | final class | — |
| `Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException` | final class | — |
| `Draft\InitialLayoutDocumentProviderInterface` | interface | — |
| `Draft\LayoutDraftGatewayInterface` | interface | — |
| `Draft\LayoutSaveAdvisoryAcknowledgementDispatcher` | final class | — |
| `Preview\RevisionPreviewGatewayInterface` | interface | — |
| `Preview\RevisionPreviewUrlGeneratorInterface` | interface | — |
| `Revision\PageBuilderRevisionGatewayInterface` | interface | — |

### publishing

| Element | Type | Purpose |
|---------|------|---------|
| `AdvisoryAwareContentDraftMutationInterface` | interface | — |
| `ContentDraftMutationInterface` | interface | — |
| `ContentHtmlSanitizerInterface` | interface | — |
| `ContentPublicationTransitionerInterface` | interface | — |
| `ContentRevisionHistoryInterface` | interface | — |
| `ContentRevisionPreviewInterface` | interface | — |
| `ContentValidatorInterface` | interface | — |
| `Exception\ContentPublishingException` | abstract class | — |
| `Exception\UnsupportedSaveAdvisoryAcknowledgementException` | final class | — |
| `SaveAdvisoryAcknowledgementDispatcher` | final class | — |

### search

| Element | Type | Purpose |
|---------|------|---------|
| `BatchSearchIndexerInterface` | interface | — |
| `Projection\EntitySearchDocumentId` | final class | Creates stable search document identifiers for projected entities |
| `Projection\EntitySearchProjectionRegistry` | final class | Selects the first application or framework projector that supports an entity |
| `Projection\EntitySearchProjectorInterface` | interface | Projects a normal entity (Node) into an indexable search document without an upward search dependency |
| `Projection\NodeSearchProjector` | final class | Provides the framework default projection for public Node content |
| `Projection\SearchTextNormalizer` | final class | Converts CMS field values into inert plain searchable text |
| `ProvidesEntitySearchProjectorsInterface` | interface | Contributes application entity search projectors ordered ahead of the built-in node default |
| `ProvidesSearchSourceResolversInterface` | interface | Contributes exact-namespace resolvers for canonical non-entity search sources |
| `SearchCandidateResolverInterface` | interface | Resolves an opaque index pointer to a canonical principal-safe projection |
| `SearchContentCatalogueInterface` | interface | Lists and reads bounded canonical content projections under an explicit immutable principal |
| `SearchIndexableInterface` | interface | Marks an entity as searchable and provides its document ID and text fields |
| `SearchIndexerInterface` | interface | Adds, updates, and removes documents from the search index |
| `SearchProviderInterface` | interface | Executes principal-scoped full-text search queries and returns safe ranked results |

### seo

| Element | Type | Purpose |
|---------|------|---------|
| `Discovery\CrawlEligibilityPolicyInterface` | interface | Application-owned restriction of which entity types may be crawled |
| `Discovery\DiscoveryFailurePolicy` | enum | Selects empty-document degradation or propagation for a failed crawler surface |
| `Discovery\Exception\DiscoveryConfigurationException` | final class | Canonical URL policy is bound but no trusted public origin is configured |
| `Discovery\NonPublicEntityTypes` | final class | Framework-owned floor of entity types that are never crawled; applications may narrow it, never widen it |
| `Discovery\PublicUrlPolicyInterface` | interface | Application-owned canonical and Markdown-representation paths for the crawler-facing surfaces |
| `Discovery\SitemapContributorInterface` | interface | Contributes non-entity sitemap URLs without replacing the SEO controller |
| `Discovery\SitemapPath` | final readonly class | Validated root-relative sitemap entry returned by a contributor |

### structured-import

| Element | Type | Purpose |
|---------|------|---------|
| `Mapping\MappingConflictCode` | enum | — |
| `Mapping\MappingDecision` | enum | — |
| `StructuredImporterInterface` | interface | — |
| `Xlsx\XlsxCellType` | enum | — |
| `Xlsx\XlsxInspectionError` | enum | — |

### workflows

| Element | Type | Purpose |
|---------|------|---------|
| `Event\WorkflowEvents` | enum | — |

## Layer 4: API

### api

| Element | Type | Purpose |
|---------|------|---------|
| `Audit\AuditQueryReadModelInterface` | interface | — |
| `ContentSearch\ContentSearchRateLimiterInterface` | interface | — |
| `ContentSearch\ContentSearchReadModelInterface` | interface | — |
| `McpAdmin\ServerConfigReadModelInterface` | interface | — |
| `McpAdmin\ToolRegistryReadModelInterface` | interface | — |
| `Media\MediaVersionReadModelInterface` | interface | — |
| `MercureMonitor\ChannelInspectorInterface` | interface | — |
| `MercureMonitor\EventStreamReadModelInterface` | interface | — |
| `MercureMonitor\SubscriberObserverInterface` | interface | — |
| `MutableTranslatableInterface` | interface | Extends `TranslatableInterface` with `addTranslation()` for explicit translation creation |

### bimaaji

| Element | Type | Purpose |
|---------|------|---------|
| `Graph\GraphSectionProviderInterface` | interface | — |
| `Install\ClientTransformerInterface` | interface | — |
| `Install\Client\AbstractSingleFileClientTransformer` | abstract class | — |
| `Install\SkillDeliveryMode` | enum | — |
| `Install\SkillResourceFailure` | enum | — |

### routing

| Element | Type | Purpose |
|---------|------|---------|
| `Controller` | abstract class | — |
| `Language\LanguageNegotiatorInterface` | interface | Detects the active language from a request via path prefix, domain, or header |

## Layer 5: AI

### ai-agent

| Element | Type | Purpose |
|---------|------|---------|
| `Account\InitiatorAccountLoaderInterface` | interface | — |
| `AgentDefinition` | final readonly class | — |
| `AgentDefinitionRegistry` | final class | — |
| `Attribute\AsAgentDefinition` | final class | — |
| `Broadcast\AgentRunBroadcasterInterface` | interface | — |
| `Enum\EventType` | enum | — |
| `Enum\HitlMode` | enum | — |
| `Enum\RunStatus` | enum | — |
| `LocalOperator\LocalOperatorAccountContextGuard` | final class | — |
| `LocalOperator\LocalOperatorPrincipal` | final class | — |
| `LocalOperator\LocalOperatorRefusal` | final class | — |
| `LocalOperator\LocalOperatorToolProfile` | final class | — |
| `LocalOperator\LocalOperatorTransportAttestation` | final class | — |
| `Provider\ProviderException` | abstract class | — |
| `Provider\ProviderInterface` | interface | AI model provider: sends messages and returns a structured response |
| `Provider\StreamingProviderInterface` | interface | Provider variant that streams partial response chunks as they arrive |
| `Security\AgentRunAccountProjectionReaderInterface` | interface | — |
| `Security\AgentRunWorkerReaderInterface` | interface | — |
| `Tool\Wayfinding\AbstractTrailTool` | abstract class | — |

- `ToolRegistryInterface` (interface): Provides the set of tools available to an AI agent

### ai-observability

| Element | Type | Purpose |
|---------|------|---------|
| `Recorder\AgentRunMetricsRecorderInterface` | interface | — |
| `Recorder\AgentTelescopeRecorderInterface` | interface | — |
| `Recorder\TraceRecorderInterface` | interface | — |
| `Value\BudgetDecision` | enum | — |

### ai-tools

| Element | Type | Purpose |
|---------|------|---------|
| `AbstractAgentTool` | abstract class | — |
| `AgentTool` | final readonly class | — |
| `AgentToolInterface` | interface | — |
| `AgentToolResult` | final readonly class | — |
| `Attribute\AsAgentTool` | final class | — |
| `Content\AssetStoreInterface` | interface | — |
| `Dispatch\ToolDispatcherInterface` | interface | — |
| `ProvidesAgentToolsInterface` | interface | — |
| `Resource\ContentResourceProviderInterface` | interface | Contributes bounded principal-explicit content resources without coupling MCP to content packages |
| `ToolRegistryInterface` | interface | — |

### ai-vector

| Element | Type | Purpose |
|---------|------|---------|
| `DistanceMetric` | enum | — |
| `EmbeddingInterface` | interface | Extends `EmbeddingProviderInterface` with batch embedding generation |
| `EmbeddingProviderInterface` | interface | Generates a vector embedding for a single text string |
| `EmbeddingStorageInterface` | interface | Stores and similarity-searches raw float vectors by entity type and ID |
| `VectorStoreInterface` | interface | Stores and queries entity embeddings in a vector backend (pgvector, Qdrant, etc.) |

## Layer 6: Interfaces

### admin-surface

| Element | Type | Purpose |
|---------|------|---------|
| `Action\SurfaceActionHandlerInterface` | interface | Handles a custom admin surface action for a given entity type and payload |
| `Host\AbstractAdminSurfaceHost` | abstract class | Base class applications extend to integrate with the admin SPA (session, catalog, entity ops) |
| `Host\AdminPublicationFieldReaderInterface` | interface | Closed application-wiring boundary for authorized node publication metadata in admin lists |
| `Host\AdminRevisionPreviewAuthorityInterface` | interface | — |
| `Host\AdminSurfaceHostFactoryInterface` | interface | — |
| `Host\BatchAdminPublicationFieldReaderInterface` | interface | Cardinality-preserving batch extension that projects an authorized list scope transactionally |
| `List\ListFormatter` | enum | — |
| `PageBuilder\PageBuilderSurfaceHostInterface` | interface | — |
| `Query\SurfaceFilterOperator` | enum | — |

### cli

| Element | Type | Purpose |
|---------|------|---------|
| `AdminBuild\AdminBuildPlatform` | enum | — |
| `AdminBuild\AdminBuildProcessResult` | final readonly class | — |
| `AdminBuild\AdminBuildProcessRunnerInterface` | interface | — |
| `Command\Config\ConfigCommand` | abstract class | — |
| `Command\Config\ConfigDiffCommand` | final class | — |
| `Command\Config\ConfigExportCommand` | final class | — |
| `Command\Config\ConfigImportCommand` | final class | — |
| `Command\Config\ConfigManifestSignCommand` | final class | — |
| `Command\Config\ConfigResetCommand` | final class | — |
| `Command\Config\ConfigStatusCommand` | final class | — |
| `Command\Config\ConfigValidateCommand` | final class | — |
| `Command\HandlerArgumentMode` | enum | — |
| `Command\HandlerOptionMode` | enum | — |
| `Command\Make\AbstractMakeHandler` | abstract class | — |
| `Command\Migration\BackfillHelper` | final class | — |
| `Command\Migration\BackfillRowCountMismatchException` | final class | — |
| `Command\Migration\StorageMigrationEmitter` | final class | — |
| `Command\Migration\StorageMigrationTemplate` | final class | — |
| `Command\Migration\UnmappedFieldTypeException` | final class | — |
| `Handler\MakeStorageMigrationHandler` | final class | — |
| `Handler\MutationAuthorityBackfillHandler` | final readonly class | — |
| `Io\StdinSource` | interface | — |
| `Provider\MakeStorageMigrationServiceProvider` | final class | — |
| `Security\CliFieldReadCapabilityDeclaration` | final readonly class | Exact command scope and closed CLI-valid privileged-read reason |
| `Security\CliFieldReadCapabilityIssuer` | final readonly class | Issues null-actor NoActingContext capabilities from command metadata |
| `Site\SiteHostPlatform` | enum | — |
| `Site\SitePathContainment` | final class | — |
| `Site\SitePreset` | enum | — |

### deployer

| Element | Type | Purpose |
|---------|------|---------|
| `RuntimeState\RuntimeTablePolicy` | enum | Versioned ownership policy for framework SQLite artifact and serving-host runtime tables |

### genealogy

| Element | Type | Purpose |
|---------|------|---------|
| `Access\GenealogyInternalFieldReaderInterface` | interface | — |

### mcp

| Element | Type | Purpose |
|---------|------|---------|
| `Admin\RecentInvocationsQueryInterface` | interface | — |
| `Auth\McpAuthInterface` | interface | Authenticates MCP requests and resolves the immutable acting authorization principal |
| `Auth\OAuthAccessTokenValidatorInterface` | interface | Validates OAuth access tokens for one exact MCP resource and returns an active scoped principal |
| `Auth\ScopedMcpAuthInterface` | interface | — |
| `Auth\WriteTierAuthInterface` | interface | — |

- `ToolExecutorInterface` (interface): Executes an MCP tool call by name with arguments and returns structured content
- `ToolRegistryInterface` (interface): Provides the full list of MCP tool definitions for the protocol manifest

### ssr

| Element | Type | Purpose |
|---------|------|---------|
| `Http\AppController\AppControllerArgumentResolver` | interface | — |
| `Http\AppController\AppParameterKind` | enum | — |
| `PageComposition\EntityPageComposerInterface` | interface | — |
| `ThemeInterface` | interface | Provides a theme's identifier and its Twig template directory paths |
