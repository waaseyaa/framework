# Middleware Pipeline

Waaseyaa implements typed middleware pipelines for three execution contexts: HTTP requests, domain events, and background jobs. Each pipeline uses the onion pattern with separate, type-safe interface pairs. Middleware is discovered via PHP 8 attributes and compiled into sorted stacks.

## Packages

| Package | Role | Key files |
|---------|------|-----------|
| `packages/foundation/` | Interfaces, pipeline classes, `AsMiddleware` attribute, `PackageManifestCompiler` | `src/Middleware/`, `src/Attribute/AsMiddleware.php`, `src/Discovery/` |
| `packages/routing/` | `AccessChecker`, `RouteBuilder` (route option helpers) | `src/AccessChecker.php`, `src/RouteBuilder.php` |
| `packages/user/` | `SessionMiddleware` (resolves `_account` from PHP session) | `src/Middleware/SessionMiddleware.php` |
| `packages/access/` | `AuthorizationMiddleware` (enforces route-level access) | `src/Middleware/AuthorizationMiddleware.php` |

## Three Typed Pipeline Interfaces

Each pipeline context has a paired middleware interface and handler interface. They are structurally identical but type-safe to prevent cross-pipeline wiring.

### HTTP

```
packages/foundation/src/Middleware/HttpMiddlewareInterface.php
packages/foundation/src/Middleware/HttpHandlerInterface.php
packages/foundation/src/Middleware/HttpPipeline.php
```

```php
// Namespace: Waaseyaa\Foundation\Middleware
interface HttpMiddlewareInterface {
    public function process(Request $request, HttpHandlerInterface $next): Response;
}
interface HttpHandlerInterface {
    public function handle(Request $request): Response;
}
```

- `Request` = `Symfony\Component\HttpFoundation\Request`
- `Response` = `Symfony\Component\HttpFoundation\Response`
- Returns a `Response` -- the HTTP pipeline produces a value.

### Event

```
packages/foundation/src/Middleware/EventMiddlewareInterface.php
packages/foundation/src/Middleware/EventHandlerInterface.php
packages/foundation/src/Middleware/EventPipeline.php
```

```php
// Namespace: Waaseyaa\Foundation\Middleware
interface EventMiddlewareInterface {
    public function process(DomainEvent $event, EventHandlerInterface $next): void;
}
interface EventHandlerInterface {
    public function handle(DomainEvent $event): void;
}
```

- `DomainEvent` = `Waaseyaa\Foundation\Event\DomainEvent` (abstract, extends Symfony `Event`)
- Returns `void` -- event dispatch is side-effect-only.

### Job

```
packages/foundation/src/Middleware/JobMiddlewareInterface.php
packages/foundation/src/Middleware/JobHandlerInterface.php
packages/foundation/src/Middleware/JobPipeline.php
```

```php
// Namespace: Waaseyaa\Foundation\Middleware
interface JobMiddlewareInterface {
    public function process(Job $job, JobHandlerInterface $next): void;
}
interface JobHandlerInterface {
    public function handle(Job $job): void;
}
```

- `Job` = `Waaseyaa\Queue\Job`
- Returns `void` -- job execution is side-effect-only.

## Handler Interface Naming Convention

Handler interfaces follow `{Type}HandlerInterface`. Middleware interfaces follow `{Type}MiddlewareInterface`.

| Pipeline | Handler interface | Middleware interface |
|----------|-------------------|---------------------|
| HTTP | `HttpHandlerInterface` | `HttpMiddlewareInterface` |
| Event | `EventHandlerInterface` | `EventMiddlewareInterface` |
| Job | `JobHandlerInterface` | `JobMiddlewareInterface` |

All six interfaces live in `Waaseyaa\Foundation\Middleware` namespace. The design document references `JobNextHandlerInterface` but the implemented interface is `JobHandlerInterface` -- use the actual name from the codebase.

## Onion Pattern

