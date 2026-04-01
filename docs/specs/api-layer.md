# API Layer

<!-- Spec reviewed 2026-04-01 - post-M10 ApiServiceProvider route ownership, package-declared API surfaces, C18 drift remediation (#1017) -->

Technical specification for the Waaseyaa JSON:API layer and routing system. This document covers the `packages/api/` and `packages/routing/` packages, which together provide RESTful CRUD endpoints, resource serialization, query parsing, JSON Schema presentation, route building, and access checking. The current post-M10 baseline uses package-owned service providers for API route registration: `packages/api/composer.json` declares `Waaseyaa\Api\ApiServiceProvider`, and that provider delegates CRUD route registration to `JsonApiRouteProvider` while foundation keeps only shared infrastructure endpoints.

## Packages

### Package-owned route registration

`Waaseyaa\Api\ApiServiceProvider` is declared in `packages/api/composer.json` under `extra.waaseyaa.providers`. Its `routes()` method is the authoritative entry point for JSON:API CRUD route registration and delegates to `JsonApiRouteProvider` when an `EntityTypeManager` is available.

Foundation still owns the shared HTTP surfaces that are not entity-package specific, including `/api/schema/{entity_type}`, `/api/openapi.json`, `/api/entity-types`, discovery endpoints, broadcast, and the SSR catch-all.

### packages/api/

| File | Namespace | Purpose |
|------|-----------|---------|
| `src/JsonApiController.php` | `Waaseyaa\Api` | CRUD operations on entities (index, show, store, update, destroy) |
| `src/ResourceSerializer.php` | `Waaseyaa\Api` | Entity-to-JsonApiResource conversion with field access filtering |
| `src/JsonApiDocument.php` | `Waaseyaa\Api` | JSON:API document value object (data, errors, meta, links, included) |
| `src/JsonApiResource.php` | `Waaseyaa\Api` | JSON:API resource value object (type, id, attributes, relationships) |
| `src/JsonApiError.php` | `Waaseyaa\Api` | JSON:API error value object with static factory methods |
| `src/JsonApiRouteProvider.php` | `Waaseyaa\Api` | Auto-registers five CRUD routes per entity type |
| `src/Query/QueryParser.php` | `Waaseyaa\Api\Query` | Parses `$_GET` into ParsedQuery (filters, sorts, pagination, sparse fieldsets) |
| `src/Query/QueryApplier.php` | `Waaseyaa\Api\Query` | Applies ParsedQuery to EntityQueryInterface |
| `src/Query/QueryFilter.php` | `Waaseyaa\Api\Query` | Value object for a single filter condition |
| `src/Query/QuerySort.php` | `Waaseyaa\Api\Query` | Value object for a single sort directive |
| `src/Query/ParsedQuery.php` | `Waaseyaa\Api\Query` | Value object holding all parsed query components |
| `src/Query/PaginationLinks.php` | `Waaseyaa\Api\Query` | Generates self/first/prev/next pagination URLs |
| `src/Schema/SchemaPresenter.php` | `Waaseyaa\Api\Schema` | Converts EntityType definitions to JSON Schema with widget hints |
| `src/Controller/SchemaController.php` | `Waaseyaa\Api\Controller` | `GET /api/schema/{entity_type}` endpoint |
| `src/Controller/TranslationController.php` | `Waaseyaa\Api\Controller` | Translation sub-resource CRUD endpoints |
| `src/Controller/BroadcastController.php` | `Waaseyaa\Api\Controller` | SSE real-time broadcast endpoint |
| `src/Controller/BroadcastStorage.php` | `Waaseyaa\Api\Controller` | PDO-backed message queue for SSE broadcasting |
| `src/Cache/ApiCacheMiddleware.php` | `Waaseyaa\Api\Cache` | ETag, If-None-Match, Cache-Control header generation |
| `src/OpenApi/OpenApiGenerator.php` | `Waaseyaa\Api\OpenApi` | Generates OpenAPI 3.1 spec from entity type definitions |
| `src/OpenApi/SchemaBuilder.php` | `Waaseyaa\Api\OpenApi` | Builds component schemas for OpenAPI spec |
| `src/MutableTranslatableInterface.php` | `Waaseyaa\Api` | Extension of TranslatableInterface with `addTranslation()` |

### packages/routing/

| File | Namespace | Purpose |
|------|-----------|---------|
| `src/WaaseyaaRouter.php` | `Waaseyaa\Routing` | Wraps Symfony UrlMatcher + UrlGenerator |
| `src/RouteBuilder.php` | `Waaseyaa\Routing` | Fluent API for building Symfony Route objects |
| `src/RouteMatch.php` | `Waaseyaa\Routing` | Value object for matched route (name, route, parameters) |
| `src/AccessChecker.php` | `Waaseyaa\Routing` | Route-level access checking via route options |
| `src/Attribute/GateAttribute.php` | `Waaseyaa\Routing\Attribute` | PHP attribute for gate-based access control on controller methods |
| `src/ParamConverter/EntityParamConverter.php` | `Waaseyaa\Routing\ParamConverter` | Converts route parameter IDs to loaded entity objects |
| `src/Language/LanguageNegotiatorInterface.php` | `Waaseyaa\Routing\Language` | Interface for language negotiation |
| `src/Language/AcceptHeaderNegotiator.php` | `Waaseyaa\Routing\Language` | Language negotiation from Accept-Language header |
| `src/Language/UrlPrefixNegotiator.php` | `Waaseyaa\Routing\Language` | Language negotiation from URL prefix |

## Core Value Objects

### JsonApiDocument

```php
// packages/api/src/JsonApiDocument.php
final readonly class JsonApiDocument
{
    public function __construct(
        public JsonApiResource|array|null $data = null,
        public array $errors = [],
        public array $meta = [],
        public array $links = [],
        public array $included = [],
        public int $statusCode = 200,
    ) {}

    public function toArray(): array;

    // Static factories:
    public static function fromResource(JsonApiResource $resource, array $links = [], array $meta = [], int $statusCode = 200): self;
    public static function fromCollection(array $resources, array $links = [], array $meta = []): self;
    public static function fromErrors(array $errors, array $meta = [], int $statusCode = 400): self;
    public static function empty(array $meta = [], int $statusCode = 200): self;
}
```

`toArray()` always includes `jsonapi.version = "1.1"`. The `data` and `errors` members are mutually exclusive per the JSON:API spec. When `$data` is `null` (e.g., after DELETE), `toArray()` emits `"data": null`.

### JsonApiResource

```php
// packages/api/src/JsonApiResource.php
final readonly class JsonApiResource
{
    public function __construct(
        public string $type,       // entity type ID
        public string $id,         // UUID (preferred) or numeric ID as string
        public array $attributes = [],
        public array $relationships = [],
        public array $links = [],
        public array $meta = [],
    ) {}

    public function toArray(): array;
}
```

### JsonApiError

```php
// packages/api/src/JsonApiError.php
final readonly class JsonApiError
{
    public function __construct(
        public string $status,
        public string $title,
        public string $detail = '',
        public array $source = [],
    ) {}

    public function toArray(): array;

    // Static factories:
    public static function notFound(string $detail = ''): self;      // 404
    public static function forbidden(string $detail = ''): self;     // 403
    public static function unprocessable(string $detail = '', array $source = []): self;  // 422
    public static function badRequest(string $detail = ''): self;    // 400
    public static function conflict(string $detail = ''): self;      // 409
    public static function internalError(string $detail = ''): self; // 500
}
```

## JSON:API Controller

`JsonApiController` is a framework-agnostic PHP class. It receives parsed parameters and returns `JsonApiDocument` objects. The front controller in `public/index.php` handles HTTP concerns (headers, body parsing, status codes).

### Constructor

```php
// packages/api/src/JsonApiController.php
final class JsonApiController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
    ) {}
}
```

The `$accessHandler` and `$account` follow the **paired nullable** pattern: both must be non-null or both null. When both are null, no access checking is performed.

### CRUD Operations

**`index(string $entityTypeId, array $query = []): JsonApiDocument`**

1. Validates entity type exists via `$entityTypeManager->hasDefinition()`.
2. Creates a `QueryParser` and parses `$query` into `ParsedQuery`.
3. Runs a **count query** with filters only (no sorts/pagination) to get total.
4. Runs the **main query** with filters, sorts, and pagination via `QueryApplier`.
5. Loads entities via `$storage->loadMultiple($ids)`.
6. **Post-fetch access filter**: if access handler is available, filters entities where `$accessHandler->check($entity, 'view', $account)->isAllowed()` is false.
7. Serializes via `$serializer->serializeCollection()`.
8. Applies sparse fieldsets if `fields[type]` is in the query.
9. Generates pagination links and meta (`total`, `offset`, `limit`).
10. Returns `JsonApiDocument::fromCollection()`.

**`show(string $entityTypeId, int|string $id, array $query = []): JsonApiDocument`**

1. Loads entity by ID or UUID via `loadByIdOrUuid()`.
2. Checks view access. Returns 403 if denied.
3. Serializes via `$serializer->serialize()`.
4. Applies sparse fieldsets if `fields[type]` is in the query (filters attributes via `array_intersect_key`, matching `index()` behavior).
5. Returns `JsonApiDocument::fromResource()`.

**`store(string $entityTypeId, array $data): JsonApiDocument`**

1. Validates `data.type` matches `$entityTypeId`.
2. Creates entity via `$storage->create($attributes)`.
3. Checks create access via `$accessHandler->checkCreateAccess()`.
4. Checks **field edit access** for each submitted attribute via `$accessHandler->checkFieldAccess($entity, $fieldName, 'edit', $account)`. Uses `isForbidden()` (field-level semantics).
5. Saves entity and returns document with `statusCode: 201` and `meta.created = true`.

**`update(string $entityTypeId, int|string $id, array $data): JsonApiDocument`**

1. Loads entity, validates `data.type` and optional `data.id` (409 Conflict if UUID mismatch).
2. Checks update access at entity level.
3. Checks field edit access for each submitted attribute.
4. Applies updates via `$entity->set($field, $value)` (requires `FieldableInterface`).
5. Saves and returns updated resource.

**`destroy(string $entityTypeId, int|string $id): JsonApiDocument`**

1. Loads entity, checks delete access.
2. Deletes via `$storage->delete([$entity])`.
3. Returns `JsonApiDocument::empty(meta: ['deleted' => true], statusCode: 204)`.

### ID Resolution

`loadByIdOrUuid()` accepts `int|string`. If the entity type has a UUID key and the value matches UUID regex (`/^[0-9a-f]{8}-...-[0-9a-f]{12}$/i`), it queries by UUID. Otherwise it loads by primary key.

## Resource Serialization

```php
// packages/api/src/ResourceSerializer.php
final class ResourceSerializer
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    public function serialize(
        EntityInterface $entity,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): JsonApiResource;

    public function serializeCollection(
        array $entities,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array;
}
```

### Serialization Logic

1. Uses UUID as resource ID if available, otherwise falls back to numeric ID.
2. Calls `$entity->toArray()` for all values.
3. Excludes entity keys (`id`, `uuid`) from attributes -- they become top-level `type`/`id`.
4. When access handler + account are provided, calls `$accessHandler->filterFields($entity, array_keys($attributes), 'view', $account)` to remove view-denied fields.
5. Generates a `self` link: `{basePath}/{entityTypeId}/{resourceId}`.

### Paired Nullable Pattern

`$accessHandler` and `$account` must both be non-null or both null. The guard pattern is:

```php
if ($accessHandler !== null && $account !== null) {
    $allowedFields = $accessHandler->filterFields($entity, array_keys($attributes), 'view', $account);
    $attributes = array_intersect_key($attributes, array_flip($allowedFields));
}
```

Only two of the four possible states (both-null, both-non-null) are meaningful. Passing one without the other silently skips access filtering.

## Schema Presenter

```php
// packages/api/src/Schema/SchemaPresenter.php
final class SchemaPresenter
{
    public function present(
        EntityTypeInterface $entityType,
        array $fieldDefinitions = [],
        ?EntityInterface $entity = null,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array;
}
```

### JSON Schema Output Format

Follows JSON Schema draft-07 with custom extensions:

```json
{
    "$schema": "https://json-schema.org/draft-07/schema#",
    "title": "Content",
    "description": "Schema for Content entities.",
    "type": "object",
    "x-entity-type": "node",
    "x-translatable": false,
    "x-revisionable": false,
    "properties": { ... },
    "required": [ ... ]
}
```

### Custom Extensions

| Extension | Type | Purpose |
|-----------|------|---------|
| `x-widget` | string | Widget type hint for admin SPA (text, textarea, richtext, select, boolean, number, email, url, datetime, entity_autocomplete, image, file, password, hidden) |
| `x-label` | string | Human-readable field label |
| `x-description` | string | Field help text |
| `x-weight` | int | Display order weight |
| `x-required` | bool | Whether field is required in forms |
| `x-access-restricted` | bool | Field is viewable but not editable by current account |
| `x-entity-type` | string | Entity type ID (top-level) |
| `x-translatable` | bool | Whether entity type supports translations (top-level) |
| `x-revisionable` | bool | Whether entity type supports revisions (top-level) |
| `x-target-type` | string | Target entity type for entity_reference fields |
| `x-enum-labels` | object | Human-readable labels for enum values |

### readOnly vs x-access-restricted

These serve different purposes in the admin SPA:

- **`readOnly: true`** (without `x-access-restricted`): System fields like `id`, `uuid`. The admin SPA **hides** these from forms entirely.
- **`readOnly: true` + `x-access-restricted: true`**: The user can **view** the field but cannot **edit** it. The admin SPA shows a disabled widget.

### Field Access Integration

When `$entity`, `$accessHandler`, and `$account` are all non-null:

1. For each non-system field, checks `checkFieldAccess($entity, $fieldName, 'view', $account)`.
2. If `isForbidden()` for view: **removes** the property from the schema entirely.
3. If not forbidden for view, checks `checkFieldAccess($entity, $fieldName, 'edit', $account)`.
4. If `isForbidden()` for edit: marks the property with `readOnly: true` and `x-access-restricted: true`.

System keys (id, uuid, label, bundle, langcode) are always shown as-is.

### Type and Widget Mappings

Field type to JSON Schema type: `string->string`, `text->string`, `boolean->boolean`, `integer->integer`, `float->number`, `decimal->number`, `email->string`, `uri->string`, `timestamp->string`, `entity_reference->string`.

Field type to widget: `string->text`, `text->textarea`, `text_long->richtext`, `boolean->boolean`, `integer->number`, `email->email`, `uri->url`, `timestamp->datetime`, `entity_reference->entity_autocomplete`, `list_string->select`.

Format mappings: `email->email`, `uri->uri`, `timestamp->date-time`, `datetime->date-time`.

### SchemaController

```php
// packages/api/src/Controller/SchemaController.php
final class SchemaController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly SchemaPresenter $schemaPresenter,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
    ) {}

    public function show(string $entityTypeId): JsonApiDocument;
}
```

Creates a prototype entity (`new $class([])`) for field access checking. Wraps in try-catch and logs failures via `error_log()`. Returns the schema in `meta.schema` of a `JsonApiDocument`.

## Query Pipeline

### QueryParser

Parses `$_GET`-style arrays into a `ParsedQuery` value object.

**Supported query parameters:**

| Parameter | Format | Example |
|-----------|--------|---------|
| `filter[field]=value` | Simple equality | `filter[status]=published` |
| `filter[field][operator]=op&filter[field][value]=val` | Operator filter | `filter[title][operator]=CONTAINS&filter[title][value]=hello` |
| `filter[field][operator]=IN&filter[field][value][]=v1&filter[field][value][]=v2` | IN filter (batch lookup) | `filter[uuid][operator]=IN&filter[uuid][value][]=abc-123&filter[uuid][value][]=def-456` |
| `sort=field,-field2` | Comma-separated, `-` prefix for DESC | `sort=-created,title` |
| `page[offset]=N` | Offset-based pagination | `page[offset]=20` |
| `page[limit]=N` | Page size | `page[limit]=10` |
| `fields[type]=field1,field2` | Sparse fieldsets | `fields[node]=title,body` |

### QueryFilter

```php
// packages/api/src/Query/QueryFilter.php
final readonly class QueryFilter
{
    private const VALID_OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'CONTAINS', 'STARTS_WITH', 'IN'];

    public function __construct(
        public string $field,
        public mixed $value,
        public string $operator = '=',
    ) {}
}
```

Throws `InvalidArgumentException` for unsupported operators.

### QuerySort

```php
// packages/api/src/Query/QuerySort.php
final readonly class QuerySort
{
    public function __construct(
        public string $field,
        public string $direction = 'ASC',  // 'ASC' or 'DESC'
    ) {}
}
```

### ParsedQuery

```php
// packages/api/src/Query/ParsedQuery.php
final readonly class ParsedQuery
{
    public function __construct(
        public array $filters = [],           // QueryFilter[]
        public array $sorts = [],             // QuerySort[]
        public ?int $offset = null,
        public ?int $limit = null,
        public array $sparseFieldsets = [],    // array<string, list<string>>
    ) {}
}
```

### QueryApplier

```php
// packages/api/src/Query/QueryApplier.php
final class QueryApplier
{
    private int $defaultLimit = 50;
    private int $maxLimit = 100;

    public function apply(ParsedQuery $query, EntityQueryInterface $entityQuery): EntityQueryInterface;
    public function getEffectiveLimit(ParsedQuery $query): int;
    public function getEffectiveOffset(ParsedQuery $query): int;
    public function getDefaultLimit(): int;
    public function getMaxLimit(): int;
}
```

`apply()` translates each `QueryFilter` to `$entityQuery->condition()`, each `QuerySort` to `$entityQuery->sort()`, and applies `$entityQuery->range($offset, $limit)`. The limit is clamped to `min($requestedLimit, $maxLimit)` with a default of 50.

### PaginationLinks

```php
// packages/api/src/Query/PaginationLinks.php
final class PaginationLinks
{
    public static function generate(string $basePath, int $offset, int $limit, int $total): array;
}
```

Returns `self`, `first`, and optionally `prev` and `next` links. Format: `{basePath}?page[offset]={N}&page[limit]={M}`.

## Post-Fetch Access Filtering

Entity-level access is applied **after** query execution in `JsonApiController::index()`:

```php
if ($this->accessHandler !== null && $this->account !== null) {
    $entities = array_filter(
        $entities,
        fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed(),
    );
}
```

This means:
- The SQL query runs with `accessCheck(false)` -- no access checks in the database layer.
- Entities are loaded, then filtered by view access in PHP.
- The `total` count in pagination meta reflects the **unfiltered** count (from the count query, also with `accessCheck(false)`). This means `total` may be higher than the number of resources returned.

Access result semantics differ by level:
- **Entity level**: uses `isAllowed()` -- deny unless explicitly granted.
- **Field level**: uses `!isForbidden()` -- allow unless explicitly denied (open by default).

## LIKE Wildcard Escaping

The `CONTAINS` and `STARTS_WITH` filter operators are translated to SQL `LIKE` patterns by `SqlEntityQuery` (in `packages/entity-storage/`). There are two important details:

1. **DBALSelect appends `ESCAPE '\'`** for all LIKE/NOT LIKE operators. This means the backslash character is the escape character in LIKE patterns.

2. **User input must be escaped** before embedding in LIKE patterns:

```php
$escapedValue = str_replace(['%', '_'], ['\\%', '\\_'], $value);
// CONTAINS: "%{$escapedValue}%"
// STARTS_WITH: "{$escapedValue}%"
```

Without this escaping, a user submitting `100%` as a filter value would match unintended rows because `%` is a LIKE wildcard.

## IN Filter Operator

The `IN` operator supports batch lookups by matching a field against a list of values. This is primarily used for batch UUID resolution (e.g., loading multiple entities by UUID in a single request).

```
GET /api/node?filter[uuid][operator]=IN&filter[uuid][value][]=550e8400-...&filter[uuid][value][]=6ba7b810-...
```

The `value` parameter must be an array when using `IN`. `QueryParser` passes the array value through to `QueryFilter`, and `QueryApplier` translates it to a SQL `IN (...)` clause via `EntityQueryInterface::condition()`.

## Route Building

### WaaseyaaRouter

```php
// packages/routing/src/WaaseyaaRouter.php
final class WaaseyaaRouter
{
    public function __construct(?RequestContext $context = null);

    public function addRoute(string $name, Route $route): void;
    public function match(string $pathinfo): array;
    public function generate(string $name, array $parameters = []): string;
    public function getRouteCollection(): RouteCollection;
}
```

Wraps Symfony `UrlMatcher` and `UrlGenerator`. Lazy-initializes matchers/generators and resets them when routes change.

### RouteBuilder

Fluent API for building Symfony Route objects:

```php
// packages/routing/src/RouteBuilder.php
$route = RouteBuilder::create('/node/{node}')
    ->controller('App\Controller\NodeController::view')
    ->entityParameter('node', 'node')
    ->requirePermission('access content')
    ->methods('GET')
    ->build();
```

| Method | Route Option | Purpose |
|--------|-------------|---------|
| `controller(string\|callable)` | `_controller` | Sets the controller |
| `methods(string ...)` | (route methods) | Allowed HTTP methods |
| `entityParameter(string $name, string $entityType)` | `parameters[$name] = ['type' => 'entity:{entityType}']` | Entity param upcasting |
| `requirePermission(string $permission)` | `_permission` | Require specific permission |
| `requireRole(string $role)` | `_role` | Require specific role |
| `allowAll()` | `_public = true` | Public route, no auth required |
| `requirement(string $key, string $regex)` | (route requirements) | Regex requirement for parameter |
| `default(string $key, mixed $value)` | (route defaults) | Default parameter value |
| `build()` | -- | Returns configured Symfony Route |

### Route Access Options

Routes declare access requirements via Symfony Route options. These are checked by `AccessChecker`:

| Option | Type | Meaning |
|--------|------|---------|
| `_public` | `true` | Always allow access (no authentication required) |
| `_permission` | `string` | Account must have the named permission |
| `_role` | `string` | Account must have the named role (comma-separated for multiple) |
| `_gate` | `array{ability: string, subject?: mixed}` | Gate ability check |

Multiple requirements are combined with **AND** logic (all must pass). If no access requirements are present, `AccessChecker::check()` returns `AccessResult::neutral()`.

### AccessChecker

```php
// packages/routing/src/AccessChecker.php
final class AccessChecker
{
    public function __construct(
        private readonly ?GateInterface $gate = null,
    ) {}

    public function check(Route $route, AccountInterface $account): AccessResult;
    public static function applyGateToRoute(Route $route, string $ability, mixed $subject = null): void;
}
```

### GateAttribute

PHP attribute for declarative gate checks on controller methods:

```php
// packages/routing/src/Attribute/GateAttribute.php
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class GateAttribute
{
    public function __construct(
        public readonly string $ability,   // e.g., 'config.export'
        public readonly mixed $subject = null,
    ) {}
}
```

### EntityParamConverter

```php
// packages/routing/src/ParamConverter/EntityParamConverter.php
final class EntityParamConverter
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function convert(array $parameters, Route $route): array;
}
```

Reads the route `parameters` option for entries with `type => 'entity:{entityTypeId}'`. Loads the entity from storage and replaces the raw ID in the parameter array. Throws `ResourceNotFoundException` if entity not found.

### JsonApiRouteProvider

```php
// packages/api/src/JsonApiRouteProvider.php
final class JsonApiRouteProvider
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    public function registerRoutes(WaaseyaaRouter $router): void;
}
```

Registers five routes per entity type:

| Route Name | Method | Path | Controller Method |
|-----------|--------|------|-------------------|
| `api.{type}.index` | GET | `/api/{type}` | `index` |
| `api.{type}.show` | GET | `/api/{type}/{id}` | `show` |
| `api.{type}.store` | POST | `/api/{type}` | `store` |
| `api.{type}.update` | PATCH | `/api/{type}/{id}` | `update` |
| `api.{type}.destroy` | DELETE | `/api/{type}/{id}` | `destroy` |

Each route sets `_entity_type` as a default parameter.

## Translation Sub-Resource

```php
// packages/api/src/Controller/TranslationController.php
final class TranslationController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
    ) {}
}
```

| Method | Route | Description |
|--------|-------|-------------|
| `index(entityTypeId, id)` | `GET /api/{type}/{id}/translations` | List translations |
| `show(entityTypeId, id, langcode)` | `GET /api/{type}/{id}/translations/{langcode}` | Get translation |
| `store(entityTypeId, id, langcode, data)` | `POST /api/{type}/{id}/translations/{langcode}` | Create translation |
| `update(entityTypeId, id, langcode, data)` | `PATCH /api/{type}/{id}/translations/{langcode}` | Update translation |
| `destroy(entityTypeId, id, langcode)` | `DELETE /api/{type}/{id}/translations/{langcode}` | Delete translation |

Creating a translation requires `MutableTranslatableInterface`. Deleting the original language returns 422.

## API Cache Middleware

```php
// packages/api/src/Cache/ApiCacheMiddleware.php
final class ApiCacheMiddleware
{
    public function __construct(
        private readonly ?int $entityMaxAge = null,     // default: 0
        private readonly ?int $collectionMaxAge = null,  // default: 0
        private readonly ?int $schemaMaxAge = null,      // default: 3600
        private readonly bool $isPrivate = true,
    ) {}

    public function generateETag(JsonApiDocument $document): string;
    public function isNotModified(string $ifNoneMatch, string $etag): bool;
    public function buildHeaders(JsonApiDocument $document, string $responseType = 'entity'): array;
    public function process(JsonApiDocument $document, string $responseType = 'entity', string $ifNoneMatch = ''): array;
}
```

ETags use `W/"..."` (weak validator) with SHA-256 hash of the serialized response. Supports wildcard and multi-value `If-None-Match`. Returns `Vary: Accept, Accept-Language, Authorization`.

## OpenAPI Generation

```php
// packages/api/src/OpenApi/OpenApiGenerator.php
final class OpenApiGenerator
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private string $basePath = '/api',
        private string $title = 'Waaseyaa API',
        private string $version = '0.1.0',
    ) {}

    public function generate(): array;
}
```

Generates OpenAPI 3.1.0 spec. For each entity type, creates four component schemas (`{Type}Resource`, `{Type}Attributes`, `{Type}CreateRequest`, `{Type}UpdateRequest`) and five path operations. Includes shared schemas for `JsonApiDocument`, `JsonApiErrorDocument`, `JsonApiError`, `JsonApiVersion`, and `JsonApiLinks`.

## Discovery API Handler

`DiscoveryApiHandler` encapsulates logic for discovery endpoints (topic hubs, clusters, timelines, entity endpoint pages). It handles discovery cache primitives, relationship type parsing, entity visibility checks, and cache key building.

### Instantiation Lifecycle

`DiscoveryApiHandler` is instantiated in `HttpKernel::handle()` **after** `boot()` completes and after the cache infrastructure is set up. The creation sequence in `handle()` is:

1. `$this->boot()` — bootstraps providers, entity types, access policies, and the event dispatcher.
2. Cache bins are configured (`render`, `discovery`, `mcp_read`) via `CacheFactory`.
3. `$this->discoveryHandler = new DiscoveryApiHandler(...)` is created with three dependencies:
   - `$this->entityTypeManager` — the fully booted `EntityTypeManager` (available after `boot()`).
   - `$this->database` — the `DatabaseInterface` instance (available after `boot()`).
   - `$this->discoveryCache` — a `CacheBackendInterface` (`DatabaseBackend` backed by the `cache_discovery` table), created moments earlier in the same method.

The handler is stored as `$this->discoveryHandler` on the kernel and subsequently passed to both `SsrPageHandler` and `ControllerDispatcher`.

### Constructor

```php
// packages/api/src/Http/DiscoveryApiHandler.php
final class DiscoveryApiHandler
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?CacheBackendInterface $discoveryCache = null,
    ) {}
}
```

### Key Capabilities

| Method | Purpose |
|--------|---------|
| `parseRelationshipTypesQuery(mixed $value): list<string>` | Normalizes comma-separated string or array query param into a list of relationship type IDs |
| `buildDiscoveryCacheKey(string $surface, string $entityType, string $entityId, array $options): string` | Delegates to `DiscoveryCachePrimitives` to build a deterministic cache key |
| `normalizeForCacheKey(mixed $value): mixed` | Recursively sorts associative array keys for stable cache key generation |
| `getDiscoveryCachedResponse(string $cacheKey, AccountInterface $account): ?array` | Returns cached response for anonymous users; bypasses cache for authenticated users |
| `prepareDiscoveryResponse(int $status, array $payload, string $cacheKey, AccountInterface $account): array` | Returns `[payload, headers]` tuple — caches for anonymous (public, max-age=120), sets `no-store` for authenticated |
| `isDiscoveryEndpointPairPublic(string $fromType, string $fromId, string $toType, string $toId): bool` | Checks both endpoints of a relationship exist and are publicly visible via `WorkflowVisibility` |
| `loadDiscoveryEntity(string $entityType, string $entityId): ?EntityInterface` | Loads an entity by type and ID (resolves numeric strings to int), returns null on any failure |
| `isDiscoveryEntityPublic(string $entityType, array $values): bool` | Delegates to `WorkflowVisibility::isEntityPublic()` for publish-state checking |
| `createDiscoveryService(): RelationshipDiscoveryService` | Factory method — creates a `RelationshipDiscoveryService` with a `RelationshipTraversalService` wired to the handler's entity type manager and database |

### Discovery Cache Strategy

- **Anonymous users**: Responses are cached in the `discovery` cache bin with a 120-second TTL. Cache tags are derived from the payload via `DiscoveryCachePrimitives::buildTags()`. Cached responses include `X-Waaseyaa-Discovery-Cache: MISS` on first generation.
- **Authenticated users**: Cache is bypassed entirely (`Cache-Control: private, no-store`) to ensure fresh, access-aware results.
- Cache invalidation is handled by event listeners registered via `EventListenerRegistrar::registerDiscoveryCacheListeners()`.

## File Reference

```
packages/api/
  src/
    Cache/
      ApiCacheMiddleware.php
    Controller/
      BroadcastController.php
      BroadcastStorage.php
      CodifiedContextController.php
      SchemaController.php
      TranslationController.php
    Http/
      DiscoveryApiHandler.php
    OpenApi/
      OpenApiGenerator.php
      SchemaBuilder.php
    Query/
      PaginationLinks.php
      ParsedQuery.php
      QueryApplier.php
      QueryFilter.php
      QueryParser.php
      QuerySort.php
    Schema/
      SchemaPresenter.php
    JsonApiController.php
    JsonApiDocument.php
    JsonApiError.php
    JsonApiResource.php
    JsonApiRouteProvider.php
    MutableTranslatableInterface.php
    ResourceSerializer.php

packages/routing/
  src/
    Attribute/
      GateAttribute.php
    Language/
      AcceptHeaderNegotiator.php
      LanguageNegotiatorInterface.php
      UrlPrefixNegotiator.php
    ParamConverter/
      EntityParamConverter.php
    AccessChecker.php
    RouteBuilder.php
    RouteMatch.php
    WaaseyaaRouter.php
```
