---
name: waaseyaa:api-layer
description: Use when working with JSON:API endpoints, resource serialization, query parsing, schema presentation, route building, or files in packages/api/, packages/routing/
---

# API Layer Specialist

## Scope

This skill covers the JSON:API and routing packages:

- `packages/api/src/` -- JsonApiController, ResourceSerializer, QueryParser, QueryApplier, SchemaPresenter, SchemaController, TranslationController, BroadcastController, ApiCacheMiddleware, OpenApiGenerator
- `packages/routing/src/` -- WaaseyaaRouter, RouteBuilder, AccessChecker, GateAttribute, EntityParamConverter, RouteMatch
- Front controller wiring in `public/index.php`

Use this skill when:
- Adding or modifying JSON:API endpoints
- Changing resource serialization or field access filtering
- Working with query parsing, filtering, sorting, or pagination
- Modifying JSON Schema presentation or widget hints
- Building or modifying routes and route access control
- Working with SSE broadcasting or API caching
- Generating OpenAPI specs

## Key Interfaces

### JsonApiController (packages/api/src/JsonApiController.php)

```php
final class JsonApiController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?AccountInterface $account = null,
    ) {}

    public function index(string $entityTypeId, array $query = []): JsonApiDocument;
    public function show(string $entityTypeId, int|string $id): JsonApiDocument;
    public function store(string $entityTypeId, array $data): JsonApiDocument;
    public function update(string $entityTypeId, int|string $id, array $data): JsonApiDocument;
    public function destroy(string $entityTypeId, int|string $id): JsonApiDocument;
}
```

Framework-agnostic. Returns `JsonApiDocument` objects. The front controller converts to HTTP responses.

### ResourceSerializer (packages/api/src/ResourceSerializer.php)

```php
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

Excludes entity keys (id, uuid) from attributes. Uses UUID as resource ID when available.

### SchemaPresenter (packages/api/src/Schema/SchemaPresenter.php)

```php
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

Outputs JSON Schema draft-07 with custom `x-widget`, `x-label`, `x-access-restricted` extensions.

### Query Pipeline (packages/api/src/Query/)

```php
// QueryParser parses $_GET into ParsedQuery
$parser = new QueryParser();
$parsedQuery = $parser->parse($query);

// QueryApplier translates ParsedQuery to EntityQuery calls
$applier = new QueryApplier();
$applier->apply($parsedQuery, $entityQuery);
```

Supported filter operators: `=`, `!=`, `>`, `<`, `>=`, `<=`, `CONTAINS`, `STARTS_WITH`.

Default pagination: offset 0, limit 50, max limit 100.

### RouteBuilder (packages/routing/src/RouteBuilder.php)

```php
$route = RouteBuilder::create('/node/{node}')
    ->controller('App\Controller\NodeController::view')
    ->entityParameter('node', 'node')
    ->requirePermission('access content')
    ->methods('GET')
    ->build();
```

### AccessChecker (packages/routing/src/AccessChecker.php)

```php
final class AccessChecker
{
    public function __construct(private readonly ?GateInterface $gate = null) {}
    public function check(Route $route, AccountInterface $account): AccessResult;
}
```

Route access options: `_public`, `_permission`, `_role`, `_gate`. Combined with AND logic.

## Architecture

### Request Flow

1. `public/index.php` receives HTTP request.
2. `SessionMiddleware` sets `_account` on request.
3. `AuthorizationMiddleware` reads `_account` for access checks.
4. `WaaseyaaRouter::match()` resolves path to route + parameters.
5. `AccessChecker::check()` verifies route-level access.
6. `EntityParamConverter::convert()` upcasts entity IDs to loaded entities.
7. Controller method executes, returns `JsonApiDocument`.
8. Front controller sends HTTP response (status code, headers, JSON body).

### Access Checking Layers

Two distinct access layers with different semantics:

1. **Entity level** (`EntityAccessHandler::check()`): Uses `isAllowed()` -- deny unless granted.
2. **Field level** (`EntityAccessHandler::checkFieldAccess()`): Uses `!isForbidden()` -- allow unless denied.

This asymmetry is intentional. Field access is open-by-default: `Neutral = accessible`, only `Forbidden` restricts.

### Paired Nullable Pattern

`ResourceSerializer::serialize()`, `SchemaPresenter::present()`, and `JsonApiController` constructor all accept `?EntityAccessHandler` + `?AccountInterface`. Both must be non-null or both null. The guard pattern:

```php
if ($accessHandler !== null && $account !== null) {
    // perform access filtering
}
```

### readOnly vs x-access-restricted in SchemaPresenter

- `readOnly: true` alone (no `x-access-restricted`): System fields (id, uuid). Admin SPA hides from forms.
- `readOnly: true` + `x-access-restricted: true`: User can view but not edit. Admin SPA shows disabled widget.

### Post-Fetch Access Filtering

`JsonApiController::index()` runs queries with `accessCheck(false)`, then filters results in PHP:

```php
$entities = array_filter(
    $entities,
    fn($entity) => $this->accessHandler->check($entity, 'view', $this->account)->isAllowed(),
);
```

The `total` count in pagination meta reflects the unfiltered count.

### Query Parameter Formats

| Parameter | Format | Example |
|-----------|--------|---------|
| `filter[field]` | Simple equality | `filter[status]=published` |
| `filter[field][operator]` + `filter[field][value]` | Operator filter | `filter[title][operator]=CONTAINS&filter[title][value]=hello` |
| `sort` | Comma-separated, `-` prefix for DESC | `sort=-created,title` |
| `page[offset]` + `page[limit]` | Offset pagination | `page[offset]=20&page[limit]=10` |
| `fields[type]` | Sparse fieldsets | `fields[node]=title,body` |