Each pipeline class (`HttpPipeline`, `EventPipeline`, `JobPipeline`) wraps a stack of middleware around a final handler. Execution order is outer-to-inner going in, inner-to-outer coming back.

### How it works

1. The pipeline receives an ordered array of middleware and a final handler.
2. It iterates in **reverse** over the middleware array.
3. Each middleware is wrapped in an anonymous class implementing the handler interface, creating a chain.
4. The outermost wrapper is called first; it calls `$next->handle()` to proceed inward.

### HttpPipeline implementation (canonical reference)

```php
// File: packages/foundation/src/Middleware/HttpPipeline.php
final class HttpPipeline
{
    /** @param HttpMiddlewareInterface[] $middleware */
    public function __construct(private readonly array $middleware = []) {}

    public function withMiddleware(HttpMiddlewareInterface $middleware): self
    {
        return new self([...$this->middleware, $middleware]);
    }

    public function handle(Request $request, HttpHandlerInterface $finalHandler): Response
    {
        if ($this->middleware === []) {
            return $finalHandler->handle($request);
        }
        $handler = $finalHandler;
        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class($mw, $next) implements HttpHandlerInterface {
                public function __construct(
                    private readonly HttpMiddlewareInterface $middleware,
                    private readonly HttpHandlerInterface $next,
                ) {}
                public function handle(Request $request): Response {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        return $handler->handle($request);
    }
}
```

Key details:
- `HttpPipeline` is immutable. `withMiddleware()` returns a new instance.
- Empty middleware array short-circuits directly to the final handler.
- `EventPipeline` and `JobPipeline` follow the same pattern but return `void`.

### Execution order example

Given middleware `[A, B]` added in order, execution proceeds:

```
A::process() enters
  B::process() enters
    finalHandler::handle() executes
  B::process() exits
A::process() exits
```

A middleware can short-circuit by returning a response without calling `$next->handle()`.

## HTTP Pipeline Chain

The production HTTP pipeline in `public/index.php` wires two middleware in this order:

```
SessionMiddleware -> AuthorizationMiddleware -> final handler
```

### Wiring code (from public/index.php)

```php
$pipeline = (new HttpPipeline())
    ->withMiddleware(new SessionMiddleware($userStorage))
    ->withMiddleware(new AuthorizationMiddleware($accessChecker));

$authResponse = $pipeline->handle(
    $httpRequest,
    new class implements HttpHandlerInterface {
        public function handle(HttpRequest $request): HttpResponse {
            return new HttpResponse('', 200);
        }
    },
);
```

### SessionMiddleware

**File:** `packages/user/src/Middleware/SessionMiddleware.php`
**Namespace:** `Waaseyaa\User\Middleware`
**Implements:** `HttpMiddlewareInterface`

Behavior:
1. Reads `$_SESSION['waaseyaa_uid']` (or `$request->attributes->get('_session')` for testability).
2. Loads `User` entity via `EntityStorageInterface::load($uid)`.
3. Falls back to `AnonymousUser` if uid is null, user not found, or storage throws.
4. Sets `AccountInterface` instance on `$request->attributes->set('_account', $account)`.
5. Calls `$next->handle($request)`.

This middleware always calls the next handler. It never short-circuits.

### AuthorizationMiddleware

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php`
**Namespace:** `Waaseyaa\Access\Middleware`
**Implements:** `HttpMiddlewareInterface`

Behavior:
1. Reads `Route` from `$request->attributes->get('_route_object')`. If null, passes through.
2. Reads `AccountInterface` from `$request->attributes->get('_account')`. If missing/invalid, returns 403.
3. Delegates to `AccessChecker::check($route, $account)`.
4. If `$result->isForbidden()`, returns 403 JSON:API response.
5. Otherwise (allowed or neutral), calls `$next->handle($request)`.

This middleware can short-circuit with a 403 response.

### Pre-pipeline steps in index.php

CORS handling and route matching happen **before** the pipeline runs. The matched `Route` object is set on `$request->attributes->set('_route_object', $matchedRoute)` before the pipeline starts. This is required because `AuthorizationMiddleware` reads it from the request.

## Middleware Discovery

### AsMiddleware attribute

**File:** `packages/foundation/src/Attribute/AsMiddleware.php`

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsMiddleware
{
    public function __construct(
        public readonly string $pipeline,   // 'http', 'event', or 'job'
        public readonly int $priority = 0,  // Higher = runs first
    ) {}
}
```

