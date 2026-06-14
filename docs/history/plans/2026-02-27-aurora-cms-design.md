# Aurora CMS v0.1.0 Design Document

**Date:** 2026-02-27
**Status:** Approved
**Starting point:** Drupal 11.2.10 (conceptual fork)
**License:** GPL-2.0-or-later (inherited, accepted)
**Vendor namespace:** `aurora/*`

## Vision

Aurora CMS is a modern, entity-first, AI-native CMS/framework created by treating Drupal 11 as a greenfield project at v0.1.0. It keeps Drupal's powerful subsystems (entity, field, config, plugin) and removes decades of accumulated legacy. The target is a blend of Drupal's entity/config/plugin power, Laravel's developer experience, and AI-native extensibility.

### Design Principles

1. **Entity-first.** Entities, fields, and typed data are the architectural center of gravity.
2. **Headless-first, optional SSR.** JSON:API and GraphQL are first-class. Server-side rendering exists for teams that want it, but the API is the primary interface.
3. **AI-native.** AI is a foundational layer (like entities or plugins), not a bolt-on. Every entity, field, and config object auto-exposes schemas for AI introspection and tool-use.
4. **Plugin-driven.** Extensibility via attribute-based plugins. A clean plugin/package story so a new ecosystem can form.
5. **Symfony-native.** Built on Symfony 7.3 components. No custom replacements for what Symfony already provides well.
6. **Modular by default.** The CMS is composed from independent Composer packages. Use the whole CMS, or just the entity engine.
7. **Small security surface.** Fewer subsystems, fewer legacy paths, clearer boundaries. Every removal is a security improvement.

### Non-Goals

- Backward compatibility with Drupal contrib modules.
- Migration path from existing Drupal sites (greenfield only for v0.1.0).
- Full admin UI parity with Drupal (minimal viable admin for v0.1.0).
- Internationalization in v0.1.0 (optional package, ships later).

---

## Architecture Overview

### Fork Strategy: Modular Decomposition

Start with Drupal 11.2.10 as the codebase. Aggressively decompose it into independent Composer packages during the prune. Each package gets a clean public API (facade). The CMS is composed from these packages.

### Layer Diagram

```
LAYER 0 - Foundation (zero Aurora dependencies)
  aurora/typed-data    Type system, PHP-native facade
  aurora/plugin        Attribute-based plugin discovery + managers
  aurora/cache         Cache bins + cache tag invalidation

LAYER 1 - Core Data (the hard core)
  aurora/config           Config as YAML, package-aware ownership
  aurora/entity           Entity types, interfaces, lifecycle, NO storage
  aurora/field            Field types, definitions, formatters
  aurora/entity-storage   Pluggable persistence (v0.1.0: SQL, future: Doctrine)

LAYER 2 - Services
  aurora/access        Permissions, roles, entity-aware access control
  aurora/user          User entity, authentication, sessions
  aurora/routing       Symfony Routing + param upcasting + access
  aurora/queue         Background jobs via Symfony Messenger
  aurora/state         State API + KeyValue storage
  aurora/validation    Entity-aware validation on Symfony Validator

LAYER 3 - Content Types (optional, ship in v0.1.0)
  aurora/node          Content entities (simplified from Drupal)
  aurora/taxonomy      Vocabularies + terms
  aurora/media         Media entities + file storage
  aurora/path          URL aliases as entities
  aurora/menu          Menu link entities
  aurora/workflows     State machines for entities

LAYER 4 - API Surface
  aurora/api           JSON:API + auto-generated OpenAPI 3.1
  aurora/graphql       GraphQL endpoint, auto-generated from definitions

LAYER 5 - AI
  aurora/ai-schema     JSON Schema / OpenAPI / MCP tool defs from entity/field/config
  aurora/ai-agent      AI agent plugin type + built-in MCP server
  aurora/ai-vector     Embedding + vector search, pluggable backends
  aurora/ai-pipeline   Content pipelines (ingest, transform, classify, validate)

LAYER 6 - Interfaces
  aurora/ssr           Optional Twig Components SSR (no render arrays)
  aurora/admin         Schema-driven SPA (React/Vue/Svelte), separate JS package
  aurora/cli           Artisan-style console commands

META-PACKAGES
  aurora/core          Layers 0-2. The engine.
  aurora/cms           Layers 0-4 + admin + CLI. A complete headless CMS.
  aurora/full          Everything including AI + SSR.
```

