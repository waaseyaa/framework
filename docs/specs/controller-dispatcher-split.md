# Controller Dispatcher Split

## Status
Proposed — implements #571 (ControllerDispatcher Split P1 milestone)

## Problem
`ControllerDispatcher` is 1,001 lines with a 638-line `match(true)` statement routing 18 controller cases across 10 domains. Its constructor takes 11 parameters. This violates SRP, resists testing, and couples every domain to a single dispatch point.

## Architecture

### Principle
Routers represent **domains**, not controllers. Each router is a sealed unit with its own invariants, lifecycle, and test surface. Symfony is the HTTP layer; Waaseyaa owns dispatching.

### Dispatch Flow (after refactoring)

```
HttpKernel::serveHttpRequest()
  → populate Request attributes (_account, _broadcast_storage, _parsed_body, _waaseyaa_context)
  → ControllerDispatcher::dispatch(Request)
      → callable check (closures/invokables from service providers)
      → foreach DomainRouterInterface: supports() → handle()
      → 404 fallback (no router matched)
```

## Contract Layer

### DomainRouterInterface

```php
namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface DomainRouterInterface
{
    public function supports(Request $request): bool;
    public function handle(Request $request): Response;
}
```

`supports()` inspects the full Request. The primary discriminator is `_controller` (string, set by WaaseyaaRouter), but routers may also inspect `_route_object`, HTTP method, or route metadata for future flexibility.

`handle()` receives a fully-populated Request with all context in attributes.

### Request Attributes Contract

Set by HttpKernel before dispatch:

| Attribute | Type | Source |
|---|---|---|
| `_controller` | `string` | WaaseyaaRouter (existing) |
| `_account` | `AccountInterface` | SessionMiddleware (existing) |
| `_route_object` | `Route` | Symfony routing (existing) |
| `_broadcast_storage` | `BroadcastStorage` | HttpKernel (NEW: move from dispatch param) |
| `_parsed_body` | `?array` | HttpKernel (NEW: move JSON parsing from ControllerDispatcher) |
| `_waaseyaa_context` | `WaaseyaaContext` | HttpKernel (NEW: built once, read by routers) |

### WaaseyaaContext

Typed, validated view of raw Request attributes. Built once by HttpKernel, set as `_waaseyaa_context`. Routers read it directly; no repeated resolution.

```php
namespace Waaseyaa\Foundation\Http\Router;

final class WaaseyaaContext
{
    public function __construct(
        public readonly AccountInterface $account,
        public readonly ?array $parsedBody,
        public readonly array $query,
        public readonly string $method,
        public readonly BroadcastStorage $broadcastStorage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            account: $request->attributes->get('_account'),
            parsedBody: $request->attributes->get('_parsed_body'),
            query: $request->query->all(),
            method: $request->getMethod(),
            broadcastStorage: $request->attributes->get('_broadcast_storage'),
        );
    }
}
```

**Not in the context object:**
- `projectRoot`, `config` — constructor-injected into routers that need them (MediaRouter, etc.)
- `graphqlMutationOverrides` — constructor-injected into GraphQlRouter only
- Route params — already on `$request->attributes` via Symfony routing

## Router Breakdown (10 routers)

