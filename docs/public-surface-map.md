# Waaseyaa Public Surface Map

This document lists every intentionally public API element in the Waaseyaa framework.
Elements not listed here are `@internal` and may change without notice.

Verified by `tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php`.
Machine-readable source: `docs/public-surface-map.php`.

---

## Layer 0: Foundation

### foundation

| Element | Type | Purpose |
|---------|------|---------|
| `AssetManagerInterface` | interface | Resolves source asset paths to versioned/hashed production URLs via build manifests |
| `HealthCheckerInterface` | interface | Runs boot, runtime, and ingestion health checks across subsystems |
| `LoggerInterface` | interface | Structured logger with PSR-3-style severity levels (framework-internal, not psr/log) |
| `HandlerInterface` | interface | Log handler that receives and writes formatted log records |
| `FormatterInterface` | interface | Formats a log record into its final string or array representation |
| `ProcessorInterface` | interface | Enriches log records with additional context before handling |
| `LoggerTrait` | trait | Default implementations of all log-level methods delegating to `log()` |
| `HttpHandlerInterface` | interface | Terminal HTTP request handler (innermost layer of the middleware onion) |
| `HttpMiddlewareInterface` | interface | Wraps an HTTP handler to add cross-cutting behavior |
| `JobHandlerInterface` | interface | Terminal queue job handler |
| `JobMiddlewareInterface` | interface | Wraps a job handler to add cross-cutting behavior |
| `RateLimiterInterface` | interface | Checks and records attempt counts for rate limiting |
| `SchemaRegistryInterface` | interface | Stores and retrieves JSON Schema entries by entity type ID |
| `ServiceProviderInterface` | interface | Contract for packages to register and boot their services |
| `ServiceProvider` | abstract class | Base class for service providers with DI binding and resolution helpers |
| `DomainEvent` | abstract class | Base class for all domain events carrying aggregate identity and actor context |
| `WaaseyaaException` | abstract class | Base exception for all framework errors, with HTTP status code and problem type |
| `JsonApiResponseTrait` | trait | Builds JSON:API responses with correct content type and encoding options |
| `Http\RequestContext` | interface | Exposes the active request's roles, user id, languages, and query params to `ContextResolver` (charter §5.9) |
| `Migration` | abstract class | Base class for database migrations with optional rollback and ordering |

### cache

| Element | Type | Purpose |
|---------|------|---------|
| `CacheBackendInterface` | interface | Reads and writes cache items with optional tag and expiry support |
| `CacheFactoryInterface` | interface | Creates or retrieves cache backend instances by bin name |
| `CacheTagsInvalidatorInterface` | interface | Invalidates all cache items associated with a set of tags |
| `TagAwareCacheInterface` | interface | Cache backend that supports tag-based invalidation |
| `TaggedCacheInterface` | interface | Listing-pipeline tag-aware ops (`setWithTags`, `invalidateByTag`, `getTagsFor`) — charter §5.9 |
| `ContextRegistry` | service | Whitelist of canonical context names for cache-key segmentation (charter §5.9) |
| `ContextResolver` | service | Resolves a context name against a `RequestContext` into a deterministic short string |
| `ContextNames` | constants class | Canonical context-name constants (`USER_ROLES`, `USER_ID`, `LANGUAGE_CONTENT`, `LANGUAGE_INTERFACE`, `URL_QUERY_PREFIX`) |
| `Exception\InvalidCacheTagException` | exception | Thrown by `setWithTags()` on malformed tag strings (no silent normalisation) |

### database-legacy

| Element | Type | Purpose |
|---------|------|---------|
| `DatabaseInterface` | interface | Doctrine DBAL abstraction: query builder entry point for select, insert, update, delete |
| `SelectInterface` | interface | Fluent SELECT query builder with conditions, joins, ordering, and pagination |
| `InsertInterface` | interface | Fluent INSERT query builder |
| `UpdateInterface` | interface | Fluent UPDATE query builder with conditions |
| `DeleteInterface` | interface | Fluent DELETE query builder with conditions |
| `SchemaInterface` | interface | DDL operations: create/alter/drop tables and columns |
| `TransactionInterface` | interface | Wraps database operations in a named transaction with commit/rollback |

### plugin

| Element | Type | Purpose |
|---------|------|---------|
| `PluginInspectionInterface` | interface | Provides read access to a plugin's ID and definition |
| `PluginManagerInterface` | interface | Discovers, retrieves, and instantiates plugins by ID |
| `PluginBase` | abstract class | Base implementation of `PluginInspectionInterface` for all plugin types |

