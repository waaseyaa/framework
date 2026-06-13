# Aurora CMS: Architecture v2 Design

**Date:** 2026-02-28
**Status:** Approved
**Scope:** 17 architectural pillars across 3 tiers — foundational infrastructure, identity-defining systems, and developer experience

## Context

Aurora CMS is a ground-up reimagining of Drupal 11, decomposed into 32 packages across 7 strict layers. The v0.1.0 implementation delivered a production-grade entity system, JSON:API, AI-native introspection, and MCP endpoint. This document designs the architectural pillars needed to make Aurora best-in-class — not an incremental MVP, but a CMS that learns from Drupal's failures and Laravel's 12-version evolution.

### Design Decisions (from brainstorming)

| Decision | Choice |
|----------|--------|
| Deployment model | Single-tenant primary, with multi-tenancy seams |
| Target audience | Full-stack PHP devs + agency/enterprise teams + AI-assisted developers |
| Database abstraction | Doctrine DBAL + Aurora's own migration system |
| Admin SPA framework | Vue 3 + Nuxt |
| i18n scope | Full: content + config + UI across all layers |
| Service container | Hybrid: Laravel-style ServiceProviders compiled to Symfony cached container |
| Error handling | Typed exceptions + Result objects |
| Drupal migration | Design for it, build later |

## Tier 1 — Foundational (must design before any more features)

### Pillar 1: Service Provider + Container Compilation

**Problem:** Aurora's 32 packages have no standardized lifecycle. Services are wired manually. There is no auto-discovery, no deferred loading, no way for a package to declare what it provides and requires in a single predictable place.

**Design:** Each package declares a `ServiceProvider` with `register()` and `boot()` methods. Auto-discovered via `composer.json` `extra.aurora.providers`. Compiled to Symfony's cached container for production.

```php
namespace Aurora\Foundation;

abstract class ServiceProvider
{
    // Bind interfaces to implementations. No side effects, no resolving other services.
    abstract public function register(): void;

    // Use resolved services. All packages are registered when boot() runs.
    public function boot(): void {}

    // Deferred provider — only loaded when one of these is requested.
    public function provides(): array { return []; }

    // Binding helpers
    protected function singleton(string $abstract, string|callable $concrete): void;
    protected function bind(string $abstract, string|callable $concrete): void;
    protected function tag(string $abstract, string $tag): void;
}
```

**Auto-discovery via composer.json:**

```json
{
    "extra": {
        "aurora": {
            "providers": ["Aurora\\Entity\\EntityServiceProvider"]
        }
    }
}
```

**Key design decisions:**

- `register()` is pure binding — no side effects, no using other services
- `boot()` runs after ALL packages are registered — safe to resolve cross-package dependencies
- Provider ordering follows layer dependency (Layer 0 first, Layer 6 last)
- Deferred providers (`provides()`) are only loaded when their interfaces are requested — keeps cold boot fast
- Each meta-package (core, cms, full) aggregates its packages' providers automatically
- `TestServiceProvider` can override any binding: `$this->app->swap(Interface::class, $mock)`
- Compilation: during `aurora cache:clear` or first boot, the Kernel iterates all providers, calls `register()`, then compiles the result into Symfony's `ContainerBuilder` dumped as PHP. Production requests never touch provider code.

**Package convention:** Every package in `packages/` must have exactly one `ServiceProvider`. The provider is the package's entry point into Aurora's runtime.

---

### Pillar 2: Domain Events + Event Architecture

**Problem:** Aurora uses Symfony EventDispatcher with entity lifecycle events and config events, but there is no unified domain event pattern, no async dispatch, no event sourcing seams, no broadcasting for real-time admin updates, and no cross-package event discovery.

**Design:** A structured `DomainEvent` base that carries metadata, routes through Symfony EventDispatcher for sync handling, and optionally dispatches to Symfony Messenger for async processing or SSE for real-time broadcasting.

```php
namespace Aurora\Foundation\Event;

abstract class DomainEvent
{
    public readonly string $eventId;
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $aggregateType,  // 'node', 'user', 'config'
        public readonly string $aggregateId,    // entity ID or config name
        public readonly ?string $tenantId = null,
        public readonly ?string $actorId = null,
    ) {
        $this->eventId = Uuid::v7()->toString();
        $this->occurredAt = new \DateTimeImmutable();
    }

    abstract public function getPayload(): array;
}
```

**Concrete event example:**

```php
namespace Aurora\Entity\Event;

final class EntitySaved extends DomainEvent
{
    public function __construct(
        public readonly EntityInterface $entity,
        public readonly array $changedFields,
        public readonly bool $isNew,
        ?string $tenantId = null,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateType: $entity->getEntityTypeId(),
            aggregateId: $entity->id(),
            tenantId: $tenantId,
            actorId: $actorId,
        );
    }

    public function getPayload(): array
    {
        return [
            'entity_type' => $this->entity->getEntityTypeId(),
            'entity_id' => $this->entity->id(),
            'changed_fields' => $this->changedFields,
            'is_new' => $this->isNew,
        ];
    }
}
```

**Three dispatch channels from a single event:**

```
DomainEvent dispatched
    |
    +-- Sync listeners (Symfony EventDispatcher)
    |     Cache invalidation, access index updates, validation side-effects
    |     Must complete before response.
    |
    +-- Async listeners via #[Async] attribute (Symfony Messenger)
    |     AI re-embedding, search re-indexing, webhook delivery, pipeline triggers
    |     Dispatched to queue, returns immediately.
    |
    +-- Broadcast listeners via #[Broadcast] attribute (SSE)
          Admin SPA real-time updates, collaborative editing signals, pipeline progress
          Pushed to connected clients.
```

**Listener registration via attributes:**

```php
#[Listener]
final class ReindexEntityOnSave
{
    #[Async]
    public function __invoke(EntitySaved $event): void
    {
        $this->embedder->embed($event->entity);
    }
}

#[Listener]
#[Broadcast(channel: 'admin.{aggregateType}')]
final class NotifyAdminOnEntityChange
{
    public function __invoke(EntitySaved $event): array
    {
        return [
            'type' => $event->isNew ? 'created' : 'updated',
            'entity_type' => $event->aggregateType,
            'entity_id' => $event->aggregateId,
        ];
    }
}
```

**EventBus — central dispatcher:**