### Dependency Rule

Dependencies flow strictly downward. No circular dependencies. If a higher layer needs to influence a lower layer, it does so through events or narrow interfaces — never by the lower layer depending on the higher one.

### External Dependencies (not ours to build)

| Need | Package |
|------|---------|
| DI Container | `symfony/dependency-injection` |
| Events | `symfony/event-dispatcher` |
| HTTP Kernel | `symfony/http-kernel` + `http-foundation` |
| Routing (base) | `symfony/routing` |
| Validation (base) | `symfony/validator` |
| Console | `symfony/console` |
| Mailer | `symfony/mailer` |
| Queues | `symfony/messenger` |
| Scheduler | `symfony/scheduler` |
| Filesystem | `symfony/filesystem` |
| YAML parsing | `symfony/yaml` |
| Serialization (base) | `symfony/serializer` |
| Database (target) | `doctrine/dbal` |
| Schema migrations (target) | `doctrine/migrations` |
| Logging | `monolog/monolog` |
| Templating | `twig/twig` |

---

## Drupal 11 Teardown: Keep / Replace / Remove

### KEEP (Core Assets)

| System | Drupal Source | Aurora Package | Notes |
|--------|-------------|----------------|-------|
| Entity system | `Core\Entity`, `Core\TypedData` | `aurora/entity`, `aurora/typed-data` | The crown jewel. No other PHP framework matches this. |
| Field system | `Core\Field`, `field` module | `aurora/field` | Pluggable field types, storage, formatters. |
| Config system | `Core\Config`, `config` module | `aurora/config` | Config as YAML, import/export, deployable. Rework ownership model for packages. |
| Plugin system | `Core\Plugin`, `Component\Plugin` | `aurora/plugin` | Attribute-based discovery only (no annotations). |
| Cache system | `Core\Cache` | `aurora/cache` | Cache bins + cache tags. Best-in-class invalidation. |
| Routing | `Core\Routing` | `aurora/routing` | Symfony Routing with param upcasting and access. |
| DI Container | `Core\DependencyInjection` | Symfony DI directly | Drupal's compiler passes, simplified. |
| Access/Permissions | `Core\Access` | `aurora/access` | Granular, entity-aware. Remove node grants. |
| Validation | `Core\Validation` | `aurora/validation` | Symfony Validator with entity-aware constraints. |
| Queue | `Core\Queue` | `aurora/queue` | Migrate to Symfony Messenger. |
| State + KeyValue | `Core\State`, `Core\KeyValueStore` | `aurora/state` | Simple mutable storage. |
| JSON:API | `jsonapi` module | `aurora/api` | Spec-compliant, zero-config. Enhance with OpenAPI. |
| Serialization | `serialization` module | Part of `aurora/api` | Drupal-aware Symfony Serializer. |
| User | `user` module | `aurora/user` | Auth, sessions, roles, permissions. |
| Node | `node` module | `aurora/node` | Simplified. No node grants, no node access table. |
| Taxonomy | `taxonomy` module | `aurora/taxonomy` | Vocabularies + terms. |
| File + Media | `file`, `media` modules | `aurora/media` | File handling, media entities. |
| Path Alias | `path_alias` module | `aurora/path` | Clean URL management. |
| Workflows | `workflows`, `content_moderation` modules | `aurora/workflows` | State machines for entities. |
| Image | `image` module | Part of `aurora/media` | Image styles and derivatives. |
| Datetime | `datetime`, `datetime_range` modules | Field type plugins in `aurora/field` | Date field types. |
| Link, Telephone, Text, Options | Various modules | Field type plugins in `aurora/field` | Core field types as plugins, not modules. |

### REPLACE