### typed-data

| Element | Type | Purpose |
|---------|------|---------|
| `TypedDataInterface` | interface | Typed wrapper around a scalar or complex value with validation and string casting |
| `DataDefinitionInterface` | interface | Describes a typed data property: type, label, required, read-only, constraints |
| `ComplexDataInterface` | interface | Typed data with named properties (traversable, get/set by name) |
| `ListInterface` | interface | Ordered, typed list of `TypedDataInterface` items |
| `PrimitiveInterface` | interface | Typed scalar value with cast accessor |
| `TypedDataManagerInterface` | interface | Creates typed data definitions and instances by data type name |
| `CastTokenMapper` | final class | Maps entity `$casts` tokens to `TypedDataManager` data type names (#1185) |
| `CoercionException` | final class | Thrown when entity-parity primitive/JSON-array coercion fails (#1185) |
| `EntityCastCoercion` | final class | Storage ↔ domain coercion for `int`/`float`/`bool`/`string`/`array` casts (#1185) |

### i18n

| Element | Type | Purpose |
|---------|------|---------|
| `LanguageManagerInterface` | interface | Manages the set of available languages and their default |
| `TranslatorInterface` | interface | Translates keys with optional parameter substitution and locale override |
| Config key `translation.fallback_chain` | config | Array of langcodes (M-006, WP06 / ADR 017). Read by `FallbackChainResolver` when a translatable field has no value for the requested langcode |
| Config key `translation.read_active_language` | config | Bool (M-006, WP06). When `true`, `EntityRepository::find()` consults the `LanguageManager` and returns the entity in the active language instead of the default langcode |

### queue

| Element | Type | Purpose |
|---------|------|---------|
| `QueueInterface` | interface | Dispatches messages to the queue for asynchronous processing |

### testing

| Element | Type | Purpose |
|---------|------|---------|
| `CreatesApplication` | trait | Bootstraps a Waaseyaa application instance for test suites |
| `InteractsWithApi` | trait | HTTP request helpers for making API calls in tests |
| `InteractsWithAuth` | trait | Simulates acting as a specific user without a full auth subsystem |
| `InteractsWithEvents` | trait | Captures and asserts on dispatched domain events in tests |
| `RefreshDatabase` | trait | Wraps each test in a transaction and rolls back after, keeping the database clean |

---

## Layer 1: Core Data

### entity

| Element | Type | Purpose |
|---------|------|---------|
| `EntityInterface` | interface | Core contract for all entity types: identity, label, type ID, and value access |
| `EntityBase` | abstract class | Default implementations of `EntityInterface`; subclasses hardcode entity type ID and keys |
| `ContentEntityBase` | abstract class | Fieldable entity base supporting dynamic field values and per-language translation |
| `ContentEntityInterface` | interface | Marker combining `EntityInterface` and `FieldableInterface` for content entities |
| `ConfigEntityBase` | abstract class | Base for configuration entities with string machine-name IDs and enable/disable lifecycle |
| `ConfigEntityInterface` | interface | Contract for configuration entities including status and enabled/disabled state |
| `EntityTypeInterface` | interface | Describes an entity type: ID, label, class, keys, field definitions, and constraints |
| `EntityTypeManagerInterface` | interface | Registers entity types and provides storage instances for each |
| `FieldableInterface` | interface | Marks an entity as supporting named field access and definition retrieval |
| `RevisionableInterface` | interface | Adds revision ID tracking and new-revision control to an entity |
| `TranslatableInterface` | interface | Per-language translation access for a translatable entity (M-006 / ADR 017): `getTranslation`, `hasTranslation`, `addTranslation`, `removeTranslation`, `translations`, `defaultLangcode`, `activeLangcode`, `fieldLangcode`. `language()` retained as deprecated alias for `activeLangcode()` |
| `EntityTranslationException` | final class | Translation persistence error with named-constructor factories `langcodeRequired`, `cannotRemoveDefault`, `translationAlreadyExists`, `translationNotFound` (M-006, WP01) |
| `TranslationEvent` | class | Translation lifecycle event extending `EntityEvent`; carries entity, target langcode, and (for updates/deletes) prior values (M-006, WP08). Event-name constants: `PRE_TRANSLATION_INSERT`, `POST_TRANSLATION_INSERT`, `PRE_TRANSLATION_UPDATE`, `POST_TRANSLATION_UPDATE`, `PRE_TRANSLATION_DELETE`, `POST_TRANSLATION_DELETE` |
| `EntityEvent` | class | Lifecycle event base; non-`final` so `TranslationEvent` may extend it (M-006, WP08 — public-surface change documented in mission reconciliation note) |
| `EntityType::__construct(...translatable: bool = false, ...)` | constructor arg | Marks an entity type as translatable; load-bearing — enforced by boot validation (M-006, WP02) |
| Entity key string `'default_langcode'` | key | Required entry in `EntityType::$keys` for translatable types; identifies the column carrying the canonical (default) langcode (M-006, WP02) |
| `RevisionableEntityTrait` | trait | Default implementation of `RevisionableInterface` using `$values` and `$entityKeys` |
| `EntityRepositoryInterface` | interface | High-level CRUD API handling hydration, event dispatch, and language fallback. Adds `findTranslations(EntityInterface): array<string, EntityInterface>` (M-006, WP10) |
| `EntityEventFactoryInterface` | interface | Creates `EntityEvent` instances with optional before/after snapshots |
| `EntityStorageInterface` | interface | Lower-level storage operations: load, save, delete, query |
| `RevisionableStorageInterface` | interface | Extends entity storage with load, delete, and list operations for specific revisions |
| `EntityQueryInterface` | interface | Fluent query builder for filtering and loading entities by field conditions |

### entity-storage

| Element | Type | Purpose |
|---------|------|---------|
| `EntityStorageDriverInterface` | interface | Low-level persistence SPI: raw row I/O without hydration or event dispatch. Adds `findTranslations(EntityInterface): array<string, EntityInterface>` (M-006, WP10) |
| `ConnectionResolverInterface` | interface | Resolves named database connections; multi-tenancy seam for entity storage |
| `FieldStorageBackendInterface` | interface | Contract for pluggable field storage backends (M-001, WP01) |
| `HasFieldStorageBackendsInterface` | interface | Mix-in for packages that provide custom field storage backends (M-001, WP01) |
| `IsFrameworkBackendProviderInterface` | interface | Marker for built-in framework backend providers; do not implement in application code (M-001, WP01) |
| `ReservedBackendIds` | final class | String constants for built-in backend ids: `SQL_BLOB`, `SQL_COLUMN`, `VECTOR` (M-001, WP01) |
| `BackendRegistrar` | final class | Registers field storage backends by id for an entity type (M-001, WP01) |
| `BackendRegistrarFactory` | final class | Creates a `BackendRegistrar` bound to a specific entity type (M-001, WP01) |
| `UnsupportedQueryException` | final class | Thrown when a query operator is unsupported by the active backend (M-001, WP01) |
| `UnsupportedListingException` | final class | Thrown when listing is unsupported by the active backend (M-001, WP06) |
| `EntityStorageCoordinator` | final class | Fan-out engine dispatching read/write/delete to all registered backends (M-001, WP02) |
| `BackendResolver` | final class | Resolves which backend handles a given `FieldDefinition` (M-001, WP02) |
| `UnknownBackendException` | final class | Thrown when a field references an unregistered backend id (M-001, WP02) |
| `SqlBlobBackend` | final class | Stores field values in a JSON `_data` blob column; `supportsQuery()` always false (M-001, WP03) |
| `EntityLifecycleEventInterface` | interface | Marker for all four coordinator lifecycle events (M-001, WP04) |
| `BeforeSaveEvent` | final class | Dispatched before any backend write; listeners may abort via `AbortOperationException` (M-001, WP04) |
| `AfterSaveEvent` | final class | Dispatched after all backends commit; not dispatched on partial failure (M-001, WP04) |
| `BeforeDeleteEvent` | final class | Dispatched before any backend delete (M-001, WP04) |
| `AfterDeleteEvent` | final class | Dispatched after all backends confirm delete (M-001, WP04) |
| `AbortOperationException` | final class | Thrown from `BeforeSave`/`BeforeDelete` listener to abort the operation (M-001, WP04) |
| `PartialSaveException` | final class | Thrown when at least one backend succeeds and one fails; carries `$errorCode` (M-001, WP04) |
| `SaveContext` | final class | Immutable value object passed to save operations; carries revision flags and translation langcode. `withLangcode(string $langcode): self` returns an immutable copy targeting a translation write (M-001, WP04 + M-006, WP07) |
| `EntityRepository::findTranslations(EntityInterface): array<string, EntityInterface>` | method | Returns every translation of the given entity, keyed by langcode, default-langcode first; single SQL query (M-006, WP10) |
| `CoordinatorLifecycleDispatcher` | final class | Dispatches lifecycle events from the coordinator (M-001, WP04) |
| `SqlColumnBackend` | final class | Stores each field in a dedicated SQL column; `supportsQuery()` true for non-vector types (M-001, WP05) |
| `SqlColumnSchemaBuilder` | final class | Builds SQL column schema for `SqlColumnBackend` (M-001, WP05) |
| `SqlColumnQueryTranslator` | final class | Translates field-level query predicates to SQL for `SqlColumnBackend` (M-001, WP05) |
| `TypeMapping` | final class | Maps `FieldDefinition` type strings to DBAL column types (M-001, WP05) |
| `DefinitionValidator` | final class | Validates `FieldDefinition` objects at registration time; throws `UnsupportedQueryException` (M-001, WP06) |
| `RevisionPruningPolicy` | final class | Immutable value object describing how many revisions to keep (M-001, WP08) |
| `RevisionPruningReport` | final class | Result of a pruning run: counts of deleted and retained revisions (M-001, WP08) |
| `RevisionTableBuilder` | final class | Creates the `{entity_type}_revision` schema table (M-001, WP07) |
| `FieldStorageBackendContractTestCase` | abstract class | Abstract PHPUnit harness; extend to verify any `FieldStorageBackendInterface` implementation (M-001, WP12) |
| `Waaseyaa\EntityStorage\Exception\StorageMigrationException` | final class | Typed exception for storage-migration / two-axis schema failures during kernel boot, schema sync, or migration generator runs. Stable `errorCode` strings: `no_op_promotion`, `unsupported_two_axis_field` (M-004, WP04) |
| `Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver` | final class | Driver-level two-axis save/load orchestration: composes `RevisionTableBuilder` + `TranslationSchemaHandler` and honours `SaveContext::withTranslations()` for atomic multi-language revision writes (M-004, WP03 + WP04) |
| `Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler` | final class | Emits the `<entity>__translation__revision` table for two-axis entities; pairs with `RevisionTableBuilder::buildTwoAxis()` (M-004, WP02) |
| `Waaseyaa\EntityStorage\Listing\TwoAxisFilterResolver` | final class | Resolves listing filters against two-axis storage: joins `<entity>__revision` to `<entity>__translation__revision` and applies langcode + revision-window selection (M-004, WP07) |
| `Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy` | final class | Two-axis pruning policy value object; keeps the M-001 `RevisionPruningPolicy` surface intact while extending semantics to per-langcode revision counts (M-004, WP05) |
| `SaveContext::withTranslations(array $langcodes): self` | method | Immutable copy carrying a `[langcode => values]` map for atomic multi-language revision writes; rejected if empty. Pairs with `withLangcode()` for single-language writes (M-004, WP03) |
| `EntityTranslationException::historicalRevisionWrite(int $vid, string $langcode): self` | factory | Raised when a write targets a historical (non-tip) revision in a two-axis entity; stable `errorCode` `historical_revision_write` (M-004, WP04) |

### access

| Element | Type | Purpose |
|---------|------|---------|
| `AccountInterface` | interface | Represents a user account for access checking: ID, roles, and permission checks |
| `AccessPolicyInterface` | interface | Checks entity-level access for view, update, delete, and (M-006 / ADR 017) `'translate'` operations |
| `ContextAwareAccessPolicyInterface` | interface | Companion to `AccessPolicyInterface` accepting a `$context` array (carries `langcode` for the `'translate'` operation and read-time langcode for `view`/`update`) (M-006, WP09) |
| `'translate'` access-policy operation | operation literal | Used by translation writes; resolves via `ContextAwareAccessPolicyInterface::access($operation, $entity, $account, ['langcode' => $lc])` (M-006, WP09) |
| `FieldAccessPolicyInterface` | interface | Checks field-level access on an entity; open-by-default (Forbidden restricts, Neutral allows) |
| `PermissionHandlerInterface` | interface | Manages the registry of available permissions and their metadata |
| `GateInterface` | interface | Resolves the policy for a subject and checks whether a user has a given ability |

### config

| Element | Type | Purpose |
|---------|------|---------|
| `ConfigInterface` | interface | Read/write access to a named configuration object with key-path addressing |
| `ConfigFactoryInterface` | interface | Creates and caches `ConfigInterface` instances by name |
| `ConfigManagerInterface` | interface | Manages config storage backends and export/import lifecycle |
| `StorageInterface` | interface | Reads and writes raw configuration data arrays by name |
| `TranslatableConfigFactoryInterface` | interface | Creates language-specific overrides of configuration objects |

#### config — CMI sync substrate (M-003)

Configuration Management v1 — active/sync store split, six `config:*` CLI commands, dependency-ordered import. Charter §5.5; spec [`docs/specs/config-management.md`](specs/config-management.md); cookbook [`docs/cookbook/config-sync.md`](cookbook/config-sync.md); ADR 018.

| Element | Type | Purpose |
|---------|------|---------|
| `Dependency\ConfigDependencyInterface` | interface | Config-entity contract: `configDependencies(): string[]` returns `<entity_type>.<entity_id>` ids consumed by the DAG (M-003, WP01) |
| `Dependency\Exception\ConfigDependencyCycleException` | exception | Raised when the sync-store DAG contains a cycle; carries the full cycle path (M-003) |
| `Dependency\Exception\ConfigDependencyMissingException` | exception | Raised when a `_meta.dependencies` entry references a config id absent from both stores (M-003) |
| `Sync\ConfigSyncFile` | value object | In-memory parsed sync file: `_meta` block + field values (M-003, WP02) |
| `Sync\ConfigSyncSerializer` | service | Entity → YAML; sorts keys alphabetically; emits leading `_meta` block (M-003, WP02) |
| `Sync\ConfigSyncDeserializer` | service | YAML → `ConfigSyncFile`; validates `_meta.entity_type` matches filename prefix (M-003, WP02) |
| `Sync\ConfigSyncRepository` | service | Filesystem read/write under `config.sync_path` (default `storage/config-sync/`) (M-003, WP02) |
| `Sync\ConfigSyncFileSourceInterface` | interface | Extension point: alternative sync-file sources (e.g. in-memory test sources) (M-003) |
| `Sync\ConfigSyncValidator` | service | Runs `FieldDefinition::validators()` over each sync file; powers `config:validate` (M-003, WP06) |
| `Sync\ConfigExporter` | service | Active → sync; backs `config:export`; honours `--diff` and `--dry-run` (M-003, WP03) |
| `Sync\ConfigImporter` | service | Sync → active in topological order; per-entity transaction; orphan-warn default (M-003, WP04) |
| `Sync\ConfigImportApplyHookInterface` | interface | Cross-cutting hook fired per applied entity during `config:import` (extension point) (M-003, WP04) |
| `Sync\ConfigDiffer` | service | Unified-diff renderer with UUID-tracked rename detection; backs `config:diff` (M-003, WP05) |
| `Sync\ConfigStatusReporter` | service | Computes in-sync / drift / sync-only / active-only counts; backs `config:status` (M-003, WP05) |
| `Sync\ConfigResetter` | service | Single-entity rollback from sync store; logs to `config.audit`; backs `config:reset` (M-003, WP07) |
| `Sync\ConfigManifestEntry` | value object | Per-entity manifest row consumed by exporter/importer dashboards (M-003) |
| `Audit\ConfigAuditChannel` | constants class | `CHANNEL` constant = `'config.audit'` (charter §4.4 amendment) (M-003, WP07) |
| `Audit\ConfigAuditEvent` | event payload | Entity-type, id, operation, actor, before-after diff summary (M-003, WP07) |
| `Backend\BackendRestrictionEnforcer` | service | Boot-time enforcement: config entities must declare `sql-blob` or `sql-column`; `ALLOWED_BACKEND_IDS` constant (M-003, WP08) |
| `Exception\InvalidConfigBackendException` | exception | Raised when a config entity declares `vector` / `remote` / other disallowed backend (M-003, WP08) |
| `Exception\ConfigSerializationException` | exception | Raised on `_meta.entity_type` mismatch or other YAML format errors (M-003, WP02) |
| `Exception\ConfigImportFailedException` | exception | Raised per-entity during `config:import`; carries entity id + cause (M-003, WP04) |
| `Exception\ConfigCommandCollisionException` | exception | Raised at boot when an app/extension command claims a reserved `config:*` sub-verb (M-003, WP09) |
| `Waaseyaa\CLI\Command\Config\ConfigCommand` | abstract class | Base for the six `config:*` commands; exposes `RESERVED_VERBS`, `RESERVED_FULL_VERBS`, `RESERVED_FQCNS` constants for collision checks (M-003, WP09) |
| `Waaseyaa\CLI\Command\Config\ConfigExportCommand` | command | `bin/waaseyaa config:export [--diff] [--dry-run]` (M-003, WP03) |
| `Waaseyaa\CLI\Command\Config\ConfigImportCommand` | command | `bin/waaseyaa config:import [--dry-run] [--delete-orphans] [--halt-on-error] [--no-dependency-check]` (M-003, WP04) |
| `Waaseyaa\CLI\Command\Config\ConfigDiffCommand` | command | `bin/waaseyaa config:diff [<entity-type>.<id>]` (M-003, WP05) |
| `Waaseyaa\CLI\Command\Config\ConfigStatusCommand` | command | `bin/waaseyaa config:status [--format=plain|json]` (M-003, WP05) |
| `Waaseyaa\CLI\Command\Config\ConfigValidateCommand` | command | `bin/waaseyaa config:validate` (M-003, WP06) |
| `Waaseyaa\CLI\Command\Config\ConfigResetCommand` | command | `bin/waaseyaa config:reset <entity-type>.<id> [--yes]` (M-003, WP07) |
| Sync-store file format | file format | `<entity_type>.<entity_id>.yml` with leading `_meta` block (charter §5.5; spec §5) |
| Config key `config.sync_path` | config | Filesystem root for the sync store (default `storage/config-sync/`) (M-003, FR-014) |
| `config.audit` log channel | log channel | Charter §4.4 amendment; receives import / export / reset audit events (M-003, FR-053) |

### field

| Element | Type | Purpose |
|---------|------|---------|
| `FieldItemInterface` | interface | A single typed value within a field list, with property accessors and emptiness check |
| `FieldItemListInterface` | interface | An ordered list of `FieldItemInterface` values for one field on one entity |
| `FieldDefinitionInterface` | interface | Describes a field: type, label, cardinality, settings, and constraints |
| `FieldDefinition::translatable(bool $translatable = true): self` | builder method | Marks a field as translatable (per-langcode value). Calling on a non-translatable `EntityType`'s field fails at boot (M-006, WP03) |
| `FieldDefinition::isTranslatable(): bool` | reader | Returns whether the field carries per-language values (M-006, WP03) |
| `FieldTypeInterface` | interface | Plugin interface for field type implementations providing column and property schemas |
| `FieldFormatterInterface` | interface | Plugin interface for rendering a field item list for display |
| `FieldTypeManagerInterface` | interface | Discovers field type plugins and provides their default settings and column definitions |
| `FieldItemBase` | abstract class | Base field item implementation combining plugin and typed-data behavior |
| `ViewModeConfigInterface` | interface | Configures which fields and formatters are active for a given view mode |

### oauth-provider

| Element | Type | Purpose |
|---------|------|---------|
| `OAuthProviderInterface` | interface | OAuth 2.0 provider abstraction: authorization URL, code exchange, token refresh, user profile |
| `SessionInterface` | interface | Manages OAuth session state (CSRF state token and post-auth redirect) |

---

## Layer 2: Content Types

### media

| Element | Type | Purpose |
|---------|------|---------|
| `FileRepositoryInterface` | interface | CRUD operations for file value objects keyed by URI |

### path

| Element | Type | Purpose |
|---------|------|---------|
| `PathAliasManagerInterface` | interface | Resolves and manages URL aliases for entity paths |

### relationship

| Element | Type | Purpose |
|---------|------|---------|
| `VisibilityFilterInterface` | interface | Filters relationship results based on viewer access |

---

## Layer 3: Services

### search

| Element | Type | Purpose |
|---------|------|---------|
| `SearchProviderInterface` | interface | Executes full-text search queries and returns ranked results |
| `SearchIndexerInterface` | interface | Adds, updates, and removes documents from the search index |
| `SearchIndexableInterface` | interface | Marks an entity as searchable and provides its document ID and text fields |

### notification

| Element | Type | Purpose |
|---------|------|---------|
| `NotificationInterface` | interface | Defines which channels to deliver through and provides channel-specific payloads |
| `NotifiableInterface` | interface | Marks a recipient as notification-capable and provides channel routing |
| `NotifiableTrait` | trait | Default `NotifiableInterface` implementation routing by channel for entity classes |
| `ChannelInterface` | interface | Delivers a notification to a notifiable recipient via one transport |

### migration

Mission `migration-platform-v1-01KRCDE9` (M-002). All entries below are intentional public surface — the plugin SPI, value objects, the framework destination, reference processors, exceptions, schema, log channels, and the conformance harness third-party plugin authors extend.

| Element | Type | Purpose |
|---------|------|---------|
| `SourcePluginInterface` | interface | Source plugin SPI: streams `SourceRecord` instances and assigns `SourceId`s (FR-049, WP01) |
| `ProcessPluginInterface` | interface | Per-field record transformer SPI (FR-005, WP01) |
| `DestinationPluginInterface` | interface | Destination plugin SPI: `write`, `rollback`, `lookup` per source id (FR-006, WP01) |
| `HasMigrationPluginsInterface` | interface | Marker for service providers exposing migration plugins via reflection discovery (WP01) |
| `HasMigrationsInterface` | interface | Marker for service providers contributing migration manifests (FR-003, WP02) |
| `MigrationDefinition` | final class | Immutable migration definition: id, source, processors, destination, dependencies, stability (WP02) |
| `SourceId` | final class | Stable composite key identifying a source record across re-runs (WP01) |
| `SourceRecord` | final class | DTO carrying a raw row from a source plugin: `sourceType`, `fields` (WP01) |
| `DestinationRecord` | final class | DTO carrying processed fields to a destination plugin: `entityType`, `bundle`, `fields`, `sourceId`, `sourceRecordHash` (WP01) |
| `WriteResult` | final class | Destination write outcome: `destinationEntityType`, `destinationUuid`, `sourceRecordHash`, `runId`, `writtenAt` (FR-006, WP01) |
| `ProcessContext` | final class | Per-field processor context carrying current record + run metadata (WP01) |
| `Destination\EntityDestination` | final class | Built-in destination writing to Waaseyaa entities via `EntityRepository` (FR-018..FR-029, WP05/WP08) |
| `Destination\EntityDestinationFactory` | final class | Constructs `EntityDestination` instances bound to a migration id (WP05) |
| `Process\PassThroughProcessor` | final class | Reference processor: emits the input value unchanged (WP03) |
| `Process\HtmlSanitizeProcessor` | final class | Reference processor: sanitises HTML field values (WP03) |
| `Process\LookupProcessor` | final class | Reference processor: resolves cross-migration lookups via `MigrationIdMap` (FR-028, WP03) |
| `Process\ConcatProcessor` | final class | Reference processor: concatenates multiple source fields (WP03) |
| `Process\TypeCoerceProcessor` | final class | Reference processor: coerces strings to int/float/bool (WP03) |
| `Process\DefaultValueProcessor` | final class | Reference processor: substitutes a default when the input is null/empty (WP03) |
| `ReservedPluginIds` | final class | Constants for framework-reserved plugin ids; collision raises `MigrationPluginCollisionException` (WP01) |
| `Exception\SourceReadException` | final class | Source plugin failed to read a record / opened-file errors (WP01) |
| `Exception\ProcessException` | final class | Process plugin raised during per-field transformation (WP03) |
| `Exception\DestinationWriteException` | final class | Destination plugin failed to write; carries `$reason` code (WP05) |
| `Exception\MigrationAbortedException` | final class | Operator-triggered abort surfaced from runner / signal handler (WP06) |
| `Exception\MigrationConcurrencyException` | final class | Per-migration lock contention; carries `holdingPid` + `lockPath` (FR-061, WP09) |
| `Exception\MigrationCycleException` | final class | Dependency-graph cycle detected during discovery (WP02) |
| `Exception\MigrationDependencyMissingException` | final class | Migration depends on an unknown id (WP02) |
| `Exception\MigrationPluginCollisionException` | final class | Two plugins claim the same id; reserved-id collisions set `$isReserved=true` (WP01) |
| `Schema\MigrationIdMapSchema` | final class | DDL builder for the `migration_id_map` table (FR-029, WP04) |
| `Log\Channels` | final class | Logger channel constants: `MIGRATION_DEPRECATION`, `MIGRATION_DISCOVERY` (WP01) |
| `Testing\SourceConformanceTestCase` | abstract class | Conformance harness third-party source plugins extend (FR-052, WP10; autoload-dev) |
| `Testing\DestinationConformanceTestCase` | abstract class | Conformance harness third-party destination plugins extend (FR-050/FR-051, WP10; autoload-dev) |

### listing

Charter §5.6 — listing-pipeline-v1 (M-007). Namespace `Waaseyaa\Listing\`.

| Element | Type | Purpose |
|---------|------|---------|
| `ListingDefinition` | final readonly class | Immutable listing manifest: id, entity type, filters, sorts, page size, access ops |
| `FilterDefinition` | final readonly class | Field + operator + value; optional `exposedParam` for URL-driven filters |
| `SortDefinition` | final readonly class | Field + direction; resolver appends an implicit id tie-break sort |
| `Pagination` | final readonly class | Page metadata: page, page size, total rows, total pages, hasPrev, hasNext |
| `ListingResult` | final readonly class | Resolution result: rows + pagination + cache tags + cache contexts |
| `ExposedFilterValues` | final readonly class | Typed view over parsed `$_GET` slice passed to `ListingResolver::resolve()` |
| `Operator` | backed enum | Filter vocabulary: EQ, NEQ, LT, LTE, GT, GTE, IN, NOT_IN, IS_NULL, IS_NOT_NULL, BETWEEN, STARTS_WITH, CONTAINS |
| `SortDirection` | enum | ASC, DESC |
| `Filter` | factory class | Sugar factories: `eq()`, `gte()`, `in()`, `isNull()`, `langcode()`, `exposed()`, etc. |
| `Sort` | factory class | Sugar factories: `asc()`, `desc()` |
| `HasListingsInterface` | capability interface | ServiceProviders implement to declare listings; mirrors `HasMigrationsInterface` |
| `ListingResolver` | final class | Single public method `resolve(ListingDefinition, ?ExposedFilterValues): ListingResult` |
| `ListingDefinitionRegistry` | final class | `get(string $id): ListingDefinition` — throws `UnknownListingException` on miss |
| `ExposedFilterParser` | final class | Parses query params into `ExposedFilterValues`; never throws on user input |
| `Exception\UnsupportedListingException` | final class | Definition-time validation failure (carries listing id, field name, reason) |
| `Exception\UnknownListingException` | final class | Registry miss (carries listing id) |

---

## Layer 4: API

### api

| Element | Type | Purpose |
|---------|------|---------|
| `MutableTranslatableInterface` | interface | Extends `TranslatableInterface` with `addTranslation()` for explicit translation creation |
| `CodifiedContextSessionStoreInterface` | interface | Read-only port for codified-context session rows consumed by `CodifiedContextController`; Telescope implements via adapter |

### routing

| Element | Type | Purpose |
|---------|------|---------|
| `LanguageNegotiatorInterface` | interface | Detects the active language from a request via path prefix, domain, or header |

---

## Layer 5: AI

### ai-agent

| Element | Type | Purpose |
|---------|------|---------|
| `ToolRegistryInterface` | interface | Provides the set of tools available to an AI agent |
| `ProviderInterface` | interface | AI model provider: sends messages and returns a structured response |
| `StreamingProviderInterface` | interface | Provider variant that streams partial response chunks as they arrive |

### ai-pipeline

| Element | Type | Purpose |
|---------|------|---------|
| `PipelineStepInterface` | interface | One step in an AI pipeline: receives input from the previous step and returns output |

### ai-vector

| Element | Type | Purpose |
|---------|------|---------|
| `VectorStoreInterface` | interface | Stores and queries entity embeddings in a vector backend (pgvector, Qdrant, etc.) |
| `EmbeddingProviderInterface` | interface | Generates a vector embedding for a single text string |
| `EmbeddingInterface` | interface | Extends `EmbeddingProviderInterface` with batch embedding generation |
| `EmbeddingStorageInterface` | interface | Stores and similarity-searches raw float vectors by entity type and ID |

---

## Layer 6: Interfaces

### cli

| Element | Type | Purpose |
|---------|------|---------|
| `SourceConnectorInterface` | interface | Connects an ingestion source: transforms raw records and returns rows with diagnostics |

### admin-surface

| Element | Type | Purpose |
|---------|------|---------|
| `SurfaceActionHandlerInterface` | interface | Handles a custom admin surface action for a given entity type and payload |
| `AbstractAdminSurfaceHost` | abstract class | Base class applications extend to integrate with the admin SPA (session, catalog, entity ops) |

### mcp

| Element | Type | Purpose |
|---------|------|---------|
| `ToolExecutorInterface` | interface | Executes an MCP tool call by name with arguments and returns structured content |
| `ToolRegistryInterface` | interface | Provides the full list of MCP tool definitions for the protocol manifest |
| `McpAuthInterface` | interface | Authenticates MCP requests and resolves the acting account |

### ssr

| Element | Type | Purpose |
|---------|------|---------|
| `ThemeInterface` | interface | Provides a theme's identifier and its Twig template directory paths |