```php
namespace Aurora\Foundation\Event;

final class EventBus
{
    public function __construct(
        private EventDispatcherInterface $syncDispatcher,
        private MessageBusInterface $asyncBus,
        private BroadcasterInterface $broadcaster,
        private ?EventStoreInterface $eventStore = null,
    ) {}

    public function dispatch(DomainEvent $event): void
    {
        $this->eventStore?->append($event);
        $this->syncDispatcher->dispatch($event);
        $this->asyncBus->dispatch(new AsyncEventEnvelope($event));
        $this->broadcaster->broadcast($event);
    }
}
```

**Key design decisions:**

- `DomainEvent` carries identity: `eventId` (UUIDv7), `occurredAt`, `aggregateType`, `aggregateId`, `tenantId`, `actorId`
- Three channels, one dispatch: sync for immediate consistency, async for expensive work, broadcast for real-time UI
- Event sourcing is a seam, not a requirement: `EventStoreInterface` is optional. When wired, all events persist to an append-only store enabling audit trails and replay.
- Broadcast channels are tenant-scoped automatically
- `#[Listener]` attribute on class + `#[Async]`/`#[Broadcast]` on method — discovered at compile time, zero runtime overhead
- Replaces existing entity event constants (`EntityEvents::POST_SAVE` becomes `EntitySaved`). Backward-compatible bridge during migration.
- Domain events buffered during `UnitOfWork` transactions and dispatched after commit

---

### Pillar 3: Package Migrations System

**Problem:** Aurora has no migration system. Entity storage creates tables on the fly via `SqlSchemaHandler`. No versioning, no rollback, no data migrations, no cross-package ordering.

**Design:** Aurora's own migration system on Doctrine DBAL. PHP migration classes with a fluent `SchemaBuilder`. Cross-package dependency ordering. Batch tracking for rollback.

**Migration file convention:**

```
packages/{package}/migrations/
    YYYY_MM_DD_NNNNNN_description.php
```

**Migration class:**

```php
return new class extends Migration
{
    public array $after = ['aurora/entity-storage'];

    public function up(SchemaBuilder $schema): void
    {
        $schema->create('users', function (TableBuilder $table) {
            $table->id();
            $table->string('name');
            $table->string('mail')->unique();
            $table->string('password');
            $table->string('status')->default('active');
            $table->json('_data')->nullable();
            $table->timestamps();
            $table->index(['mail', 'status']);
        });
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

**SchemaBuilder — fluent API over Doctrine DBAL:**

```php
namespace Aurora\Foundation\Migration;

final class SchemaBuilder
{
    public function __construct(
        private DbalConnection $connection,
        private string $tablePrefix = '',
    ) {}

    public function create(string $table, \Closure $callback): void;
    public function table(string $table, \Closure $callback): void;
    public function drop(string $table): void;
    public function dropIfExists(string $table): void;
    public function rename(string $from, string $to): void;
    public function hasTable(string $table): bool;
    public function hasColumn(string $table, string $column): bool;
}
```

**TableBuilder — column types + Aurora conventions:**

```php
namespace Aurora\Foundation\Migration;

final class TableBuilder
{
    // Column types
    public function id(string $name = 'id'): ColumnDefinition;
    public function string(string $name, int $length = 255): ColumnDefinition;
    public function text(string $name): ColumnDefinition;
    public function integer(string $name): ColumnDefinition;
    public function boolean(string $name): ColumnDefinition;
    public function float(string $name): ColumnDefinition;
    public function json(string $name): ColumnDefinition;
    public function timestamp(string $name): ColumnDefinition;
    public function timestamps(): void;

    // Indexes
    public function primary(array $columns): void;
    public function unique(array|string $columns): void;
    public function index(array|string $columns, ?string $name = null): void;
    public function foreign(string $column): ForeignKeyDefinition;

    // Aurora conventions
    public function entityBase(): void;          // id + entity_type + bundle + _data + timestamps
    public function translationColumns(): void;  // langcode + default_langcode + translation_source
    public function revisionColumns(): void;     // revision_id + revision_created + revision_log
}
```

**Migration runner:**

```php
namespace Aurora\Foundation\Migration;

final class Migrator
{
    public function run(): MigrationResult;
    public function rollback(int $steps = 1): MigrationResult;
    public function reset(): MigrationResult;
    public function refresh(): MigrationResult;
    public function status(): array;
}
```

**Migration tracking table:**

```sql
CREATE TABLE aurora_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration VARCHAR(255) NOT NULL,
    package VARCHAR(128) NOT NULL,
    batch INTEGER NOT NULL,
    ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Key design decisions:**

- Aurora owns the format, DBAL owns the SQL — portable across MySQL, PostgreSQL, SQLite
- `$after` for cross-package ordering — topological sort ensures dependencies run first
- Batch tracking — rollback undoes the last `aurora migrate` invocation, not a single migration
- Convention methods (`entityBase()`, `translationColumns()`, `revisionColumns()`) enforce Aurora's storage patterns
- Data migrations via `$this->query()` for raw SQL within migration classes
- Development mode: `SqlSchemaHandler` auto-creates tables then `aurora make:migration --from-schema` generates the delta
- Production mode: `SqlSchemaHandler` disabled. Only migrations run.
- Anonymous classes (`return new class extends Migration`) — no naming collisions, no autoloading needed
- Multi-tenancy: `SchemaBuilder` accepts `tablePrefix` from `TenantContext`

**CLI commands:**

```
aurora migrate                  # Run all pending
aurora migrate:rollback         # Rollback last batch
aurora migrate:status           # Show pending/completed
aurora migrate:reset            # Rollback everything
aurora migrate:refresh          # Reset + run (dev only)
aurora make:migration           # Generate migration file
    --package=aurora/user
    --create=table_name
    --table=table_name
```

---

### Pillar 4: i18n Foundation (Multilingual Architecture)

**Problem:** i18n is the single biggest missing pillar. Translation touches every layer: entity storage, field definitions, config values, API responses, admin SPA, AI embeddings, routing, and MCP tools.

**Design principles:**

- Two-table storage, not Drupal's four — base (untranslatable) + translations (translatable). Revisions add a third only when needed.
- Entity is always one language — no `getTranslation()` returning a different object. Load the language you want.
- Fallback at the database level — `COALESCE` joins, single query, no N+1.
- Two language axes — content language and interface language are independent.
- Config translation as namespaced config — `i18n/fr/system.site.yml`, not a separate system.
- AI embeddings per language — each translation is independently embedded and searchable.
- `aurora/i18n` in Layer 0 — language is foundational, available to every package.