| Current | Replacement | Aurora Package | Rationale |
|---------|------------|----------------|-----------|
| Database layer (`Core\Database`, `mysql`/`pgsql`/`sqlite` modules) | Doctrine DBAL | `aurora/database-legacy` (v0.1.0), `aurora/database-doctrine` (v0.2.0+) | Industry standard, better tooled, more backends. Staged migration via `DatabaseInterface` adapter. |
| Form API (`Core\Form`) | Symfony Forms (backend) + SPA forms (admin) | Removed from core, Symfony Forms available | Render arrays for forms are the worst Drupal DX. |
| Render arrays (`Core\Render`) | Twig Components (SSR) or API responses | `aurora/ssr` (optional) | Render arrays are the single biggest complexity source. |
| Hook system (`Core\Hook`) | Events + Attributes | Symfony EventDispatcher | D11 started this. Aurora finishes it. |
| Theme system (`Core\Theme`, all core themes) | Twig Components | `aurora/ssr` (optional) | No preprocessing, no template suggestions, no theme hooks. |
| Views engine (`views` module) | Keep query engine, remove UI | Query engine in `aurora/entity`, UI in `aurora/admin` | Views query builder is powerful. UI belongs in SPA. |
| Asset/Library system (`Core\Asset`) | Vite | `aurora/admin` uses Vite | Drupal's YAML library system is dated. |
| Mail (`Core\Mail`) | Symfony Mailer | Direct Symfony Mailer usage | Use it directly, not through Drupal's wrapper. |
| Installer (`Core\Installer`, all profiles) | CLI installer | `aurora/cli` | `aurora install` command. No web wizard. |
| Update system (`update` module, `Core\Update`) | Doctrine Migrations | `aurora/entity-storage` | Versioned migration classes, not `hook_update_N`. |
| Logging (`dblog`, `syslog`) | Monolog | Symfony standard | PSR-3 with configurable handlers. |
| Search (`search` module) | Vector search + traditional | `aurora/ai-vector` | AI-native search as core service. |
| Batch (`Core\Batch`) | Queue workers | `aurora/queue` via Symfony Messenger | No batch page with progress bar. |
| Cron (`automated_cron`) | Symfony Scheduler | Symfony standard | Real scheduled tasks, not page-request triggered. |

### REMOVE (No Replacement Needed)

**Modules (40 of 74):**

| Module | Why it's removed |
|--------|-----------------|
| `announcements_feed` | Marketing content in admin. |
| `ban` | IP banning belongs in infrastructure (WAF/CDN). |
| `big_pipe` | Optimizes render array streaming. No render arrays = no BigPipe. |
| `block` / `block_content` | Block placement is a theme-layer concept. Content blocks become regular entities if needed. |
| `breakpoint` | Theme-layer responsive config. Frontend handles this. |
| `ckeditor5` | WYSIWYG belongs in SPA admin as a JS dependency, not a PHP module. |
| `comment` | Not core CMS functionality. Plugin/package. |
| `contact` | Not core. A form. |
| `contextual` | In-place editing tied to theme layer. |
| `dynamic_page_cache` / `page_cache` | API caching via HTTP headers + CDN. |
| `editor` | Server-side WYSIWYG framework. SPA handles this. |
| `field_layout` | Layout Builder dependency. |
| `field_ui` | Replaced by SPA admin's schema-driven entity management. |
| `filter` | Text format/filter pipeline. Replace with simple sanitization + markdown. |
| `help` | Drupal's help page system. Documentation lives externally. |
| `history` | "New content" marker tracking. Niche. |
| `inline_form_errors` | Form API specific. |
| `language` / `locale` / `content_translation` / `config_translation` | i18n is important but massively complex. Optional package post-v0.1.0. |
| `layout_builder` / `layout_discovery` | Depends on render arrays, blocks, theme system. Visual page building belongs in SPA admin later. |
| `media_library` | Media selection UI. SPA replacement. |
| `menu_ui` | Menu management UI. SPA replacement. |
| `migrate` / `migrate_drupal` / `migrate_drupal_ui` | Migration from old Drupal. Irrelevant for greenfield. |
| `navigation` | D11 admin sidebar. Replaced by SPA. |
| `package_manager` | Composer integration UI. Use Composer directly. |
| `phpass` | Legacy password hashing. Use `password_hash()` natively. |
| `responsive_image` | Theme-layer responsive images. Frontend responsibility. |
| `rest` | Redundant when JSON:API is primary. |
| `sdc` | Single Directory Components. Twig Components replacement supersedes. |
| `settings_tray` | Theme-layer off-canvas settings. |
| `shortcut` | Admin bookmark shortcuts. SPA handles this. |
| `toolbar` | Admin toolbar. SPA replacement. |
| `update` | "Check for updates" module. Use Composer. |
| `views_ui` | Views config UI. SPA replacement. |
| `workspaces` / `workspaces_ui` | Experimental, deeply coupled. Revisit post-v0.1.0. |