Usage on a middleware class:

```php
#[AsMiddleware(pipeline: 'http', priority: 100)]
final class TenantResolverMiddleware implements HttpMiddlewareInterface { ... }
```

### PackageManifestCompiler

**File:** `packages/foundation/src/Discovery/PackageManifestCompiler.php`

The compiler scans all `Waaseyaa\\*` classes in the Composer classmap for `AsMiddleware` attributes. Discovered middleware is stored in the `PackageManifest::$middleware` property, keyed by pipeline name:

```php
// PackageManifest::$middleware type
array<string, list<array{class: string, priority: int}>>
```

Example compiled manifest entry:

```php
'middleware' => [
    'http' => [
        ['class' => 'Waaseyaa\\...\\TenantResolverMiddleware', 'priority' => 100],
        ['class' => 'Waaseyaa\\...\\LanguageNegotiatorMiddleware', 'priority' => 90],
    ],
    'event' => [
        ['class' => 'Waaseyaa\\...\\TenantScopeMiddleware', 'priority' => 100],
    ],
],
```

Middleware stacks are sorted by priority descending (`$b['priority'] <=> $a['priority']`). Higher priority runs first (outermost in the onion).

### Cached artifact

Written to `storage/framework/packages.php` by `PackageManifestCompiler::compileAndCache()`. Uses atomic write-to-temp-then-rename pattern to prevent partial reads.

## Route Options for Access Control

Routes declare access requirements via Symfony Route options. `AccessChecker` reads these at runtime.

| Option | Type | Meaning |
|--------|------|---------|
| `_public` | `bool` | If `true`, skip all access checks. Anyone can access. |
| `_permission` | `string` | Require `$account->hasPermission($permission)` to return `true`. |
| `_role` | `string` | Comma-separated role list. Account must have at least one. |
| `_gate` | `array{ability: string, subject?: mixed}` | Delegates to `GateInterface::allows()`. |

**Combination logic:** Multiple options are combined with AND. All must pass.

**No requirements:** If no options are set, `AccessChecker` returns `AccessResult::neutral()`. The `AuthorizationMiddleware` treats neutral as "pass through" (open-by-default).

### RouteBuilder helpers

```php
// File: packages/routing/src/RouteBuilder.php
RouteBuilder::create('/api/nodes')
    ->requirePermission('access content')  // sets _permission option
    ->requireRole('editor')                // sets _role option
    ->allowAll()                           // sets _public = true
    ->build();
```

## php://input Single-Read Constraint

`HttpRequest::createFromGlobals()` consumes `php://input`. The stream cannot be read again.

**Rule:** After creating the Symfony `Request` object, always use `$httpRequest->getContent()` to read the request body. Never call `file_get_contents('php://input')` afterward.

This is visible in `public/index.php`:

```php
$httpRequest = HttpRequest::createFromGlobals();
// ... later, in the dispatch section:
$raw = $httpRequest->getContent();  // Correct: reads from the Request object
```

## Built-in HTTP Middleware

All HTTP middleware implement `HttpMiddlewareInterface` and use `#[AsMiddleware(pipeline: 'http', priority: N)]` for auto-discovery. Higher priority runs first (outer onion layer).