#### Layer 0 — Language as a Foundation concern

```php
namespace Aurora\Foundation\Language;

final class Language
{
    public function __construct(
        public readonly string $id,         // 'en', 'fr', 'zh-hans'
        public readonly string $label,      // 'English', 'Francais'
        public readonly string $direction,  // 'ltr' or 'rtl'
        public readonly int $weight = 0,
        public readonly bool $isDefault = false,
    ) {}
}

interface LanguageManagerInterface
{
    public function getDefaultLanguage(): Language;
    public function getLanguage(string $id): ?Language;
    public function getLanguages(): array;
    public function getCurrentLanguage(): Language;
    public function getFallbackChain(string $langcode): array;
    public function isMultilingual(): bool;
}

final class LanguageContext
{
    public function __construct(
        private Language $contentLanguage,
        private Language $interfaceLanguage,
        private ?string $tenantId = null,
    ) {}

    public function withContentLanguage(Language $lang): self;
    public function withInterfaceLanguage(Language $lang): self;
}
```

Two language axes: content language controls which entity translations load, interface language controls admin SPA strings and system messages. They can differ — a French editor managing English content.

#### Layer 1 — Entity translation storage

**Two-table pattern:**

```sql
-- Base table: language-independent data
CREATE TABLE nodes (
    id VARCHAR(128) PRIMARY KEY,
    bundle VARCHAR(64) NOT NULL,
    default_langcode VARCHAR(12) NOT NULL DEFAULT 'en',
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    created TIMESTAMP NOT NULL,
    changed TIMESTAMP NOT NULL,
    _data JSON,
    author_id VARCHAR(128),
    sticky BOOLEAN DEFAULT FALSE
);

-- Translation table: one row per (entity_id, langcode)
CREATE TABLE node_translations (
    entity_id VARCHAR(128) NOT NULL,
    langcode VARCHAR(12) NOT NULL,
    title VARCHAR(255),
    body TEXT,
    summary TEXT,
    _data JSON,
    translation_status VARCHAR(32) DEFAULT 'draft',
    translation_source VARCHAR(12),
    translation_created TIMESTAMP,
    translation_changed TIMESTAMP,
    PRIMARY KEY (entity_id, langcode),
    FOREIGN KEY (entity_id) REFERENCES nodes(id) ON DELETE CASCADE
);
```

Base table holds untranslatable fields. Translation table holds translatable fields. Default language values live in the translation table like every other language — no special case.

**Field definitions carry translatability:**

```php
FieldDefinition::create('title')
    ->setType('string')
    ->setTranslatable(true);     // stored in translations table

FieldDefinition::create('author_id')
    ->setType('entity_reference')
    ->setTranslatable(false);    // stored in base table
```

**Entity loading — always one language:**

```php
$node = $nodeStorage->load('42');                              // current language
$node = $nodeStorage->load('42', language: 'fr');              // specific language
$node = $nodeStorage->load('42', language: 'fr', fallback: true); // with fallback chain

$node->getTranslationStatus();     // 'published', 'draft', 'needs_review'
$node->getTranslationSource();     // 'en' (translated from English)
$node->getAvailableLanguages();    // ['en', 'fr', 'de']
$node->isDefaultTranslation();     // true if langcode == default_langcode
```

**Fallback resolved at database level:**

```sql
SELECT n.*,
    COALESCE(t_fr.title, t_es.title, t_en.title) AS title,
    COALESCE(t_fr.body, t_es.body, t_en.body) AS body
FROM nodes n
LEFT JOIN node_translations t_fr ON n.id = t_fr.entity_id AND t_fr.langcode = 'fr'
LEFT JOIN node_translations t_es ON n.id = t_es.entity_id AND t_es.langcode = 'es'
LEFT JOIN node_translations t_en ON n.id = t_en.entity_id AND t_en.langcode = 'en'
WHERE n.id = ?
```

One query, no N+1, fallback resolved in SQL.

**Revisions + translations (when enabled):**

```sql
CREATE TABLE node_revisions (
    entity_id VARCHAR(128) NOT NULL,
    revision_id VARCHAR(128) NOT NULL,
    langcode VARCHAR(12) NOT NULL,
    title VARCHAR(255),
    body TEXT,
    revision_log TEXT,
    revision_created TIMESTAMP,
    revision_author_id VARCHAR(128),
    PRIMARY KEY (revision_id, langcode),
    FOREIGN KEY (entity_id) REFERENCES nodes(id) ON DELETE CASCADE
);
```

Three tables max: base, translations (current), revisions (history). Drupal's 4-table pattern collapses to 3 because untranslatable fields stay in the base table.

#### Layer 1 — Config translation

```
config/sync/system.site.yml                   # original
config/sync/i18n/fr/system.site.yml           # French override
```

Config translation uses the same config storage with a language-prefixed namespace:

```php
interface ConfigFactoryInterface
{
    public function get(string $name): ConfigInterface;                          // auto-translated
    public function getTranslated(string $name, string $langcode): ConfigInterface;
    public function getOriginal(string $name): ConfigInterface;                 // untranslated
}
```

Config schema declares which keys are translatable:

```yaml
system.site:
  type: mapping
  properties:
    site_name:
      type: string
      translatable: true
    slogan:
      type: string
      translatable: true
    default_langcode:
      type: string
      translatable: false
```

#### Layer 2 — Language negotiation

Three negotiators, applied in order:

1. **Explicit parameter:** `/fr/api/node/42` (URL prefix)
2. **HTTP header:** `Accept-Language: fr` (API clients)
3. **Default language:** configured in `system.site`

No session negotiation, no domain negotiation, no browser sniffing. Those can be added as optional packages.

```php
namespace Aurora\Routing\Language;

final class LanguageNegotiator
{
    /** @param LanguageNegotiatorInterface[] $negotiators */
    public function __construct(
        private array $negotiators,
        private LanguageManagerInterface $languageManager,
    ) {}

    public function negotiate(Request $request): LanguageContext;
}
```

Runs as middleware — sets `LanguageContext` on request, all downstream services read from it.

#### Layer 4 — API language behavior

```http
GET /api/node/42?langcode=fr
Accept-Language: fr
```

Response includes translation metadata:

```json
{
    "data": {
        "type": "node",
        "id": "42",
        "attributes": {
            "title": "Bonjour le monde",
            "langcode": "fr",
            "default_langcode": "en",
            "translation_status": "published",
            "available_languages": ["en", "fr", "de"]
        }
    },
    "meta": {
        "langcode": "fr",
        "fallback_used": false
    }
}
```