**All core themes:** claro, olivero, stark, stable9, starterkit_theme. No theme system = no themes.

**All profiles:** standard, minimal, demo_umami. CLI installer replaces profiles.

**Core subsystems removed:**

| Subsystem | Reason |
|-----------|--------|
| `Core\Ajax` | Tied to Form API's AJAX framework. |
| `Core\Annotation` | Replaced by PHP 8 Attributes. |
| `Core\Batch` | Replaced by queue workers. |
| `Core\Block` | Block placement tied to theme regions. |
| `Core\Breadcrumb` | Theme-layer concept. |
| `Core\Display` | Entity display modes tied to render/theme. Replace with API-driven field selection. |
| `Core\Executable` | Condition/action system. Simplify or remove. |
| `Core\FileTransfer` | Legacy FTP/SSH transfer for updates. |
| `Core\Form` | Entire Form API. |
| `Core\Installer` | Web installer. |
| `Core\Locale` | Translation management. Optional package. |
| `Core\PageCache` | Server-rendered page cache. |
| `Core\Pager` | Pagination rendering. API uses cursor/offset. |
| `Core\Render` | The entire render pipeline. |
| `Core\Template` | Template processing. |
| `Core\Theme` | The entire theme system. |
| `Core\Update` | `hook_update_N` system. |
| `Core\Updater` | Module updater. |

---

## Deep Design: Entity + Storage

### Entity Interfaces (aurora/entity)

The entity package defines shapes and behavior. It has no knowledge of databases, SQL, or persistence.

```php
namespace Aurora\Entity;

interface EntityInterface
{
    public function id(): int|string|null;
    public function uuid(): string;
    public function label(): string;
    public function getEntityTypeId(): string;
    public function bundle(): string;
    public function isNew(): bool;
    public function toArray(): array;
}

interface ContentEntityInterface
    extends EntityInterface, FieldableInterface {}

interface RevisionableInterface
{
    public function getRevisionId(): int|string|null;
    public function isDefaultRevision(): bool;
    public function isLatestRevision(): bool;
}

interface TranslatableInterface
{
    public function language(): string;
    public function getTranslationLanguages(): array;
    public function hasTranslation(string $langcode): bool;
    public function getTranslation(string $langcode): static;
}

interface FieldableInterface
{
    public function hasField(string $name): bool;
    public function get(string $name): FieldItemListInterface;
    public function set(string $name, mixed $value): static;
    public function getFieldDefinitions(): array;
}

interface ConfigEntityInterface extends EntityInterface
{
    public function status(): bool;
    public function getDependencies(): array;
    public function toConfig(): array;
}
```

### Storage Interfaces (aurora/entity-storage)

Storage interfaces live in `aurora/entity` (so higher packages can type-hint them). Implementations live in `aurora/entity-storage`.

```php
namespace Aurora\Entity\Storage;

interface EntityStorageInterface
{
    public function create(array $values = []): EntityInterface;
    public function load(int|string $id): ?EntityInterface;
    public function loadMultiple(array $ids = []): array;
    public function save(EntityInterface $entity): int;
    public function delete(array $entities): void;
    public function getQuery(): EntityQueryInterface;
}

interface RevisionableStorageInterface extends EntityStorageInterface
{
    public function loadRevision(int|string $revisionId): ?EntityInterface;
    public function loadMultipleRevisions(array $ids): array;
    public function deleteRevision(int|string $revisionId): void;
    public function getLatestRevisionId(int|string $entityId): int|string|null;
}

interface EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $op = '='): static;
    public function exists(string $field): static;
    public function notExists(string $field): static;
    public function sort(string $field, string $dir = 'ASC'): static;
    public function range(int $offset, int $limit): static;
    public function count(): static;
    public function accessCheck(bool $check = true): static;
    public function execute(): array;
}
```

### What the Interfaces Deliberately Exclude (vs Drupal)

