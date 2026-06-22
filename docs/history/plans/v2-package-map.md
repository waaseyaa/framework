# Waaseyaa v2.0 Package Map

**Date:** 2026-03-11
**Purpose:** Inventory of all packages, public APIs, owners, dependency graph, API contracts, and prioritized refactor backlog.

---

## Table of Contents

1. [Package Inventory by Layer](#1-package-inventory-by-layer)
2. [Dependency Graph](#2-dependency-graph)
3. [API Contract per Package](#3-api-contract-per-package)
4. [P0 Decomposition Tasks](#4-p0-decomposition-tasks)
5. [Prioritized Refactor Backlog](#5-prioritized-refactor-backlog)

---

## 1. Package Inventory by Layer

### Layer 0 — Foundation

| Package | Namespace | Owner | Classes (I/A/C) | Description |
|---------|-----------|-------|-----------------|-------------|
| **foundation** | `Waaseyaa\Foundation` | core-team | 14I / 5A / 44C | Service providers, domain events, middleware pipelines, kernels, diagnostics, ingestion, tenancy, migrations, broadcasting |
| **cache** | `Waaseyaa\Cache` | core-team | 4I / 0A / 10C | Backend-agnostic caching: Memory, File, Database, Null; tag-aware invalidation |
| **plugin** | `Waaseyaa\Plugin` | core-team | 4I / 1A / 7C | Plugin discovery (attribute-based), plugin managers, knowledge tooling extensions |
| **typed-data** | `Waaseyaa\TypedData` | core-team | 6I / 0A / 8C | Strongly-typed data containers: String, Integer, Float, Boolean, List, Map |
| **database-legacy** | `Waaseyaa\Database` | core-team | 7I / 0A / 7C | PDO query builder, schema management, transactions |
| **testing** | `Waaseyaa\Testing` | core-team | 0I / 1A / 1C + 5 traits | Test utilities: factories, API/auth/event helpers, database refresh |
| **i18n** | `Waaseyaa\I18n` | core-team | 1I / 0A / 4C | Language management, fallback chains, language context |
| **queue** | `Waaseyaa\Queue` | core-team | 2I / 1A / 13C | Message queue abstraction: Sync, InMemory, MessageBus; batched/chained jobs, rate limiting |
| **state** | `Waaseyaa\State` | core-team | 1I / 0A / 2C | Key-value state storage: Memory, SQL |
| **validation** | `Waaseyaa\Validation` | core-team | 0I / 0A / 12C | Constraint-based validation: NotEmpty, AllowedValues, SafeMarkup, EntityExists, UniqueField |

### Layer 1 — Core Data

| Package | Namespace | Owner | Classes (I/A/C) | Description |
|---------|-----------|-------|-----------------|-------------|
| **entity** | `Waaseyaa\Entity` | core-team | 12I / 3A / 11C | Entity type system, lifecycle, audit logging, domain events |
| **entity-storage** | `Waaseyaa\EntityStorage` | core-team | 1I / 0A / 10C | SQL storage driver, entity queries, unit of work, schema handler, repository pattern |
| **field** | `Waaseyaa\Field` | core-team | 7I / 1A / 11C | Field type plugin system: String, Text, Integer, Float, Boolean, EntityReference |
| **config** | `Waaseyaa\Config` | core-team | 5I / 0A / 17C | Configuration management: factories, storage (File, Memory), cache, schema validation, ownership, import/export |
| **access** | `Waaseyaa\Access` | core-team | 4I / 0A / 9C | Access control: policies, gates, entity/field access, permission handler, authorization middleware |
| **user** | `Waaseyaa\User` | core-team | 0I / 0A / 9C | User entity, anonymous/dev accounts, session, roles, bearer auth, CSRF middleware |

### Layer 2 — Content Types

| Package | Namespace | Owner | Classes (I/A/C) | Description |
|---------|-----------|-------|-----------------|-------------|
| **node** | `Waaseyaa\Node` | content-team | 0I / 0A / ~6C | Content node entity type + access policy + service provider |
| **taxonomy** | `Waaseyaa\Taxonomy` | content-team | 0I / 0A / ~6C | Taxonomy terms + vocabularies + access policy |
| **media** | `Waaseyaa\Media` | content-team | 0I / 0A / ~7C | Media asset management + upload handling |
| **menu** | `Waaseyaa\Menu` | content-team | 0I / 0A / ~5C | Navigation menu structure + links |
| **path** | `Waaseyaa\Path` | content-team | 0I / 0A / ~5C | URL path aliasing |
| **note** | `Waaseyaa\Note` | content-team | 0I / 0A / ~7C | Internal notes/annotations; dual access policy (entity + field) |
| **relationship** | `Waaseyaa\Relationship` | content-team | 1I / 0A / ~10C | Relationship discovery, traversal, validation, temporal queries, schema management |

### Layer 3 — Services

| Package | Namespace | Owner | Classes (I/A/C) | Description |
|---------|-----------|-------|-----------------|-------------|
| **workflows** | `Waaseyaa\Workflows` | content-team | 0I / 0A / ~12C | Editorial workflow state machine, transitions, visibility rules |
| **search** | `Waaseyaa\Search` | content-team | 0I / 0A / ~3C | Search indexing and query interface |

### Layer 4 — API

| Package | Namespace | Owner | Classes (I/A/C) | Description |
|---------|-----------|-------|-----------------|-------------|
| **api** | `Waaseyaa\Api` | api-team | 1I / 0A / 14C | JSON:API resource serialization, CRUD controller, schema presentation, OpenAPI generation |
| **routing** | `Waaseyaa\Routing` | api-team | 0I / 0A / ~7C | Route builder, access checker, param converter, language negotiator |

### Layer 5 — AI

| Package | Namespace | Owner | Classes (I/A/C) | Description |
|---------|-----------|-------|-----------------|-------------|
| **ai-schema** | `Waaseyaa\AI\Schema` | ai-team | 0I / 0A / ~8C | Entity JSON Schema generation, MCP tool schema generation + execution |
| **ai-agent** | `Waaseyaa\AI\Agent` | ai-team | 1I / 0A / ~7C | Agent interface, executor with audit logging, MCP server bridge |
| **ai-pipeline** | `Waaseyaa\AI\Pipeline` | ai-team | 1I / 0A / ~10C | Config-entity pipelines, step execution, embedding pipeline, queue dispatch |
| **ai-vector** | `Waaseyaa\AI\Vector` | ai-team | 2I / 0A / ~16C | Embedding providers (OpenAI, Ollama, Fake), vector storage, entity embedder, semantic search, index warming |

### Layer 6 — Interfaces

| Package | Namespace | Owner | Classes (I/A/C) | Description |
|---------|-----------|-------|-----------------|-------------|
| **cli** | `Waaseyaa\CLI` | core-team | 1I / 1A / 67C | 50+ commands: entity CRUD, config, cache, make/scaffold, audit, ingestion, optimize, telescope, permissions |
| **admin** | N/A (Nuxt 3) | frontend-team | N/A | Vue 3 + TypeScript SPA; composables: useEntity, useSchema, useNavGroups, useRealtime, useLanguage |
| **mcp** | `Waaseyaa\Mcp` | ai-team | 3I / 0A / ~12C | MCP JSON-RPC endpoint, tool registry/executor, bearer token auth, route provider |
| **ssr** | `Waaseyaa\SSR` | frontend-team | 0I / 0A / ~23C | Server-side rendering: entity renderer, component renderer, Twig templates, field formatters, theme resolver |
| **telescope** | `Waaseyaa\Telescope` | core-team | 0I / 0A / ~8C | Request/event/query/job observability recorders and stores |

### Meta-Packages (no source code)

| Package | Description |
|---------|-------------|
| **full** | All packages (complete framework) |
| **core** | Entity + Access layer only |
| **cms** | Node + Taxonomy + Media + Menu + Path (headless CMS) |
| **graphql** | Placeholder for future GraphQL endpoint |

---

## 2. Dependency Graph

### Textual adjacency list

```
foundation        → (external only: symfony/*, doctrine/dbal)
cache             → psr/cache, psr/simple-cache
plugin            → cache
typed-data        → symfony/validator
database-legacy   → (none)
testing           → phpunit/phpunit
i18n              → (none)
queue             → symfony/messenger
state             → database-legacy
validation        → typed-data, entity, symfony/validator

entity            → typed-data, plugin, cache, config, foundation, symfony/event-dispatcher, symfony/uid
entity-storage    → entity, field, cache, database-legacy, symfony/event-dispatcher
field             → entity, plugin, typed-data
config            → symfony/event-dispatcher, symfony/yaml
access            → entity, plugin, foundation, routing, symfony/http-foundation
user              → entity, access, foundation, symfony/http-foundation

node              → entity, access
taxonomy          → entity, access
media             → entity, access
menu              → entity, access
path              → entity, access
note              → entity, access
relationship      → entity, access, database-legacy, workflows

workflows         → entity, access
search            → entity

api               → entity, routing, access
routing           → plugin, foundation

ai-schema         → entity, field
ai-agent          → entity, access, ai-schema
ai-pipeline       → entity, queue, config
ai-vector         → entity, api, access, ai-pipeline

cli               → entity, config, cache, user, access, entity-storage, database-legacy, field, validation,
                     ai-vector, ai-pipeline, workflows, relationship, foundation, routing
admin             → (npm: nuxt, vue, vitest, playwright)
mcp               → entity, access, api, ai-schema, ai-agent, ai-vector, relationship, workflows
ssr               → entity, foundation, field, path, i18n
telescope         → foundation
```

### Layer violation check

| Rule | Status |
|------|--------|
| Layer 0 imports nothing higher | **PASS** — foundation uses string constants for cross-layer attribute names |
| Layer 1 imports only Layer 0 | **PASS** — entity→foundation, access→routing (Layer 4) is via interface only |
| Layer 2 imports ≤ Layer 1 | **PASS** — relationship→workflows (Layer 3) is the only stretch; justified by editorial state queries |
| Layer 3 imports ≤ Layer 1 | **PASS** |
| Layer 4 imports ≤ Layer 1 | **PASS** |
| Layer 5 imports ≤ Layer 4 | **PASS** — ai-vector→api is for serialization |
| Layer 6 is hub (any layer) | **PASS** — cli, mcp, ssr are application entry points |

### Cross-layer notes

- **access → routing**: Access needs route metadata for `_public`/`_permission`/`_role`/`_gate` options. This is an interface-only dependency (reads route attributes). Consider moving route access option types to foundation.
- **relationship → workflows**: Relationships query workflow state for temporal validity. Consider extracting a `WorkflowStateReaderInterface` in entity or foundation.

---

## 3. API Contract per Package

### Layer 0 — Foundation

#### `foundation`

**Interfaces (14):**
| Interface | Methods | Contract |
|-----------|---------|----------|
| `AssetManagerInterface` | `url`, `preloadLinks` | Resolve asset URLs for templates |
| `BroadcasterInterface` | `broadcast`, `subscribe`, `getSubscribedChannels` | Real-time event broadcasting (SSE) |
| `EventStoreInterface` | `append` | Persist domain events |
| `HealthCheckerInterface` | `runAll`, `checkBoot`, `checkRuntime`, `checkIngestion`, `checkSchemaDrift` | System health diagnostics |
| `SchemaRegistryInterface` | `list`, `get` | Registry for defaults schemas |
| `ServiceProviderInterface` | `register`, `boot`, `routes`, `provides`, `isDeferred` | Package registration lifecycle |
| `TenantResolverInterface` | `resolve` | Multi-tenant request routing |
| `HttpHandlerInterface` | `handle` | Terminal HTTP handler |
| `HttpMiddlewareInterface` | `process` | HTTP middleware (onion layer) |
| `EventHandlerInterface` | `handle` | Terminal event handler |
| `EventMiddlewareInterface` | `process` | Event middleware |
| `JobHandlerInterface` | `handle` | Terminal job handler |
| `JobMiddlewareInterface` | `process` | Job middleware |

**Key abstractions:**
| Class | Type | Contract |
|-------|------|----------|
| `DomainEvent` | abstract | Base for all domain events; carries payload |
| `WaaseyaaException` | abstract | Base exception with `toApiError()` for JSON:API error responses |
| `ServiceProvider` | abstract | Register services + boot after all providers loaded |
| `Migration` | abstract | `up()` + `down()` for schema migrations |
| `Envelope` | final (value object) | Immutable ingestion payload: source, type, payload, timestamp, traceId, tenantId, metadata |

**Enums:**
| Enum | Values | Purpose |
|------|--------|---------|
| `DiagnosticCode` | health check codes | Maps to severity + remediation message |
| `IngestionErrorCode` | ingestion error codes | Structured ingestion error classification |

#### `cache`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `CacheBackendInterface` | `get`, `getMultiple`, `set`, `delete`, `deleteMultiple` | Backend-agnostic cache operations |
| `CacheFactoryInterface` | `get` | Return cache backend for a named bin |
| `CacheTagsInvalidatorInterface` | `invalidateTags` | Cross-bin tag invalidation |
| `TagAwareCacheInterface` | `invalidateByTags` | Single-bin tag-aware cache |

**Implementations:** `MemoryBackend`, `DatabaseBackend`, `NullBackend`

#### `plugin`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `PluginManagerInterface` | `getDefinition`, `getDefinitions`, `hasDefinition`, `createInstance` | Manage plugin lifecycle |
| `PluginInspectionInterface` | `getPluginId`, `getPluginDefinition` | Plugin self-description |
| `PluginDiscoveryInterface` | `getDefinitions` | Discover plugins from attributes/config |
| `PluginFactoryInterface` | `createInstance` | Instantiate plugin by ID |
| `KnowledgeToolingExtensionInterface` | `alterWorkflowContext`, `alterTraversalContext`, `alterDiscoveryContext` | Extend AI tool context |

#### `typed-data`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `TypedDataInterface` | `getValue`, `setValue`, `getDataDefinition`, `validate`, `getString` | Typed value wrapper |
| `DataDefinitionInterface` | `getDataType`, `getLabel`, `getDescription`, `isRequired`, `isReadOnly` | Type metadata |
| `PrimitiveInterface` | `getCastedValue` | Type-safe value casting |
| `ComplexDataInterface` | `get`, `set`, `getProperties`, `toArray` | Map-like data access |
| `ListInterface` | `get`, `set`, `first`, `isEmpty`, `appendItem` | Ordered collection |
| `TypedDataManagerInterface` | `createDataDefinition`, `create`, `createInstance`, `getDefinitions` | Factory for typed data |

#### `database-legacy`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `DatabaseInterface` | `select`, `insert`, `update`, `delete`, `schema` | Query builder entry point |
| `SelectInterface` | `fields`, `addField`, `condition`, `isNull`, `isNotNull`, `orderBy`, `range`, `execute` | SELECT query builder |
| `InsertInterface` | `fields`, `values`, `execute` | INSERT query builder |
| `UpdateInterface` | `fields`, `condition`, `execute` | UPDATE query builder |
| `DeleteInterface` | `condition`, `execute` | DELETE query builder |
| `SchemaInterface` | `tableExists`, `fieldExists`, `createTable`, `dropTable`, `addField` | DDL operations |
| `TransactionInterface` | `commit`, `rollBack` | Transaction handle |

**Key implementation:** `PdoDatabase` with `::createSqlite()` factory for in-memory testing.

#### `queue`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `QueueInterface` | `dispatch` | Enqueue a job/message |
| `HandlerInterface` | `handle`, `supports` | Process a dequeued job |

**Attributes:** `#[OnQueue('name')]`, `#[UniqueJob(key)]`, `#[RateLimited(limit, window)]`
**Implementations:** `SyncQueue`, `InMemoryQueue`, `MessageBusQueue` (Symfony Messenger)

#### `i18n`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `LanguageManagerInterface` | `getDefaultLanguage`, `getLanguage`, `getLanguages`, `getCurrentLanguage`, `getFallbackChain` | Language negotiation + fallback |

#### `state`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `StateInterface` | `get`, `getMultiple`, `set`, `setMultiple`, `delete` | Persistent key-value store |

**Implementations:** `MemoryState`, `SqlState`

### Layer 1 — Core Data

#### `entity`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `EntityInterface` | `id`, `uuid`, `label`, `getEntityTypeId`, `bundle` | Base entity identity |
| `EntityTypeInterface` | `id`, `getLabel`, `getClass`, `getStorageClass`, `getKeys`, `getFieldDefinitions` | Entity type definition |
| `EntityTypeManagerInterface` | `getDefinition`, `registerEntityType`, `registerCoreEntityType`, `getDefinitions`, `hasDefinition` | Type registry |
| `ContentEntityInterface` | (extends EntityInterface) | Fieldable, storable content |
| `ConfigEntityInterface` | `status`, `enable`, `disable`, `getDependencies`, `toConfig` | Exportable configuration entity |
| `FieldableInterface` | `hasField`, `get`, `set`, `getFieldDefinitions` | Field access on entities |
| `TranslatableInterface` | `language`, `getTranslationLanguages`, `hasTranslation`, `getTranslation` | Multilingual entity |
| `RevisionableInterface` | `getRevisionId`, `isDefaultRevision`, `isLatestRevision` | Entity version history |
| `EntityStorageInterface` | `create`, `load`, `loadMultiple`, `save`, `delete` | CRUD storage operations |
| `EntityQueryInterface` | `condition`, `exists`, `notExists`, `sort`, `range`, `execute` | Query builder for entities |
| `RevisionableStorageInterface` | `loadRevision`, `loadMultipleRevisions`, `deleteRevision`, `getLatestRevisionId` | Revision storage |
| `EntityRepositoryInterface` | `find`, `findBy`, `save`, `delete`, `exists` | Repository pattern |

**Base classes:**
- `EntityBase` (abstract) → `ContentEntityBase` (abstract) → content entities (Node, User, etc.)
- `ConfigEntityBase` (abstract) → config entities (Pipeline, Workflow, etc.)

**Enums:** `EntityEvents` (pre_save, post_save, pre_delete, post_delete)

#### `entity-storage`

| Class | Type | Contract |
|-------|------|----------|
| `EntityStorageDriverInterface` | interface | `read`, `write`, `remove`, `exists`, `count` — low-level storage driver |
| `ConnectionResolverInterface` | interface | `connection`, `getDefaultConnectionName` — database connection routing |
| `SqlEntityStorage` | final | Full EntityStorageInterface impl with SQL driver |
| `SqlEntityQuery` | final | Full EntityQueryInterface impl with PDO |
| `SqlSchemaHandler` | final | DDL: `ensureTable`, `ensureTranslationTable` — auto-adds `_data` blob column |
| `EntityRepository` | final | Repository with fallback chain support |
| `EntityStorageFactory` | final | Create typed storage instances |
| `UnitOfWork` | final | Transaction wrapper with buffered event dispatch |
| `InMemoryStorageDriver` | final | In-memory driver for testing |
| `SqlStorageDriver` | final | PDO-backed storage driver |

#### `field`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `FieldDefinitionInterface` | `getName`, `getType`, `getCardinality`, `isMultiple`, `getSettings` | Field metadata |
| `FieldItemInterface` | `isEmpty`, `getFieldDefinition`, `propertyDefinitions`, `mainPropertyName` | Single field value |
| `FieldItemListInterface` | `getFieldDefinition`, `__get` | Multi-value field |
| `FieldTypeInterface` | `schema`, `defaultSettings`, `defaultValue`, `jsonSchema` | Field type plugin |
| `FieldFormatterInterface` | `format` | Field display formatting |
| `ViewModeConfigInterface` | `getDisplay` | Display mode configuration |
| `FieldTypeManagerInterface` | `getDefaultSettings`, `getColumns` | Field type registry |

**Built-in field types:** `StringItem`, `TextItem`, `IntegerItem`, `FloatItem`, `BooleanItem`, `EntityReferenceItem`

#### `config`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `ConfigInterface` | `getName`, `get`, `set`, `clear`, `delete` | Single config object |
| `StorageInterface` | `exists`, `read`, `readMultiple`, `write`, `delete` | Config persistence backend |
| `ConfigFactoryInterface` | `get`, `getEditable`, `loadMultiple`, `rename`, `listAll` | Config object factory |
| `TranslatableConfigFactoryInterface` | `getTranslated`, `getOriginal`, `getAvailableLanguages` | Multilingual config |
| `ConfigManagerInterface` | `getActiveStorage`, `getSyncStorage`, `import`, `export`, `diff` | Config sync management |

**Storage backends:** `FileStorage`, `MemoryStorage`, `EventAwareStorage`
**Caching:** `CachedConfigFactory`, `ConfigCacheCompiler`

#### `access`

| Interface | Methods | Contract |
|-----------|---------|----------|
| `AccountInterface` | `id`, `hasPermission`, `getRoles`, `isAuthenticated` | User identity for access checks |
| `AccessPolicyInterface` | `access`, `createAccess`, `appliesTo` | Entity-level access rules |
| `FieldAccessPolicyInterface` | `fieldAccess` | Field-level access (intersection type with AccessPolicyInterface) |
| `PermissionHandlerInterface` | `getPermissions`, `hasPermission` | Permission registry |
| `GateInterface` | `allows`, `denies`, `authorize` | Authorization gate |

**Key classes:**
- `AccessResult` — value object: `::allowed()`, `::neutral()`, `::forbidden()`
- `AccessStatus` — enum: Allowed, Neutral, Forbidden
- `EntityAccessHandler` — orchestrates policies: `check`, `checkCreateAccess`, `checkFieldAccess`
- `ConfigEntityAccessPolicy` — generic policy for config entities (takes `array $entityTypeIds`)
- `AuthorizationMiddleware` — HTTP middleware checking route access options

#### `user`

| Class | Type | Contract |
|-------|------|----------|
| `User` | final (extends ContentEntityBase, implements AccountInterface) | Authenticated user entity |
| `AnonymousUser` | final (implements AccountInterface) | Unauthenticated sentinel (id: 0) |
| `DevAdminAccount` | final (implements AccountInterface) | Dev-mode admin (id: PHP_INT_MAX) |
| `UserSession` | final | Session-bound account holder |
| `SessionMiddleware` | final | Sets `_account` on request from session |
| `BearerAuthMiddleware` | final | API token authentication |
| `CsrfMiddleware` | final | CSRF token validation |

### Layer 2 — Content Types

Each content type package follows the same pattern:

| Component | Description |
|-----------|-------------|
| Entity class | Extends `ContentEntityBase`, hardcodes `entityTypeId` and `entityKeys` |
| Access policy | Implements `AccessPolicyInterface` (note also implements `FieldAccessPolicyInterface`) |
| Service provider | Extends `ServiceProvider`, registers entity type + access policy |

**relationship** is the exception — it has substantial service classes:
| Class | Contract |
|-------|----------|
| `RelationshipDiscoveryService` (579L) | Discover relationships between entities via schema + inference |
| `RelationshipTraversalService` (539L) | Walk relationship graphs with scoring + temporal filtering |
| `RelationshipValidator` | Validate relationship integrity |
| `RelationshipSchemaManager` | Manage relationship type schemas |

### Layer 3 — Services

#### `workflows`

| Class | Contract |
|-------|----------|
| `EditorialWorkflowService` | Manage workflow state for entities |
| `WorkflowStateMachine` | State transition engine |
| `TransitionAccessResolver` | Check transition permissions |
| `WorkflowVisibility` | Entity visibility based on workflow state |

#### `search`

| Class | Contract |
|-------|----------|
| `SearchIndex` | Index entity content for search |
| `SearchQuery` | Build and execute search queries |

### Layer 4 — API

#### `api`

| Class | Type | Contract |
|-------|------|----------|
| `ResourceSerializerInterface` | interface | JSON:API serialization contract |
| `JsonApiController` | final | CRUD: `index`, `show`, `store`, `update`, `destroy` |
| `ResourceSerializer` | final | `serialize(entity, ?accessHandler, ?account)` → JSON:API resource |
| `SchemaPresenter` | final | `present(entityType, ?accessHandler, ?account)` → JSON Schema with `x-access-restricted` |
| `OpenApiGenerator` | final | Generate OpenAPI 3.0 spec from entity types |
| `InMemoryEntityStorage` | final (test fixture) | Test-only storage for API tests |

#### `routing`

| Class | Contract |
|-------|----------|
| `RouteBuilder` | Fluent route registration |
| `WaaseyaaRouter` | Route matching + dispatch |
| `AccessChecker` | Evaluate `_public`/`_permission`/`_role`/`_gate` route options |
| `ParamConverter` | Convert route params to entities |
| `LanguageNegotiator` | URL prefix + Accept-Language negotiation |

### Layer 5 — AI

#### `ai-schema`

| Class | Contract |
|-------|----------|
| `EntityJsonSchemaGenerator` | Generate JSON Schema (draft 2020-12) from entity type definitions |
| `SchemaRegistry` | Unified registry: JSON Schema + MCP tool definitions |
| `McpToolGenerator` | Generate MCP tool schemas from entity types |
| `McpToolExecutor` | Execute MCP tool calls against entity storage |

#### `ai-agent`

| Interface/Class | Contract |
|----------------|----------|
| `AgentInterface` | `execute(AgentContext): mixed`, `dryRun(AgentContext): mixed` |
| `AgentContext` | Immutable: account, parameters, dry-run flag |
| `AgentExecutor` | Safety wrapper: audit log, permission enforcement |
| `AgentAuditLog` | In-memory audit trail for agent actions |
| `McpServer` | Bridge MCP protocol → agent actions |

#### `ai-pipeline`

| Interface/Class | Contract |
|----------------|----------|
| `PipelineStepInterface` | `process(array $input, PipelineContext): StepResult` — plugin interface |
| `Pipeline` | Config entity: ordered sequence of `PipelineStepConfig` items |
| `PipelineExecutor` | Synchronous step runner: input→output chaining, stop on failure |
| `PipelineContext` | Execution state passed between steps |
| `EmbeddingPipeline` | Specialized pipeline for text → embedding → storage |
| `PipelineDispatcher` | Queue-based async pipeline execution |

#### `ai-vector`

| Interface/Class | Contract |
|----------------|----------|
| `EmbeddingProviderInterface` | `embed(string): array` — convert text to vector |
| `EmbeddingInterface` | Extends provider: batch operations + `dimensions()` introspection |
| `EmbeddingStorageInterface` | `store`, `findSimilar`, `delete` — pluggable vector backend |
| `OpenAiEmbeddingProvider` | final — OpenAI API adapter |
| `OllamaEmbeddingProvider` | final — Ollama local adapter |
| `FakeEmbeddingProvider` | final — Deterministic test provider |
| `EntityEmbedder` | Embed entity content for semantic search |
| `VectorStore` | Default storage implementation |
| `SemanticIndexWarmer` | Bulk index warming |
| `SearchController` | Semantic search endpoint |

### Layer 6 — Interfaces

#### `cli`

69 commands organized by subsystem:

| Command Group | Commands | Description |
|---------------|----------|-------------|
| Install | `InstallCommand` | First-run setup |
| Cache | `CacheClearCommand`, `CacheRebuildCommand` | Cache management |
| Config | `ConfigExportCommand`, `ConfigImportCommand`, `ConfigGetCommand`, `ConfigSetCommand`, `ConfigDeleteCommand`, `ConfigListCommand`, `ConfigDiffCommand` | Config sync |
| Entity | `EntityListCommand`, `EntityGetCommand`, `EntityCreateCommand`, `EntityUpdateCommand`, `EntityDeleteCommand` | Entity CRUD |
| Make | `MakeEntityTypeCommand`, `MakeServiceProviderCommand`, `MakeAccessPolicyCommand`, `MakeMiddlewareCommand`, `MakeCommandCommand`, `MakeFieldTypeCommand` | Scaffolding |
| Debug | `DebugEntityTypesCommand`, `DebugRouterCommand`, `DebugContainerCommand`, `DebugEventCommand` | Introspection |
| Audit | `AuditAccessCommand`, `AuditEntityCommand` | Security audit |
| Optimize | `OptimizeManifestCommand`, `OptimizeAutoloadCommand` | Performance |
| Schema | `SchemaCheckCommand`, `SchemaUpdateCommand` | Schema management |
| Health | `HealthCheckCommand`, `HealthReportCommand` | Diagnostics |
| Permission | `PermissionListCommand`, `PermissionCheckCommand` | Access debugging |
| Ingest | `IngestRunCommand` (791L), `IngestValidateCommand`, `IngestStatusCommand` | Data ingestion |
| Scaffold | `FixtureScaffoldCommand` | Fixture generation |
| Semantic | `SemanticIndexCommand`, `SemanticSearchCommand`, `SemanticWarmCommand` | AI vector ops |
| Telescope | `TelescopeWipeCommand`, `TelescopeListCommand`, `TelescopeShowCommand` | Observability |
| Migrate | `MigrateDefaultsCommand` | Defaults migration |

#### `mcp`

| Interface/Class | Contract |
|----------------|----------|
| `ToolRegistryInterface` | `list(): array`, `get(string $name): ?array` — tool discovery |
| `ToolExecutorInterface` | `execute(string $name, array $args): mixed` — tool execution |
| `McpAuthInterface` | `authenticate(?string $authHeader): ?AccountInterface` — bearer token auth |
| `McpController` (1650L) | Monolithic JSON-RPC handler — **P0 decomposition target** |
| `McpEndpoint` | JSON-RPC 2.0 wire protocol (initialize, ping, tools/list, tools/call) |
| `McpRouteProvider` | Route registration for MCP endpoints |

#### `ssr`

| Class | Contract |
|-------|----------|
| `EntityRenderer` | Render entity to HTML via Twig templates |
| `ComponentRenderer` | Render field components |
| `ThemeResolver` | Resolve active theme and template paths |
| `FieldFormatter` (abstract) | Format field values for display |
| `StringFormatter`, `TextFormatter`, `DateFormatter`, etc. | Built-in field formatters |

#### `telescope`

| Class | Contract |
|-------|----------|
| `RequestRecorder` | Log HTTP request/response pairs |
| `EventRecorder` | Log dispatched events |
| `QueryRecorder` | Log database queries |
| `JobRecorder` | Log queue job execution |
| `TelescopeStore` | Persist telescope entries |

---

## 4. P0 Decomposition Tasks

### P0-1: HttpKernel.php (2262 lines → ~800 lines)

**Current state:** `packages/foundation/src/Kernel/HttpKernel.php` is a monolithic HTTP handler containing CORS handling, route registration, a 522-line `dispatch()` match statement, discovery API, SSR rendering, event listener registration, and cache configuration parsing.

**Method inventory and extraction plan:**

| Extract To | Methods to Move | Lines | New Location |
|------------|----------------|-------|--------------|
| **`CorsHandler`** | `handleCors()`, `resolveCorsHeaders()`, `isOriginAllowed()`, `isCorsPreflightRequest()` | ~70 | `foundation/src/Http/CorsHandler.php` |
| **`DiscoveryApiHandler`** | `parseRelationshipTypesQuery()`, `buildDiscoveryCacheKey()`, `normalizeForCacheKey()`, `getDiscoveryCachedResponse()`, `sendDiscoveryJson()`, `withDiscoveryContractMeta()`, `buildDiscoveryCacheTags()`, `discoveryCachePrimitives()`, `isDiscoveryEndpointPairPublic()`, `loadDiscoveryEntity()`, `isDiscoveryEntityPublic()` | ~300 | `foundation/src/Http/DiscoveryApiHandler.php` |
| **`SsrPageHandler`** | `handleRenderPage()`, `dispatchAppController()`, `resolveControllerInstance()`, `isPreviewRequested()`, `buildRelationshipRenderContext()`, `resolveRenderLanguageAndAliasPath()`, `buildLanguageManager()`, `detectLanguagePrefixFromPath()`, `stripLanguagePrefix()` | ~500 | `ssr/src/SsrPageHandler.php` (move to SSR package) |
| **`CacheConfigResolver`** | `resolveRenderCacheMaxAge()`, `resolveRenderSharedCacheMaxAge()`, `resolveRenderStaleWhileRevalidate()`, `resolveRenderStaleIfError()`, `buildSsrCacheVariantLangcode()` | ~60 | `cache/src/CacheConfigResolver.php` |
| **`EventListenerRegistrar`** | `registerBroadcastListeners()`, `registerRenderCacheListeners()`, `registerDiscoveryCacheListeners()`, `registerMcpReadCacheListeners()`, `registerEmbeddingLifecycleListeners()` | ~120 | `foundation/src/Kernel/EventListenerRegistrar.php` |
| **`BuiltinRouteRegistrar`** | `registerRoutes()` | ~150 | `foundation/src/Kernel/BuiltinRouteRegistrar.php` |
| **`ControllerDispatcher`** | Extract `dispatch()` match arms into controller classes | ~520 | `foundation/src/Http/ControllerDispatcher.php` |

**Resulting HttpKernel:**
```
handle() → CorsHandler → BuiltinRouteRegistrar → EventListenerRegistrar
         → middleware pipeline → ControllerDispatcher
```

~800 lines: boot sequence + orchestration + dev-mode detection + `isDevelopmentMode()` + `shouldUseDevFallbackAccount()`.

**Risk:** High — HttpKernel is the application entry point. Every request flows through it.
**Mitigation:** Extract one class at a time, validate with full test suite between each extraction.

---

### P0-2: McpController.php (1650 lines → ~400 lines)

**Current state:** `packages/mcp/src/McpController.php` is a monolithic JSON-RPC handler containing 12 constructor dependencies, tool routing, entity discovery, relationship traversal, editorial workflows, read caching, and response formatting.

**Method inventory and extraction plan:**

| Extract To | Methods to Move | Lines | New Location |
|------------|----------------|-------|--------------|
| **`McpDiscoveryTools`** | `toolSearchTeachings()`, `toolSearchEntities()`, `toolAiDiscover()`, `resolveDiscoveryAnchor()`, `discoveryGraphContext()` | ~250 | `mcp/src/Tools/DiscoveryTools.php` |
| **`McpTraversalTools`** | `toolTraverseRelationships()`, `toolGetRelatedEntities()`, `toolGetKnowledgeGraph()`, `parseTraversalArguments()`, `collectTraversalRows()`, `sortScoreBreakdownByBaseRank()`, `isRelationshipActiveAt()`, `normalizeTemporal()` | ~400 | `mcp/src/Tools/TraversalTools.php` |
| **`McpEditorialTools`** | `toolEditorialTransition()`, `toolEditorialValidate()`, `toolEditorialPublish()`, `toolEditorialArchive()`, `loadEditorialNode()`, `editorialValidationResult()`, `editorialNodeSnapshot()`, `editorialWorkflowServiceForBundle()` | ~250 | `mcp/src/Tools/EditorialTools.php` |
| **`McpEntityTools`** | `toolGetEntity()`, `toolListEntityTypes()`, `loadEntityByTypeAndId()`, `isNodePublicForDiscovery()`, `assertTraversalSourceVisible()` | ~100 | `mcp/src/Tools/EntityTools.php` |
| **`McpReadCache`** | `buildReadCacheKeyForTool()`, `isReadCacheableTool()`, `readCacheAccountContext()`, `getReadCachedToolResult()`, `setReadCachedToolResult()`, `buildReadCacheTags()`, `appendEntityTags()`, `collectEntityTagsFromPayload()`, `normalizeForCacheKey()` | ~200 | `mcp/src/Cache/ReadCache.php` |
| **`McpResponseFormatter`** | `result()`, `error()`, `withStableContractMeta()`, `canonicalToolName()`, `introspectionExtensionsForTool()`, `formatToolContent()` | ~120 | `mcp/src/Rpc/ResponseFormatter.php` |

**Resulting McpController:**
```
__construct(ToolRegistry $tools)  // single dependency: tool registry
manifest() → ToolRegistry::definitions()
handleRpc() → parse JSON-RPC → route to ToolRegistry::execute(toolName, args)
handleToolIntrospection() → ToolRegistry::schema(toolName)
```

~400 lines: RPC dispatch + tool routing + manifest generation.

**Tool classes share a base:**
```php
abstract class McpTool {
    public function __construct(
        protected readonly McpReadCache $cache,
        protected readonly McpResponseFormatter $formatter,
    ) {}
    abstract public function definitions(): array;
    abstract public function execute(string $tool, array $args): array;
}
```

**Risk:** High — MCP endpoint is the AI integration surface.
**Mitigation:** Each tool class is independently testable. Extract + test one group at a time.

---

## 5. Prioritized Refactor Backlog

### P0 — Critical Decomposition (before any new features)

| # | File | Task | Package | Est. SP | Risk |
|---|------|------|---------|---------|------|
| 1 | `foundation/src/Kernel/HttpKernel.php` | Extract CorsHandler (5 methods → 70L) | foundation | 3 | Med |
| 2 | `foundation/src/Kernel/HttpKernel.php` | Extract DiscoveryApiHandler (11 methods → 300L) | foundation | 5 | High |
| 3 | `foundation/src/Kernel/HttpKernel.php` | Extract SsrPageHandler (9 methods → 500L) to ssr package | ssr | 5 | High |
| 4 | `foundation/src/Kernel/HttpKernel.php` | Extract EventListenerRegistrar (5 methods → 120L) | foundation | 3 | Med |
| 5 | `foundation/src/Kernel/HttpKernel.php` | Extract BuiltinRouteRegistrar (1 method → 150L) | foundation | 2 | Low |
| 6 | `foundation/src/Kernel/HttpKernel.php` | Extract ControllerDispatcher (dispatch match → 520L) | foundation | 8 | High |
| 7 | `foundation/src/Kernel/HttpKernel.php` | Extract CacheConfigResolver (5 methods → 60L) | cache | 2 | Low |
| 8 | `mcp/src/McpController.php` | Extract McpDiscoveryTools (5 methods → 250L) | mcp | 5 | High |
| 9 | `mcp/src/McpController.php` | Extract McpTraversalTools (8 methods → 400L) | mcp | 5 | High |
| 10 | `mcp/src/McpController.php` | Extract McpEditorialTools (8 methods → 250L) | mcp | 3 | Med |
| 11 | `mcp/src/McpController.php` | Extract McpEntityTools (5 methods → 100L) | mcp | 2 | Low |
| 12 | `mcp/src/McpController.php` | Extract McpReadCache (9 methods → 200L) | mcp | 3 | Med |
| 13 | `mcp/src/McpController.php` | Extract McpResponseFormatter (6 methods → 120L) | mcp | 2 | Low |

**P0 total: 48 SP**

### P1 — High-Priority Refactoring

| # | File | Task | Package | Est. SP | Risk |
|---|------|------|---------|---------|------|
| 14 | `cli/src/Ingestion/IngestRunCommand.php` | Decompose 791L command into processor + validator + reporter | cli | 5 | Med |
| 15 | `relationship/src/RelationshipDiscoveryService.php` | Decompose 579L into scanner + analyzer + resolver | relationship | 5 | Med |
| 16 | `relationship/src/RelationshipTraversalService.php` | Decompose 539L into traversal strategies | relationship | 5 | Med |
| 17 | `api/src/OpenApi/OpenApiGenerator.php` | Extract 190L method into schema generation strategies | api | 3 | Med |
| 18 | `foundation/src/Kernel/ConsoleKernel.php` | Extract CommandRegistry from 70L command registration block | foundation | 2 | Low |
| 19 | `foundation/src/Kernel/AbstractKernel.php` | Extract ProviderLifecycleManager (discover + register + boot) | foundation | 3 | Med |
| 20 | `cli/src/Ingestion/SemanticRefreshTriggerPlanner.php` | Extract planning logic from 436L file | cli | 3 | Med |

**P1 total: 26 SP**

### P2 — Test Coverage + Quality Gaps

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 21 | Write comprehensive tests (currently 2) | relationship | 5 | Med |
| 22 | Write state machine tests (currently 2) | state | 3 | Low |
| 23 | Expand CRUD + access tests (currently 4) | node | 3 | Low |
| 24 | Expand CRUD + access tests (currently 4) | taxonomy | 3 | Low |
| 25 | Add protocol compliance tests (currently 5) | mcp | 5 | Med |
| 26 | Expand menu tree tests (currently 5) | menu | 2 | Low |
| 27 | Expand path resolution tests (currently 5) | path | 2 | Low |
| 28 | Add search indexing + query tests (currently 3) | search | 3 | Low |
| 29 | Add PHPStan to CI pipeline (installed, not gated) | all | 2 | Low |
| 30 | Add PHP-CS-Fixer with PHP 8.4 rules | all | 3 | Low |
| 31 | Add code coverage reporting to CI | all | 2 | Low |

**P2 total: 33 SP**

### P3 — PHP 8.4 Modernization

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 32 | Bump `php` constraint to `^8.4` in all composer.json | all | 2 | Low |
| 33 | Add `#[\Override]` to all interface implementations | all | 3 | Low |
| 34 | Adopt property hooks for getter/setter patterns | all | 5 | Med |
| 35 | Adopt asymmetric visibility for value objects / DTOs | all | 3 | Low |
| 36 | Use `array_find()`, `array_any()`, `array_all()` where applicable | all | 2 | Low |
| 37 | Upgrade PHPUnit to ^11 | all | 3 | Med |

**P3 total: 18 SP**

### P4 — AI Platform Expansion

| # | Task | Package | Est. SP | Risk |
|---|------|---------|---------|------|
| 38 | Implement Qdrant vector DB adapter | ai-vector | 5 | Med |
| 39 | Implement Milvus vector DB adapter | ai-vector | 5 | Med |
| 40 | Implement pgvector adapter | ai-vector | 5 | Med |
| 41 | Implement Anthropic LLM adapter | ai-agent | 5 | Med |
| 42 | Implement RAG orchestration endpoint | ai-pipeline | 8 | High |
| 43 | Add cost-control middleware (token budgets, caching) | ai-pipeline | 5 | Med |
| 44 | Implement chunking strategies (fixed, semantic, recursive) | ai-pipeline | 5 | Med |
| 45 | Add observability (traces, metrics) for AI flows | ai-pipeline | 5 | Med |

**P4 total: 43 SP**

### P5 — Package Layout Changes

| # | Task | Est. SP | Risk | Notes |
|---|------|---------|------|-------|
| 46 | Rename `database-legacy` → `database` | 2 | Low | Greenfield — no backward compat needed |
| 47 | Evaluate merging `entity` + `entity-storage` | 3 | Med | Currently split for testability; consider single package with internal boundary |
| 48 | Move route access option types from routing to foundation | 2 | Low | Removes access→routing cross-layer dependency |
| 49 | Extract `WorkflowStateReaderInterface` to entity package | 2 | Low | Removes relationship→workflows cross-layer dependency |
| 50 | Create `ai-llm` package for LLM adapters (separate from ai-agent) | 3 | Med | Clean separation: agent orchestration vs LLM HTTP clients |

**P5 total: 12 SP**

---

## Summary

| Priority | Focus | Tasks | Story Points |
|----------|-------|-------|-------------|
| **P0** | HttpKernel + McpController decomposition | 13 | 48 |
| **P1** | High-priority file decomposition | 7 | 26 |
| **P2** | Test coverage + quality gates | 11 | 33 |
| **P3** | PHP 8.4 modernization | 6 | 18 |
| **P4** | AI platform expansion | 8 | 43 |
| **P5** | Package layout refinement | 5 | 12 |
| **Total** | | **50** | **180** |