**Translation CRUD sub-endpoints:**

- `GET /api/{type}/{id}/translations` — list available translations
- `GET /api/{type}/{id}/translations/{langcode}` — get specific translation
- `POST /api/{type}/{id}/translations/{langcode}` — create translation
- `PATCH /api/{type}/{id}/translations/{langcode}` — update translation
- `DELETE /api/{type}/{id}/translations/{langcode}` — delete translation

Collection queries: `GET /api/node?langcode=fr&fallback=true&filter[status]=published`

#### Layer 5 — AI i18n

**Vector embeddings per language:**

```php
final class TranslationAwareEmbedder
{
    #[Async]
    public function onTranslationSaved(TranslationSaved $event): void
    {
        $this->vectorStore->store(new EntityEmbedding(
            entityType: $event->aggregateType,
            entityId: $event->aggregateId,
            langcode: $event->langcode,
            vector: $this->provider->embed($event->translatedText),
        ));
    }
}
```

**Language-aware similarity search:**

```php
$results = $vectorStore->search(
    query: "climate change policy",
    langcode: 'fr',
    fallback: ['en'],
    limit: 10,
);
```

**AI translation pipeline:**

```php
$pipeline = Pipeline::create('translate-content')
    ->addStep(new LoadEntity(id: '42', langcode: 'en'))
    ->addStep(new AiTranslate(targetLangcode: 'fr'))
    ->addStep(new SaveTranslation(langcode: 'fr'))
    ->addStep(new EmbedTranslation(langcode: 'fr'));
```

#### Layer 6 — Admin SPA i18n

Vue I18n (`@intlify/vue-i18n`) with lazy-loaded JSON message files per language. Side-by-side translation editor. Translation status badges (draft, published, needs review, outdated). AI Translate button triggers pipeline. Schema-driven forms show translatable fields in translation panel, untranslatable fields only in default language form.

#### Layer 6 — MCP tool language awareness

Every entity CRUD tool accepts optional `langcode` and `fallback` parameters. Translation-specific tools auto-generated: `aurora_{type}_translations_list`, `aurora_{type}_translation_create`, `aurora_{type}_translation_update`.

#### i18n package map

```
aurora/i18n (new, Layer 0)     Language, LanguageManager, LanguageContext, FallbackChain,
                                interface translation loading
aurora/entity (enhanced)        TranslatableInterface, field translatability metadata
aurora/entity-storage (enhanced) Translation table creation, language-aware load/query
aurora/config (enhanced)        Config translation storage, translatable schema keys
aurora/routing (enhanced)       LanguageNegotiator middleware, URL prefix routing
aurora/api (enhanced)           langcode parameter, translation CRUD sub-endpoints
aurora/ai-vector (enhanced)     Language-scoped embeddings, cross-language fallback
aurora/admin (enhanced)         Vue I18n, side-by-side editor, translation dashboard
```

---

## Tier 2 — Structural (defines Aurora's identity)

### Pillar 5: Unified Error Handling

**Problem:** No consistent error strategy. Some packages throw generic exceptions, some return booleans, API/CLI/MCP errors handled differently. No telemetry, no problem classification.

**Design:** Two error primitives — `Result` for domain outcomes, exceptions for infrastructure failures.

```php
namespace Aurora\Foundation\Result;

/** @template T @template E */
final readonly class Result
{
    public static function ok(mixed $value = null): self;
    public static function fail(mixed $error): self;

    public function isOk(): bool;
    public function isFail(): bool;
    public function unwrap(): mixed;
    public function unwrapOr(mixed $default): mixed;
    public function error(): mixed;
    public function map(\Closure $fn): self;
    public function mapError(\Closure $fn): self;
}
```

**When to use which:**

| Situation | Mechanism |
|-----------|-----------|
| Expected domain outcome (validation fails, access denied, not found) | `Result` |
| Unexpected infrastructure failure (database down, filesystem error) | Exception |
| Programmer error (invalid argument, type mismatch) | Exception |

**Structured domain errors (RFC 9457 compatible):**

```php
namespace Aurora\Foundation\Result;

final readonly class DomainError
{
    public function __construct(
        public string $type,          // URI: 'aurora:entity/not-found'
        public string $title,         // 'Entity Not Found'
        public string $detail,        // 'Node 42 does not exist'
        public int $statusCode = 400,
        public array $meta = [],
    ) {}

    public static function entityNotFound(string $entityType, string $id): self;
    public static function accessDenied(string $operation, string $entityType, string $id): self;
    public static function validationFailed(array $violations): self;
    public static function translationMissing(string $entityType, string $id, string $langcode): self;
}
```

**Exception hierarchy:**

```php
namespace Aurora\Foundation\Exception;

abstract class AuroraException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $problemType = 'aurora:internal-error',
        public readonly int $statusCode = 500,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) { parent::__construct($message, 0, $previous); }

    public function toApiError(): array;
    public function toCliOutput(): string;
}

final class StorageException extends AuroraException {}       // 503
final class ConfigException extends AuroraException {}        // 500
final class AuthenticationException extends AuroraException {} // 401
final class PackageException extends AuroraException {}       // 500
```

**Central ExceptionHandler — maps to appropriate responses:**

```php
namespace Aurora\Foundation\Exception;

final class ExceptionHandler
{
    public function render(string $exceptionClass, \Closure $renderer): void;
    public function report(string $exceptionClass, \Closure $reporter): void;

    public function handle(\Throwable $e, RequestContext $context): Response
    {
        $this->reportException($e);
        return match ($context->type) {
            RequestContext::API => $this->renderApiError($e),
            RequestContext::CLI => $this->renderCliError($e),
            RequestContext::MCP => $this->renderMcpError($e),
            RequestContext::SSR => $this->renderHtmlError($e),
        };
    }
}
```

Same exception, four renderings: JSON:API error document, formatted CLI output, JSON-RPC error, Twig error page.

---

### Pillar 6: Config Versioning + Schema

**Problem:** Config import/export exists but there is no schema validation, no environment overrides, no config versioning for safe deployments.

**Design:**

**Config schema (reuses ai-schema JSON Schema infrastructure):**

```yaml
# packages/user/config/schema/user.settings.schema.yml
user.settings:
  type: object
  properties:
    registration:
      type: string
      enum: [open, admin_only, closed]
      default: admin_only
    password_min_length:
      type: integer
      minimum: 8
      default: 12
```