| Drupal concept | Aurora decision | Rationale |
|---------------|-----------------|-----------|
| `TableMappingInterface` | Not in public API | Exposes SQL structure. Storage-internal. |
| `SqlContentEntityStorageSchema` | Not in public API | Schema management is the impl's problem. |
| `FieldStorageDefinitionInterface` | Merged into `FieldDefinitionInterface` | Eliminates storage concern leakage. Field type plugin declares properties; storage impl decides persistence. |
| `hook_entity_storage_load` | Event: `EntityEvents::POST_LOAD` | Typed event, not a hook. |
| `hook_schema` | Not exposed | Storage implementations manage their own schema. |
| `$entity->original` | Event payload on `PreSave` | Old and new state passed in event, not cached on entity. |

### Revisions and Translations: Simplification

| Concern | Drupal | Aurora |
|---------|--------|--------|
| Entity object | Holds all translations internally. `getTranslation()` switches active language. | Represents ONE language at a time. Translation is a storage concern. |
| Revisions | Entity knows its revision state AND storage details. | Entity knows revision state only (id, isDefault, isLatest). Storage manages timeline. |
| Storage schema | 4 tables: base, data, revision, revision_data. Leaks into entity query. | Storage decides representation. Public API is `loadRevision()`, `getTranslation()`. That's it. |
| Field values | `FieldItemList` holds all language variants. Developer must call `getTranslation()` first. | `FieldItemList` holds values for current language only. No hidden state. |
| Access model | Per-field translation access. Revision + translation access matrix. | Entity-level access + simple language permission. No per-field translation bypass. |

**v0.1.0 pragmatic reality:** Internally, `SqlEntityStorage` will still use Drupal's 4-table pattern. It works, it's tested, it's correct. But the public interface hides this completely. Future Doctrine-based storage can use a different schema without any change to consuming code.

### Database Seam

```
aurora/entity-storage (v0.1.0)
    │
    ├── SqlEntityStorage (main implementation)
    │       uses ↓
    ├── DatabaseInterface (thin abstraction, narrow surface)
    │       │
    │       ├── DrupalDatabaseAdapter (v0.1.0)
    │       │       wraps Drupal\Core\Database\Connection
    │       │       ships in: aurora/database-legacy
    │       │
    │       └── DoctrineDatabaseAdapter (v0.2.0+)
    │               wraps Doctrine\DBAL\Connection
    │               ships in: aurora/database-doctrine
    │
    └── Adapter swap = composer require change, not rewrite.
```

```php
namespace Aurora\Database;

interface DatabaseInterface
{
    public function select(string $table, string $alias): SelectInterface;
    public function insert(string $table): InsertInterface;
    public function update(string $table): UpdateInterface;
    public function delete(string $table): DeleteInterface;
    public function schema(): SchemaInterface;
    public function transaction(): TransactionInterface;
}
```

Intentionally narrow. Supports only the operations entity storage needs. Makes the Doctrine adapter straightforward — small surface to map.

---

## Deep Design: API + AI Schema

### aurora/api — JSON:API + OpenAPI

Drupal's JSON:API module is already close to what Aurora needs. It auto-discovers entity types and creates spec-compliant endpoints. Aurora enhances it with auto-generated OpenAPI 3.1 specs.

**Auto-generated OpenAPI at `GET /api/openapi.json`:**

Every entity type, bundle, and field definition produces:
- Endpoint paths for CRUD operations
- Request/response schemas with field types, constraints, descriptions
- Relationship definitions (JSON:API links)
- Authentication requirements
- Filter, sort, and pagination parameters

Add a field to an entity type and the OpenAPI spec updates automatically. Zero manual API documentation.

### aurora/ai-schema — The Introspection Layer

This package takes entity/field/config definitions and produces AI-consumable schemas:

```
Entity Type + Field Definitions + Config Schemas
                    │
            aurora/ai-schema
                    │
         ┌──────────┼──────────┐
         ↓          ↓          ↓
   OpenAPI 3.1   JSON Schema  MCP Tool
   (endpoints)   (data shapes) Definitions
```

**MCP tool definitions are auto-generated from CMS state.** Add a content type and a new tool appears. Add a field and the tool's input schema updates. This is what "AI-native" means: the CMS teaches AI agents how to use it, automatically.

### One Schema, Three Consumers

The admin SPA, AI agents, and external consumers all see the SAME schema. There is no separate "admin API" or "AI API." One API surface, one schema, one permission model.

