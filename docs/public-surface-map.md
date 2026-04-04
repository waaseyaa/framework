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
| `BroadcasterInterface` | interface | Broadcasts messages to subscribed channels (SSE, WebSockets, Redis Pub/Sub, etc.) |
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
| `Migration` | abstract class | Base class for database migrations with optional rollback and ordering |

### cache

| Element | Type | Purpose |
|---------|------|---------|
| `CacheBackendInterface` | interface | Reads and writes cache items with optional tag and expiry support |
| `CacheFactoryInterface` | interface | Creates or retrieves cache backend instances by bin name |
| `CacheTagsInvalidatorInterface` | interface | Invalidates all cache items associated with a set of tags |
| `TagAwareCacheInterface` | interface | Cache backend that supports tag-based invalidation |

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

### i18n

| Element | Type | Purpose |
|---------|------|---------|
| `LanguageManagerInterface` | interface | Manages the set of available languages and their default |
| `TranslatorInterface` | interface | Translates keys with optional parameter substitution and locale override |

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
| `TranslatableInterface` | interface | Provides per-language translation access and language code introspection |
| `RevisionableEntityTrait` | trait | Default implementation of `RevisionableInterface` using `$values` and `$entityKeys` |
| `EntityRepositoryInterface` | interface | High-level CRUD API handling hydration, event dispatch, and language fallback |
| `EntityEventFactoryInterface` | interface | Creates `EntityEvent` instances with optional before/after snapshots |
| `EntityStorageInterface` | interface | Lower-level storage operations: load, save, delete, query |
| `RevisionableStorageInterface` | interface | Extends entity storage with load, delete, and list operations for specific revisions |
| `EntityQueryInterface` | interface | Fluent query builder for filtering and loading entities by field conditions |

### entity-storage

| Element | Type | Purpose |
|---------|------|---------|
| `EntityStorageDriverInterface` | interface | Low-level persistence SPI: raw row I/O without hydration or event dispatch |
| `ConnectionResolverInterface` | interface | Resolves named database connections; multi-tenancy seam for entity storage |

### access

| Element | Type | Purpose |
|---------|------|---------|
| `AccountInterface` | interface | Represents a user account for access checking: ID, roles, and permission checks |
| `AccessPolicyInterface` | interface | Checks entity-level access for view, update, and delete operations |
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

### field

| Element | Type | Purpose |
|---------|------|---------|
| `FieldItemInterface` | interface | A single typed value within a field list, with property accessors and emptiness check |
| `FieldItemListInterface` | interface | An ordered list of `FieldItemInterface` values for one field on one entity |
| `FieldDefinitionInterface` | interface | Describes a field: type, label, cardinality, settings, and constraints |
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

---

## Layer 4: API

### api

| Element | Type | Purpose |
|---------|------|---------|
| `JsonResponseTrait` | trait | Parses incoming JSON request bodies and builds JSON error responses |
| `MutableTranslatableInterface` | interface | Extends `TranslatableInterface` with `addTranslation()` for explicit translation creation |

### routing

| Element | Type | Purpose |
|---------|------|---------|
| `LanguageNegotiatorInterface` | interface | Detects the active language from a request via path prefix, domain, or header |

---

## Layer 5: AI

### ai-agent

| Element | Type | Purpose |
|---------|------|---------|
| `AgentInterface` | interface | AI agent that executes CMS actions within the permission model with dry-run support |
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
| `SurfaceActionHandler` | interface | Handles a custom admin surface action for a given entity type and payload |
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