Every config file has a matching schema. `aurora config:validate` checks all config against schemas.

**Environment overrides:**

```
config/
    sync/                          # base config (committed to git)
    environments/
        local/                     # gitignored
        staging/
        production/
```

Resolution order (last wins):

1. `config/sync/{name}.yml` (base)
2. `config/environments/{AURORA_ENV}/{name}.yml` (environment overlay)
3. Environment variables `AURORA_CONFIG_*` (runtime overrides)

`AURORA_ENV` selects the overlay. Config is data, not code.

**Config versioning manifest:**

```yaml
# config/sync/.aurora-config-manifest.yml
version: "2026.03.15.001"
checksum: "sha256:a1b2c3..."
packages:
    aurora/user: "2026.03.15.001"
    aurora/node: "2026.03.12.003"
generated_at: "2026-03-15T14:30:00Z"
```

Auto-generated on `aurora config:export`. On import, warns about: config from removed packages, backward version changes, unresolved conflicts.

**CLI commands:**

```
aurora config:export
aurora config:import
aurora config:validate
aurora config:diff
aurora config:diff --env=staging
```

---

### Pillar 7: Storage Abstraction Boundaries

**Problem:** `SqlEntityStorage` is both the repository and the persistence driver. No clean extension point for non-SQL storage, no unit-of-work for transactional consistency.

**Design: Three layers, clean boundaries.**

```
EntityRepositoryInterface      (public API — what developers use)
    |
EntityStorageDriverInterface   (persistence SPI — what implementors build)
    |
ConnectionInterface            (database abstraction — Doctrine DBAL)
```

```php
namespace Aurora\Entity\Repository;

interface EntityRepositoryInterface
{
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface;
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array;
    public function save(EntityInterface $entity): Result;
    public function delete(EntityInterface $entity): Result;
    public function query(): EntityQueryInterface;
    public function exists(string $id): bool;
    public function count(array $criteria = []): int;
}
```

```php
namespace Aurora\EntityStorage\Driver;

interface EntityStorageDriverInterface
{
    public function read(string $entityType, string $id, ?string $langcode = null): ?array;
    public function write(string $entityType, string $id, array $values): void;
    public function remove(string $entityType, string $id): void;
    public function query(string $entityType): StorageQueryInterface;
    public function schemaHandler(): SchemaHandlerInterface;
}
```

Repository handles language fallback, access checking, event dispatch, entity hydration. Driver is pure I/O.

**Swappable drivers:** `SqlStorageDriver`, `ElasticsearchStorageDriver`, `ApiStorageDriver`, `InMemoryStorageDriver`.

**Connection resolver (multi-tenancy seam):**

```php
namespace Aurora\EntityStorage\Connection;

interface ConnectionResolverInterface
{
    public function connection(?string $name = null): ConnectionInterface;
    public function getDefaultConnectionName(): string;
}
```

**Unit of work:**

```php
namespace Aurora\EntityStorage;

final class UnitOfWork
{
    public function transaction(\Closure $callback): mixed;
}
```

Domain events are buffered during a transaction and dispatched after commit.

---

### Pillar 8: Multi-Tenancy Seams

**Problem:** Aurora is single-tenant. The goal is baking in architectural seams so multi-tenancy can be layered on without rewriting core.

**Design:**

```php
namespace Aurora\Foundation\Tenant;

final readonly class TenantContext
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $databaseName = null,
        public ?string $configPrefix = null,
        public array $metadata = [],
    ) {}
}

interface TenantResolverInterface
{
    public function resolve(Request $request): ?TenantContext;
}

// Single-tenant default — zero overhead
final class NullTenantResolver implements TenantResolverInterface
{
    public function resolve(Request $request): ?TenantContext
    {
        return null;
    }
}
```

**Seam locations:**

| Subsystem | Seam | Single-tenant behavior |
|-----------|------|----------------------|
| Database | `ConnectionResolverInterface` | Returns default connection |
| Config | `TenantConfigOverlay` | No overlay |
| Cache | Key prefix `{tenantId}:` | No prefix |
| Entity storage | `ConnectionResolverInterface` | Default connection |
| File storage | Base path `{tenantId}/files/` | Default path |
| Events | `DomainEvent.tenantId` | `null` |
| Broadcast | Channel prefix `{tenantId}.` | No prefix |
| Queue | Message carries `tenantId` | `null` |
| Language | Per-tenant language settings | Global settings |
| MCP | Per-tenant tool scoping | All tools available |

**TenantMiddleware — the orchestration point:**

```php
final class TenantMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);
        if ($tenant !== null) {
            $this->connectionResolver->setTenant($tenant);
            $this->configFactory->setTenantOverlay($tenant);
            $this->cacheFactory->setPrefix($tenant->id);
        }
        return $next($request);
    }
}
```

**Isolation strategies** (chosen by `aurora/multi-tenant` package, not core):

| Strategy | Description |
|----------|-------------|
| Database-per-tenant | Separate databases. Strongest isolation. |
| Schema-per-tenant | Shared database, separate schemas (PostgreSQL). |
| Row-level | Shared tables, `tenant_id` column. Lowest overhead. |

---

### Pillar 14: Authorization Policies

**Problem:** Entity-level access control exists but there is no resource-level authorization for non-entity operations (config export, AI pipelines, MCP access, admin dashboard, translation management).

**Design: Two authorization layers.**

```
Gate (resource-level)           "Can this user do this action?"
EntityAccessHandler (entity)    "Can this user do this to this entity?"
Both feed into the same AccessResult.
```

**Gate:**

```php
namespace Aurora\Access\Gate;

interface GateInterface
{
    public function allows(string $ability, mixed ...$arguments): bool;
    public function denies(string $ability, mixed ...$arguments): bool;
    public function check(string $ability, mixed ...$arguments): AccessResult;
    public function authorize(string $ability, mixed ...$arguments): void;
}
```

**Policies — grouped authorization logic per domain:**

```php
#[Policy]
final class ConfigPolicy
{
    public function export(AccountInterface $user): AccessResult
    {
        return $user->hasPermission('export configuration')
            ? AccessResult::allowed()
            : AccessResult::forbidden('Missing permission: export configuration');
    }
}

#[Policy]
final class PipelinePolicy
{
    public function execute(AccountInterface $user, Pipeline $pipeline): AccessResult
    {
        if ($user->hasPermission('administer pipelines')) {
            return AccessResult::allowed();
        }
        return $user->hasPermission("execute pipeline:{$pipeline->id}")
            ? AccessResult::allowed()
            : AccessResult::forbidden();
    }
}
```