| Priority | Class | Package | Purpose |
|----------|-------|---------|---------|
| 100 | `SecurityHeadersMiddleware` | foundation | CSP, X-Frame-Options, HSTS. Constructor: `(string $csp, bool $hstsEnabled, int $hstsMaxAge)` |
| 90 | `CompressionMiddleware` | foundation | gzip compression for responses above minimum size. Constructor: `(int $minimumSize = 1024)` |
| 80 | `RateLimitMiddleware` | foundation | IP-based rate limiting via `RateLimiterInterface`. Constructor: `(RateLimiterInterface, int $maxAttempts = 60, int $windowSeconds = 60)` |
| 70 | `BodySizeLimitMiddleware` | foundation | Rejects payloads over max bytes (413). Constructor: `(int $maxBytes = 1_048_576)` |
| 60 | `RequestLoggingMiddleware` | foundation | Logs method, URI, status, duration. Constructor: `(?Closure $logger = null)` |
| 50 | `ETagMiddleware` | foundation | ETag generation + 304 Not Modified for GET/HEAD |
| 40 | `BearerAuthMiddleware` | user | JWT and API key auth via Bearer header. Constructor: `(EntityStorageInterface, string $jwtSecret, array $apiKeys, ?LoggerInterface)` |
| — | `SessionMiddleware` | user | Resolves `AccountInterface` from session |
| — | `AuthorizationMiddleware` | access | Route-level access enforcement via `AccessChecker` |

## File Reference

### Interfaces (packages/foundation/src/Middleware/)

| File | Interface |
|------|-----------|
| `HttpMiddlewareInterface.php` | `process(Request, HttpHandlerInterface): Response` |
| `HttpHandlerInterface.php` | `handle(Request): Response` |
| `EventMiddlewareInterface.php` | `process(DomainEvent, EventHandlerInterface): void` |
| `EventHandlerInterface.php` | `handle(DomainEvent): void` |
| `JobMiddlewareInterface.php` | `process(Job, JobHandlerInterface): void` |
| `JobHandlerInterface.php` | `handle(Job): void` |

### Pipeline classes (packages/foundation/src/Middleware/)

| File | Class |
|------|-------|
| `HttpPipeline.php` | `HttpPipeline` -- immutable, `withMiddleware()` returns new instance |
| `EventPipeline.php` | `EventPipeline` -- same pattern, returns `void` |
| `JobPipeline.php` | `JobPipeline` -- same pattern, returns `void` |

### Discovery (packages/foundation/)

| File | Class |
|------|-------|
| `src/Attribute/AsMiddleware.php` | `AsMiddleware` -- `#[Attribute]` with `pipeline` and `priority` |
| `src/Discovery/PackageManifestCompiler.php` | `PackageManifestCompiler` -- scans classes, compiles manifest |
| `src/Discovery/PackageManifest.php` | `PackageManifest` -- typed DTO with `$middleware` property |

### Concrete middleware

| File | Class | Pipeline |
|------|-------|----------|
| `packages/user/src/Middleware/SessionMiddleware.php` | `SessionMiddleware` | HTTP |
| `packages/access/src/Middleware/AuthorizationMiddleware.php` | `AuthorizationMiddleware` | HTTP |

### Access checking (packages/routing/)

| File | Class |
|------|-------|
| `src/AccessChecker.php` | `AccessChecker` -- reads `_public`, `_permission`, `_role`, `_gate` from Route options |
| `src/RouteBuilder.php` | `RouteBuilder` -- fluent API with `requirePermission()`, `requireRole()`, `allowAll()` |

### Front controller

| File | Role |
|------|------|
| `public/index.php` | Wires CORS, route matching, `HttpPipeline` (Session + Auth), dispatch |

### Tests

| File | Coverage |
|------|----------|
| `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php` | SessionMiddleware unit tests |
| `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php` | AuthorizationMiddleware unit tests |
| `tests/Integration/Phase11/AuthorizationPipelineTest.php` | Full pipeline integration (Session + Auth + final handler) |