- **Admin SPA** uses the schema to auto-generate forms, lists, and navigation.
- **AI agents** use the schema as MCP tool definitions to know what they can do and what shape data takes.
- **External developers** read the OpenAPI spec and generate client SDKs.

This eliminates an entire class of inconsistency bugs and security gaps.

### AI Agent Plugin Type

```php
namespace Aurora\AI\Agent;

#[AuroraPlugin(
    id: 'content_classifier',
    label: 'Content Classifier',
    description: 'Classifies content into taxonomy terms on save',
)]
class ContentClassifierAgent implements AgentInterface
{
    #[OnEvent(EntityEvents::POST_SAVE)]
    public function onEntitySave(EntityEvent $event): void
    {
        // Agent logic: classify entity, assign terms
    }
}
```

Agents are plugins. They subscribe to events. They operate within the permission model. They support dry-run mode. They are discovered via attributes and managed via the plugin system — the same infrastructure that manages field types, access policies, and everything else.

### AI Safety Model

- AI agents operate as a specific user (or as a system user with defined permissions).
- Same access control as human users: entity access, field access, config permissions.
- Dry-run mode: agent proposes changes as a diff, human approves.
- Guardrails: rate limiting, content validation, reversible-only operations by default.
- Audit trail: all agent actions logged with agent identity.

---

## Deep Design: Interfaces (SSR + Admin)

### aurora/ssr — Optional Server Rendering

The SSR package is a leaf node. Nothing depends on it. If you remove it, nothing breaks.

**Dependencies:** `aurora/entity`, `aurora/field`, `aurora/routing`, `twig/twig`.
**Depended on by:** Nothing.

**How it works:**

Controllers return Symfony `Response` objects, not render arrays. Components are PHP classes paired with Twig templates. No preprocessing, no template suggestions, no theme registry.

```php
#[Route('/article/{node}', name: 'article.view')]
class ArticleController
{
    public function __construct(
        private readonly ComponentRenderer $renderer,
    ) {}

    public function __invoke(NodeInterface $node): Response
    {
        return $this->renderer->render($node);
    }
}

#[Component(name: 'article', template: 'components/article.html.twig')]
class ArticleComponent
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $imageUrl,
        public readonly array $tags,
        public readonly \DateTimeInterface $date,
    ) {}

    public static function fromEntity(ContentEntityInterface $entity): static
    {
        return new static(
            title: $entity->get('title')->value,
            body: $entity->get('body')->value,
            imageUrl: $entity->get('field_image')->entity?->getFileUri(),
            tags: array_map(
                fn($item) => $item->entity->label(),
                iterator_to_array($entity->get('field_tags')),
            ),
            date: $entity->get('created')->date,
        );
    }
}
```

**What this eliminates vs Drupal:**

| Drupal concept | Aurora equivalent |
|---------------|-------------------|
| Render arrays | Gone. Components receive typed data. |
| `hook_preprocess_*` | `Component::fromEntity()` static factory. |
| Template suggestions | Component class hierarchy or explicit selection. |
| Theme registry | Gone. PHP attribute discovery. |
| `drupal_render()` | Gone. Twig renders components directly. |
| `#attached` libraries | Vite handles assets. |
| Regions + blocks | Page layout is a Twig layout template. |

### aurora/admin — Schema-Driven SPA

The admin is a separate JavaScript package. Zero PHP coupling. Communicates entirely via HTTP.

**Startup flow:**
1. Fetch OpenAPI spec → knows all entity types, bundles, fields.
2. Auto-generate navigation, list views, form schemas from spec.
3. Fetch MCP tool definitions → expose AI assistant in UI.
4. All CRUD via JSON:API. Same endpoints as any external consumer.
5. Authentication via session cookie or JWT.

**Schema-driven forms:** No form is hand-coded. Field types declare their UI widget via the schema. The SPA has a widget registry mapping JSON Schema types to UI components. Add a custom field type, declare its widget schema, admin renders it.

**AI augmentation:** "AI: Write" button sends entity schema + context to AI agent via MCP. Agent returns structured content matching the schema. Admin populates the form. User reviews before saving. Same permission model: AI operates as the logged-in user.

---

## Security Surface Reduction