**Convention:** `Gate::check('config.export')` resolves to `ConfigPolicy::export()`. Domain prefix maps to policy class at compile time.

**Route integration:**

```php
#[Route('/api/config/export', methods: ['POST'])]
#[Gate('config.export')]
public function export(): Response { ... }

#[Route('/api/node/{id}', methods: ['PATCH'])]
#[EntityAccess('update')]
public function update(Node $node): Response { ... }
```

**Standard abilities:**

```
admin.access                 config.export            config.import
translation.manage           translation.manage:{lang}
pipeline.execute             pipeline.create
mcp.connect                  mcp.tools:{tool}
user.impersonate
```

Packages register abilities in their ServiceProvider. Admin SPA reads available abilities from `GET /api/abilities`.

---

## Tier 3 — Experience (what makes developers choose Aurora)

### Pillar 9: Schema-Driven Admin UI Architecture

**Problem:** Admin SPA is a scaffold. Aurora's differentiator is that one schema powers forms, lists, navigation, and AI tools. The rendering layer does not exist yet.

**Design:**

**Schema pipeline:**

```
EntityType + FieldDefinitions
    | (aurora/ai-schema generates)
JSON Schema per entity type per operation (create/edit/view/list)
    | (admin SPA consumes at runtime)
SchemaFormRenderer -> auto-generated forms
SchemaListBuilder  -> auto-generated data grids
SchemaNavBuilder   -> auto-generated admin navigation
```

**Schema endpoint:**

```http
GET /api/schema/node--article
```

Returns: entity type metadata, per-field definitions with `widget` hints (text_input, rich_text, entity_autocomplete, select, toggle, media_picker, date_picker), operation-specific required/optional fields, current user's permissions for this type.

**Vue 3 + Nuxt admin SPA structure:**

```
packages/admin/
    app/
        components/
            schema/          SchemaForm, SchemaList, SchemaField
            widgets/         TextInput, RichText, EntityAutocomplete, Select,
                            Toggle, MediaPicker, DatePicker
            layout/          AdminShell, NavBuilder
            ai/              AiAssistPanel, TranslateButton
        composables/
            useSchema.ts     Fetch + cache entity schemas
            useEntity.ts     CRUD operations via JSON:API
            useLanguage.ts   Language switching + context
            useRealtime.ts   SSE subscription for live updates
            useAbilities.ts  Permission-aware UI rendering
        pages/
            [entityType]/index.vue     SchemaList
            [entityType]/[id].vue      SchemaForm (edit)
            [entityType]/create.vue    SchemaForm (create)
            translations/[entityType]/[id].vue   Side-by-side editor
```

**SchemaForm:** Renders any entity form from schema. `SchemaField` resolves widget component dynamically from the `widget` field in the schema. Zero entity-specific form code — add a new entity type with fields and the admin SPA automatically has a working form, list, and navigation entry.

**AI assist panel:** Schema-aware content creation copilot. Knows field types, constraints, translatability. Calls Aurora's AI pipeline endpoints for suggestions, drafts, summaries, and translations.

---

### Pillar 10: Testing Ergonomics

**Problem:** Good in-memory infrastructure exists but no developer-facing testing helpers.

**Design: `aurora/testing` package (Layer 0).**

**Entity factories:**

```php
NodeFactory::define('node', [
    'title' => fn () => FakeData::sentence(),
    'body' => fn () => FakeData::paragraphs(3),
    'status' => 'draft',
    'bundle' => 'article',
    'author_id' => fn () => UserFactory::create()->id(),
]);

$node = NodeFactory::create(['title' => 'Specific Title']);
$nodes = NodeFactory::published()->createMany(10);
$node = NodeFactory::withTranslation('fr', ['title' => 'Titre'])->create();
```

**Test case base class:**

```php
abstract class AuroraTestCase extends \PHPUnit\Framework\TestCase
{
    use CreatesApplication;      // boots Aurora with test config
    use InteractsWithEntities;   // factory shortcuts
    use InteractsWithAuth;       // actingAs(), assertAuthenticated()
    use InteractsWithApi;        // JSON:API request helpers
    use InteractsWithEvents;     // assertEventDispatched()
    use InteractsWithQueue;      // assertJobDispatched()
    use RefreshDatabase;         // transaction per test, auto-rollback
}
```

**Key helpers:**

```php
// Auth
$this->actingAs($admin);

// JSON:API
$response = $this->getJson('/api/node/42');
$response->assertOk();
$response->assertJsonApiType('node');
$response->assertJsonApiAttribute('title', 'Hello World');

$response = $this->postJson('/api/node', [...]);
$response->assertCreated();

// Events + queue
$this->assertEventDispatched(EntitySaved::class);
$this->assertJobDispatched(ReindexEntityJob::class);

// Entities
$this->assertEntityExists('node', '42');
$this->assertEntityCount('node', 10, ['status' => 'published']);
$this->assertTranslationExists('node', '42', 'fr');
```

**RefreshDatabase trait:** Every test runs in a transaction that rolls back on tearDown. Fast isolation without re-migrating.

---

### Pillar 11: DX Conventions + Developer Experience

**Problem:** No "Aurora way" — no scaffolding, no introspection commands, no REPL, no debugging helpers.

**Design:**

**Scaffolding commands:**

```
aurora make:entity Article
aurora make:field-type ColorField
aurora make:migration create_comments_table --package=aurora/node --create=comments
aurora make:provider SitemapServiceProvider
aurora make:command GenerateSitemap
aurora make:policy ContentPolicy
aurora make:listener NotifyOnPublish --event=EntitySaved --async
aurora make:job ProcessUpload
aurora make:test NodeRepositoryTest --unit
```

**Introspection commands:**

```
aurora about                  # system info
aurora route:list             # all routes
aurora entity:list            # all entity types
aurora config:list            # all config files
aurora event:list             # all events + listeners
aurora schema:dump node       # JSON schema for entity type
aurora permission:list        # all permissions
aurora migration:status       # pending/completed migrations
```

**Debugging:**

```php
dd($entity);    // dump and die — formatted entity output
dump($entity);  // dump without dying
```

`dd()` for entities produces: entity type, ID, fields with values, translation status, access results — not raw PHP object dumps.

**`aurora tinker` — interactive REPL (PsySH):**