| # | Router | `supports()` discriminator | Current match cases absorbed | Key dependencies |
|---|---|---|---|---|
| 1 | JsonApiRouter | `str_contains($ctrl, 'JsonApiController')` | `*JsonApiController` (index, show, store, update, destroy) | EntityTypeManager, EntityAccessHandler, DatabaseInterface |
| 2 | EntityTypeLifecycleRouter | `$ctrl === 'entity_types'` or `str_starts_with($ctrl, 'entity_type.')` | `entity_types`, `entity_type.disable`, `entity_type.enable` | EntityTypeManager, EntityTypeLifecycleManager |
| 3 | SchemaRouter | `$ctrl === 'openapi'` or `str_contains($ctrl, 'SchemaController')` | `openapi`, `*SchemaController` | EntityTypeManager, EntityAccessHandler |
| 4 | DiscoveryRouter | `str_starts_with($ctrl, 'discovery.')` or `str_contains($ctrl, 'ApiDiscoveryController')` | `discovery.topic_hub`, `discovery.cluster`, `discovery.timeline`, `discovery.endpoint`, `*ApiDiscoveryController` | DiscoveryApiHandler |
| 5 | SearchRouter | `$ctrl === 'search.semantic'` | `search.semantic` | EmbeddingProviderFactory, DatabaseInterface, config |
| 6 | MediaRouter | `$ctrl === 'media.upload'` | `media.upload` | config (upload limits), projectRoot |
| 7 | GraphQlRouter | `$ctrl === 'graphql.endpoint'` | `graphql.endpoint` | EntityTypeManager, EntityAccessHandler, graphqlMutationOverrides |
| 8 | McpRouter | `$ctrl === 'mcp.endpoint'` | `mcp.endpoint` | EntityTypeManager, EntityAccessHandler, CacheBackendInterface |
| 9 | SsrRouter | `$ctrl === 'render.page'` | `render.page` | SsrPageHandler |
| 10 | BroadcastRouter | `$ctrl === 'broadcast'` | `broadcast` (SSE stream) | — (reads BroadcastStorage from context) |

### Domain Rationale

- **JsonApi vs EntityTypeLifecycle**: CRUD is runtime data; lifecycle is platform configuration. Different invariants, different growth trajectories (schema evolution, entity type versioning).
- **Schema + OpenAPI**: Both are schema-level introspection sharing type resolution and field metadata concerns.
- **Discovery vs Search**: Discovery is the capabilities surface; search is cross-domain with its own caching, ranking, and embedding invariants. Search will grow (filters, facets, hybrid search).
- **BroadcastRouter**: The SSE endpoint is a routed HTTP endpoint. Broadcast *emission* remains a side-effect in event listeners, not a router concern.

## Kernel Dispatcher (rewritten ControllerDispatcher)

```php
final class ControllerDispatcher
{
    use JsonApiResponseTrait;

    /** @param iterable<DomainRouterInterface> $routers */
    public function __construct(
        private readonly iterable $routers,
        private readonly array $config = [],
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function dispatch(Request $request): Response
    {
        $controller = $request->attributes->get('_controller', '');

        // Callable controllers (closures/invokables from service providers)
        if (is_callable($controller)) {
            return $this->handleCallable($controller, $request);
        }

        // Domain router chain (deterministic, first match wins)
        try {
            foreach ($this->routers as $router) {
                if ($router->supports($request)) {
                    return $router->handle($request);
                }
            }
        } catch (\Throwable $e) {
            // Error wrapper with debug-aware responses
            return $this->handleException($e);
        }

        // No router matched — routing miss, not a server error
        $this->logger?->warning(sprintf('Unknown controller: %s', $controller));
        return $this->jsonApiResponse(404, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '404',
                'title' => 'Not Found',
                'detail' => sprintf("No router supports controller '%s'.", $controller),
            ]],
        ]);
    }
}
```

**Target size:** ~80 lines (down from 1,001).

**Private methods (not shown above):**
- `handleCallable(callable, Request): Response` — the existing callable type coercion logic (SsrResponse, InertiaResponse, RedirectResponse, array, raw value), moved verbatim from the current `dispatch()` preamble.
- `handleException(\Throwable): Response` — the existing debug-aware error wrapper, moved verbatim from the current `dispatch()` catch block.

**What stays in the kernel:**
- Callable controller fallback with SsrResponse/InertiaResponse/RedirectResponse type coercion
- Top-level try-catch with debug-aware error responses
- `JsonApiResponseTrait` (shared by kernel and routers)

**Router ordering:** Deterministic via the `$routers` iterable, ordered by HttpKernel. Exact-match routers (MCP, GraphQL, SSR, Broadcast, Search, Media) before substring-match routers (JsonApi, Schema, Discovery, EntityTypeLifecycle).