## Common Mistakes

### Paired nullables -- passing one without the other

```php
// WRONG: accessHandler without account silently skips access filtering
new JsonApiController($etm, $serializer, accessHandler: $handler);

// CORRECT: both must be provided
new JsonApiController($etm, $serializer, accessHandler: $handler, account: $account);
```

### LIKE wildcard escaping

When building `CONTAINS`/`STARTS_WITH` patterns for SQL, user input must be escaped:

```php
// WRONG: user input "100%" matches everything
$pattern = "%{$value}%";

// CORRECT: escape LIKE wildcards in user input
$escapedValue = str_replace(['%', '_'], ['\\%', '\\_'], $value);
$pattern = "%{$escapedValue}%";
```

`PdoSelect` appends `ESCAPE '\'` automatically for LIKE/NOT LIKE operators.

### Entity-level vs field-level access semantics

```php
// Entity level: deny unless granted
$result = $accessHandler->check($entity, 'view', $account);
if (!$result->isAllowed()) { /* deny */ }

// Field level: allow unless denied
$result = $accessHandler->checkFieldAccess($entity, $field, 'edit', $account);
if ($result->isForbidden()) { /* deny */ }
```

Using `isAllowed()` for field access or `isForbidden()` for entity access inverts the logic.

### Avoid double storage create

When checking field access before persisting a new entity, create once and reuse:

```php
// WRONG: creates a throwaway entity, then creates another for save
$temp = $storage->create($attributes);
checkFieldAccess($temp, ...);
$entity = $storage->create($attributes);
$storage->save($entity);

// CORRECT: create once, reuse for both access check and save
$entity = $storage->create($attributes);
checkFieldAccess($entity, ...);
$storage->save($entity);
```

### php://input is single-read

`HttpRequest::createFromGlobals()` consumes `php://input`. For subsequent body reads, use `$httpRequest->getContent()`, not `file_get_contents('php://input')`.

### JSON symmetry

Always pair `json_encode(..., JSON_THROW_ON_ERROR)` with `json_decode(..., JSON_THROW_ON_ERROR)`. Asymmetric usage causes silent `null` on corrupt data.

### Final classes cannot be mocked

All concrete classes in the API layer are `final class`. PHPUnit `createMock()` will fail. Use real instances:

```php
// WRONG:
$serializer = $this->createMock(ResourceSerializer::class);

// CORRECT: use real instances with in-memory storage
$etm = new EntityTypeManager($dispatcher, $storageFactory);
$serializer = new ResourceSerializer($etm);
```

### SchemaController prototype entity

`SchemaController::show()` creates a prototype entity via `new $class([])` for field access checking. This requires the entity subclass constructor to accept `(array $values)`. If the constructor has a different signature, the try-catch logs the error and proceeds without field access annotations.

## Testing Patterns

### In-Memory Storage for API Tests

```php
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Database\PdoDatabase;

// For entity operations
$storage = new InMemoryEntityStorage($entityType);

// For SQL-backed operations (cache, broadcast)
$db = PdoDatabase::createSqlite();  // :memory: database
```

### Testing JsonApiController

```php
$etm = new EntityTypeManager($dispatcher, fn($def) => $storage);
$serializer = new ResourceSerializer($etm);
$controller = new JsonApiController($etm, $serializer);

$doc = $controller->index('node', ['filter' => ['status' => 'published']]);
assert($doc->statusCode === 200);
assert(is_array($doc->data));
```

### Testing Access Filtering

Use anonymous classes implementing intersection types since `createMock()` cannot handle them:

```php
$policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
    public function checkAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult {
        return AccessResult::allowed();
    }
    public function checkFieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult {
        if ($fieldName === 'secret') {
            return AccessResult::forbidden('No access');
        }
        return AccessResult::neutral();
    }
};
```

### Testing Query Pipeline

```php
$parser = new QueryParser();
$parsed = $parser->parse([
    'filter' => ['status' => 'published'],
    'sort' => '-created,title',
    'page' => ['offset' => '10', 'limit' => '25'],
]);

assert(count($parsed->filters) === 1);
assert($parsed->filters[0]->field === 'status');
assert($parsed->sorts[0]->direction === 'DESC');
assert($parsed->offset === 10);
assert($parsed->limit === 25);
```

### Testing RouteBuilder

```php
$route = RouteBuilder::create('/api/node')
    ->controller('Waaseyaa\Api\JsonApiController::index')
    ->methods('GET')
    ->requirePermission('access content')
    ->build();

assert($route->getOption('_permission') === 'access content');
assert($route->getMethods() === ['GET']);
```

### Testing ApiCacheMiddleware

```php
$cache = new ApiCacheMiddleware(schemaMaxAge: 3600);
$doc = JsonApiDocument::fromResource($resource);
$result = $cache->process($doc, 'schema', 'W/"abc123"');
assert(isset($result['headers']['ETag']));
assert(is_bool($result['notModified']));
```

## Related Specs

- `docs/specs/api-layer.md` -- Full API layer specification with interface signatures, value objects, and detailed behavior documentation
- `CLAUDE.md` -- Project-wide gotchas including paired nullables, LIKE escaping, access result semantics, and JSON symmetry
- `docs/plans/2026-03-01-admin-spa-completion.md` -- Admin SPA completion plan including autocomplete search (CONTAINS/STARTS_WITH) and SSE broadcasting
- `docs/plans/2026-02-28-aurora-architecture-v2-design.md` -- Architecture v2 design with API evolution plans