```
>>> $node = NodeFactory::create(['title' => 'Test']);
=> Aurora\Node\Node {id: "abc-123", title: "Test", status: "draft"}
>>> Node::query()->where('status', 'published')->count()
=> 1
```

**Convention enforcement:**

```
aurora check              # all checks
aurora check:naming       # class/file naming
aurora check:layers       # no upward layer dependencies
aurora check:providers    # all packages have providers
aurora check:schemas      # all config has schemas
```

---

### Pillar 12: Job/Queue Ergonomics

**Problem:** Queue package wraps Symfony Messenger but provides no developer-friendly job primitives.

**Design:**

```php
namespace Aurora\Queue\Job;

abstract class Job
{
    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 300;

    abstract public function handle(): void;
    public function failed(\Throwable $e): void {}
    public function middleware(): array { return []; }
}
```

**Dispatch:**

```php
dispatch(new ReindexEntity('node', '42', 'en'));
dispatch(new ReindexEntity('node', '42', 'en'))->delay(seconds: 30);

// Chained (sequential)
dispatch(new ChainedJobs([
    new TranslateContent('42', 'fr'),
    new ReindexEntity('node', '42', 'fr'),
]));

// Batched (parallel with completion tracking)
dispatch(new BatchedJobs([...]))->then(fn () => ...)->catch(fn () => ...);
```

**Job attributes:**

```php
#[UniqueJob(key: 'entity_id')]
#[RateLimited(maxPerMinute: 60)]
#[WithoutOverlapping(key: 'entity_id')]
#[OnQueue('ai-processing')]
#[Tenant]
```

**Failed job tracking:** `aurora_failed_jobs` table. CLI: `aurora queue:work`, `aurora queue:failed`, `aurora queue:retry {id}`.

---

### Pillar 13: Real-time Broadcasting

**Problem:** Admin SPA needs live updates for content changes, translation completions, AI pipeline progress.

**Design: SSE as primary transport (no WebSocket dependency).**

**Backend:**

```php
namespace Aurora\Foundation\Broadcasting;

interface BroadcasterInterface
{
    public function broadcast(DomainEvent $event): void;
    public function subscribe(string $channel, \Closure $callback): void;
}
```

SSE endpoint at `/api/broadcast/subscribe?channels=admin.node,pipeline.progress`. Gate-protected.

**Frontend composable:**

```ts
export function useRealtime(channels: string[]) {
    const source = new EventSource(`/api/broadcast/subscribe?channels=${channels.join(',')}`)
    // Returns reactive events ref, auto-closes on unmount
}
```

**Channel structure:**

| Pattern | Events | Consumer |
|---------|--------|----------|
| `admin.{entityType}` | Entity CRUD | Entity lists |
| `pipeline.{pipelineId}` | Step progress, completion | Pipeline UI |
| `translation.{entityType}.{id}` | Translation saved/deleted | Translation editor |
| `system` | Config changes, deployments | Dashboard |

Channels auto-prefixed with tenant ID when `TenantContext` is present. Connects to Pillar 2 via `#[Broadcast]` attribute on event listeners.

---

### Pillar 15: Telescope-like Debugging

**Problem:** No visibility into runtime behavior. Developers cannot see queries, events, cache operations, jobs, or AI operations.

**Design: `aurora/telescope` package (Layer 6, optional).**

**Records:**

- HTTP requests (method, path, status, duration)
- Database queries (SQL, bindings, duration)
- Entity operations (type, id, operation, langcode)
- Cache operations (key, hit/miss, tags)
- Events dispatched (class, listeners, async dispatches)
- Jobs (class, queue, status, duration, attempts)
- Config reads (name, source: base/env/tenant/translated)
- Access checks (ability, entity, user, result)
- AI operations (pipeline, step, model, tokens, cost)
- MCP tool calls (tool, arguments, result, client)
- Exceptions (class, message, trace, context)

**Storage:** Separate SQLite database (`storage/telescope/telescope.sqlite`). Auto-prunes based on configurable retention (default 24h).

**Viewing:** CLI (`aurora telescope`, `aurora telescope:queries --slow=100`) and admin SPA route (`/admin/telescope`).

**Configuration:**

```yaml
telescope:
    enabled: true
    record:
        requests: true
        queries: true
        slow_queries_only: false
        slow_query_threshold: 100
        events: true
        cache: true
        jobs: true
        ai: true
    storage:
        driver: sqlite
        retention: 24
    ignore_paths:
        - /api/broadcast/*
        - /health
```

Per-environment via Pillar 6 overlays: record everything in dev, slow queries + exceptions only in production.

---

### Pillar 16: Caching Strategy + Cache Invalidation Model

**Problem:** PSR-6/16 backends with tag support exist, but there is no invalidation strategy, no entity-level cache integration, no tenant/language-aware keys.

**Design:**

**Cache layers:**

| Layer | What | Invalidation | TTL |
|-------|------|-------------|-----|
| Config | Parsed YAML objects | `ConfigChanged` event | Until event |
| Entity schema | Type + field definitions | `SchemaChanged` event | Until event |
| Entity data | Loaded entity objects | `EntitySaved`/`EntityDeleted` | Until event |
| Query results | Entity query results | Tag: `entity:{type}` | 60s + event |
| API responses | Serialized JSON:API | `EntitySaved` + HTTP headers | HTTP cache |
| Computed schemas | JSON Schema, OpenAPI, MCP defs | `SchemaChanged` | Until event |
| Translation | Loaded translations | `TranslationSaved` | Until event |
| Access | Permission check results | `RoleChanged`/`PolicyChanged` | 300s + event |

**Cache key structure:**

```
{tenant?}:{bin}:{langcode?}:{key}

Examples:
    entity:en:node:42
    acme:entity:fr:node:42
    query:en:node:status=published:page=1
    schema:node--article
```

`CacheFactory` auto-prefixes with tenant and language from current context.

**Tag-based invalidation (event-driven):**

```php
// Store with tags
$cache->set('node:42', $data, tags: ['entity:node', 'entity:node:42', 'langcode:en']);

// Event listener invalidates
#[Listener]
final class EntityCacheInvalidator
{
    public function onEntitySaved(EntitySaved $event): void
    {
        $this->cache->invalidateTag("entity:{$event->aggregateType}:{$event->aggregateId}");
        $this->cache->invalidateTag("entity:{$event->aggregateType}");
    }
}
```

**Cache backends (pluggable):**