## HttpKernel Changes

HttpKernel's `serveHttpRequest()` method changes:

1. **Before:** Instantiates `ControllerDispatcher` with 11 constructor params, calls `dispatch()` with 6 params.
2. **After:** Populates Request attributes, instantiates `ControllerDispatcher` with `iterable $routers`, calls `dispatch(Request)`.

```php
// Populate request attributes (before dispatch)
$broadcastStorage = new BroadcastStorage($this->database);
$request->attributes->set('_broadcast_storage', $broadcastStorage);
$request->attributes->set('_parsed_body', $this->parseJsonBody($request));
$request->attributes->set('_waaseyaa_context', WaaseyaaContext::fromRequest($request));

// Build routers (each owns its dependencies)
$routers = [
    new McpRouter($this->entityTypeManager, $this->accessHandler, $this->mcpReadCache),
    new GraphQlRouter($this->entityTypeManager, $this->accessHandler, $gqlOverrides),
    new SsrRouter($this->ssrPageHandler),
    new BroadcastRouter(),
    new SearchRouter($this->config, $this->database),
    new MediaRouter($this->projectRoot, $this->config, $this->entityTypeManager),
    new EntityTypeLifecycleRouter($this->entityTypeManager, $this->lifecycleManager),
    new SchemaRouter($this->entityTypeManager, $this->accessHandler),
    new DiscoveryRouter($this->discoveryHandler),
    new JsonApiRouter($this->entityTypeManager, $this->accessHandler, $this->database),
];

$dispatcher = new ControllerDispatcher($routers, $this->config, $this->logger);
return $dispatcher->dispatch($request);
```

JSON body parsing moves to a private `parseJsonBody(Request): ?array` method on HttpKernel (the existing logic from ControllerDispatcher, unchanged).

## File Layout

```
packages/foundation/src/Http/
    ControllerDispatcher.php           ← rewritten as thin delegator (~80 lines)
    JsonApiResponseTrait.php           ← unchanged (shared by kernel + routers)
    Router/
        DomainRouterInterface.php
        WaaseyaaContext.php
        BroadcastRouter.php
        DiscoveryRouter.php
        EntityTypeLifecycleRouter.php
        GraphQlRouter.php
        JsonApiRouter.php
        McpRouter.php
        MediaRouter.php
        SchemaRouter.php
        SearchRouter.php
        SsrRouter.php
```

Namespace: `Waaseyaa\Foundation\Http\Router\`

## Testing Strategy

### Existing tests
- `ControllerDispatcherTest` (14 tests) — media upload helpers (MIME validation, filename sanitization, file URL building) move to `MediaRouterTest`. The single dispatch test is rewritten to verify router chain delegation.

### New tests per router
Each router gets its own test class verifying:
1. `supports()` returns true for its controller identifiers, false for others
2. `handle()` produces correct Response for valid requests
3. Error cases return appropriate JSON:API error responses

### Integration tests
Existing integration tests (McpControllerTest, HttpKernelTest, JsonApiControllerTest) should pass without modification since behavior is unchanged.

## Acceptance Criteria (from #571)
- [ ] ControllerDispatcher reduced to a delegator that routes to domain routers
- [ ] Each domain router is a separate class (<200 lines)
- [ ] DomainRouterInterface with `supports(Request): bool` and `handle(Request): Response`
- [ ] No behavior change — all existing routes work identically
- [ ] All existing tests pass (McpControllerTest, HttpKernelTest, JsonApiControllerTest)

## Related Issues
- #572: extract LanguageResolver from SsrPageHandler — SsrRouter provides a clean boundary for this
- #573: extract ParameterValidator from RelationshipDiscoveryService — DiscoveryRouter isolates this
- #598: replace instanceof checks in API layer with strategy pattern — router chain IS the strategy pattern
- #643: continue AbstractKernel extraction — HttpKernel changes here reduce AbstractKernel coupling