| Area | Drupal | Aurora | Security Benefit |
|------|--------|--------|-----------------|
| Rendering | Render arrays, theme preprocess, template suggestions, #attached, BigPipe | Twig Components or API responses | Eliminates template injection, #attached XSS vectors |
| Forms | Form API with CSRF tokens, AJAX, #states, validation callbacks, submit handlers | Symfony Forms (backend) or SPA forms (admin) | CSRF handled by SPA auth. Declarative validation. |
| Entity access | Node grants table, `hook_node_access`, per-field access, per-operation matrix | Entity-level access policies. Per-field via definitions. No grants table. | Grants table was a persistent vulnerability source. |
| Translations | 4-table storage. Per-field translatability. Translation access matrix. | Entity = one language. Simple language permission check. | No per-field translation bypass. |
| Config | Module-owned. Complex dependency resolution. Install/uninstall hooks execute code. | Package-owned. Composer-based dependencies. No code execution on install. | Eliminates module install exploit surface. |
| Admin | Server-rendered PHP with 100+ routes. Form API everywhere. | SPA consuming public API. Identical attack surface to any API consumer. | No admin-specific exploit surface. |
| Database | Custom DBAL, custom query builder, custom schema manager | Doctrine DBAL (eventually). Dedicated security team, more eyeballs. | Industry-standard security posture. |
| Hooks | Allow arbitrary code injection at runtime | Typed events. Traceable, debuggable. | No runtime code injection. |
| Text processing | Filter/text format system with input format chains | Simple sanitization + markdown | Classic XSS vector eliminated. |
| Installer | Web-based wizard (install.php left accessible) | CLI only | Eliminates entire vulnerability class. |

---

## Config System: Package-Aware Ownership

### Current Drupal Model
- Each config object is "owned" by a module (e.g., `system.site` owned by `system` module).
- Uninstalling a module deletes its config.
- Config dependencies reference module names.

### Aurora Model
- Each config object is owned by a Composer package (e.g., `system.site` owned by `aurora/core`).
- Config dependencies reference package names + version constraints.
- Removing a package triggers config cleanup via Composer scripts (not hook_uninstall).
- Config import validates against installed packages and their versions.

```yaml
# Config file header in Aurora
_aurora:
  package: aurora/node
  version: ^0.1
  dependencies:
    - aurora/entity: ^0.1
    - aurora/field: ^0.1
```

Config sync understands Composer's dependency graph. `composer remove aurora/taxonomy` triggers cleanup of taxonomy-owned config. No more orphaned config from forgotten module uninstalls.

---

## Roadmap

### v0.1.0 — Foundation (target: working headless CMS)

- Decompose Drupal core into aurora/* packages (Layers 0-2).
- Ship content types: node, taxonomy, media, path, menu (Layer 3).
- Ship JSON:API with auto-generated OpenAPI (Layer 4).
- Ship `aurora/ai-schema` with MCP tool generation (Layer 5).
- Ship minimal SPA admin covering content CRUD, entity management, config editing (Layer 6).
- Ship CLI installer and basic console commands (Layer 6).
- Entity storage: Drupal's SQL storage behind clean interfaces.
- Database: `aurora/database-legacy` wrapping Drupal's DBAL.
- Hooks fully replaced by events + attributes.
- No i18n (English only).
- No render arrays, no Form API, no theme system.

### v0.2.0 — AI + Database Migration

- Ship `aurora/ai-agent` with full MCP server.
- Ship `aurora/ai-vector` with embedding + semantic search.
- Ship `aurora/ai-pipeline` with content pipelines.
- Introduce `aurora/database-doctrine` adapter.
- Begin migrating entity storage to Doctrine DBAL.
- AI-augmented admin: content generation, classification, schema scaffolding.

### v0.3.0 — SSR + Ecosystem

- Ship `aurora/ssr` with Twig Components.
- Ship `aurora/graphql` endpoint.
- Plugin/package development documentation and SDK.
- Encourage early ecosystem packages.

### v0.4.0 — i18n + Maturity

- Ship optional i18n package (simplified from Drupal's).
- Refinements based on real-world usage.
- Performance optimization.
- Security audit.

### v1.0.0 — Stable

- Doctrine DBAL as default database adapter.
- Stable public APIs with semver guarantees.
- Comprehensive documentation.
- TypedData simplified toward PHP-native typing.
- Production-ready security posture.