```yaml
cache:
    default: redis
    stores:
        redis: { driver: redis, connection: cache }
        memory: { driver: memory }
        database: { driver: database, table: aurora_cache }
    bins:
        config: { store: memory, ttl: 0 }
        entity: { store: redis, ttl: 3600 }
        query: { store: redis, ttl: 60 }
        schema: { store: memory, ttl: 0 }
```

Backend packages: `aurora/cache-redis`, `aurora/cache-memcached`, `aurora/cache-apcu`, `aurora/cache-database`.

**HTTP cache headers:**

```
Cache-Control: public, max-age=60, stale-while-revalidate=30
ETag: "node-42-en-2026-03-15T14:30:00"
Vary: Accept-Language, Authorization
```

Admin SPA invalidates local cache via broadcast events (Pillar 13). No polling.

---

### Pillar 17: Asset Pipeline + Frontend Build Strategy

**Problem:** Aurora needs a unified strategy for building, versioning, and serving frontend assets across admin SPA, SSR pages, and custom themes.

**Design:**

**Three asset contexts, one build tool (Vite):**

| Context | Technology | Output |
|---------|-----------|--------|
| Admin SPA | Vue 3 + Nuxt | `dist/admin/` |
| SSR components | Twig + Vue islands | `dist/ssr/` |
| Custom themes | CSS/JS per tenant | `dist/themes/{name}/` |

**Asset versioning:** Vite generates `manifest.json` mapping source files to hashed output:

```php
namespace Aurora\Foundation\Asset;

interface AssetManagerInterface
{
    public function url(string $path, string $bundle = 'admin'): string;
    public function preloadLinks(string $bundle = 'admin'): array;
}
```

```twig
<link rel="stylesheet" href="{{ asset('css/main.css', 'ssr') }}">
```

**Multi-tenant asset resolution:**

```
1. themes/{tenant-theme}/dist/    (tenant-specific)
2. dist/ssr/                      (base SSR)
3. dist/admin/                    (admin SPA)
```

**Vue islands for SSR pages:**

```html
<div data-vue-island="SearchComponent"
     data-props='{"endpoint": "/api/node"}'>
</div>
```

Each island built as separate Vite entry point. Runtime script discovers and hydrates them.

**CLI commands:**

```
aurora assets:build                  # all bundles (production)
aurora assets:dev                    # Vite dev server with HMR
aurora assets:build --bundle=admin   # admin SPA only
aurora assets:build --theme=agency   # specific theme
```

**Component generation:**

```
aurora make:component EntityTimeline    # admin SPA component
aurora make:island SearchWidget         # SSR Vue island
aurora make:widget TreeSelect           # schema form widget
```

---

## Roadmap

### Phase 1: Foundation Infrastructure

Pillars 1-3: Service Providers, Domain Events, Migrations

These are prerequisites for everything else. Every subsequent pillar depends on packages having a lifecycle (providers), events flowing through the system (domain events), and schema being versioned (migrations).

### Phase 2: Multilingual + Error Handling

Pillars 4-5: i18n Foundation, Unified Error Handling

i18n is the biggest single effort. Error handling standardizes the contracts all other pillars use for failure reporting.

### Phase 3: Storage + Security + Config

Pillars 6-8, 14: Config Versioning, Storage Abstraction, Multi-Tenancy Seams, Authorization Policies

These harden Aurora's internals and define its security model.

### Phase 4: Admin SPA + DX

Pillars 9-11: Schema-Driven Admin UI, Testing Ergonomics, DX Conventions

The visible payoff — Aurora becomes usable by developers beyond its creators.

### Phase 5: Runtime + Operations

Pillars 12-13, 15-17: Job/Queue, Broadcasting, Telescope, Caching, Asset Pipeline

The operational backbone that makes Aurora production-ready and delightful.

---

## Package Impact Map

### New packages

| Package | Layer | Purpose |
|---------|-------|---------|
| `aurora/i18n` | 0 | Language, LanguageManager, LanguageContext, FallbackChain |
| `aurora/testing` | 0 | Factories, test traits, assertion helpers |
| `aurora/telescope` | 6 | Runtime debugging and profiling |
| `aurora/cache-redis` | 0 | Redis cache backend |
| `aurora/cache-database` | 0 | Database cache backend |
| `aurora/multi-tenant` | 2 | Tenant resolvers, scoped services (optional) |

### Enhanced packages

| Package | Enhancements |
|---------|-------------|
| `aurora/foundation` (new or `aurora/cache` promoted) | ServiceProvider, DomainEvent, EventBus, Result, DomainError, AuroraException, ExceptionHandler, TenantContext, AssetManager |
| `aurora/entity` | TranslatableInterface, EntityRepositoryInterface, field translatability |
| `aurora/entity-storage` | StorageDriverInterface, ConnectionResolver, UnitOfWork, translation tables |
| `aurora/config` | Config schema, environment overrides, manifest, config translation |
| `aurora/access` | GateInterface, Policy attribute, resource-level authorization |
| `aurora/routing` | LanguageNegotiator middleware, TenantMiddleware |
| `aurora/api` | langcode parameter, translation sub-endpoints, HTTP cache headers |
| `aurora/queue` | Job base class, dispatch helpers, attributes, failed job tracking |
| `aurora/cache` | Tag-based invalidation, tenant/language-aware keys, backend configuration |
| `aurora/ai-vector` | Language-scoped embeddings, cross-language fallback |
| `aurora/ai-pipeline` | Language-aware pipeline steps |
| `aurora/cli` | make:* commands, introspection commands, tinker, telescope CLI |
| `aurora/admin` | Vue 3 + Nuxt SPA, schema-driven forms/lists, i18n, realtime, AI assist |
| `aurora/ssr` | Vue islands, asset integration |
| `aurora/mcp` | Language-aware tools, translation tools |

---

## Architectural Principles (unchanged from v1, reinforced here)

1. **No global state** — all dependencies via constructor injection
2. **Interface-first** — public APIs are interfaces, implementations swappable
3. **In-memory testable** — every subsystem has in-memory implementation
4. **Plugin architecture** — extensibility via attributes, not hooks
5. **Event-driven** — typed domain events replace hooks
6. **Entity-centric** — entities are the gravity center
7. **Strict layered dependency** — downward only, events for upward communication
8. **Dual-state prevention** — one canonical source for every piece of data
9. **YAGNI ruthlessly** — no feature without a concrete use case
10. **Result for domain, exceptions for infrastructure** — two error primitives, clearly delineated
