---
name: waaseyaa:middleware-pipeline
description: Use when working with HTTP middleware, event middleware, job middleware, pipeline compilation, middleware discovery, or files in packages/foundation/src/Middleware/, packages/routing/, packages/user/src/Middleware/, packages/access/src/Middleware/, public/index.php
---

# Middleware Pipeline Specialist

## Scope

This skill covers:
- The three typed middleware pipelines (HTTP, Event, Job) and their interface pairs
- The onion execution pattern implemented by `HttpPipeline`, `EventPipeline`, `JobPipeline`
- Middleware discovery via `#[AsMiddleware]` attribute and `PackageManifestCompiler`
- The HTTP authorization chain (`SessionMiddleware` -> `AuthorizationMiddleware`)
- Route-level access control via route options and `AccessChecker`
- The front controller wiring in `public/index.php`

## Key Interfaces

All interfaces live in namespace `Waaseyaa\Foundation\Middleware` within `packages/foundation/src/Middleware/`.

### HTTP pipeline

```php
// packages/foundation/src/Middleware/HttpMiddlewareInterface.php
interface HttpMiddlewareInterface {
    public function process(Request $request, HttpHandlerInterface $next): Response;
}

// packages/foundation/src/Middleware/HttpHandlerInterface.php
interface HttpHandlerInterface {
    public function handle(Request $request): Response;
}
```

- `Request` = `Symfony\Component\HttpFoundation\Request`
- `Response` = `Symfony\Component\HttpFoundation\Response`

### Event pipeline

```php
// packages/foundation/src/Middleware/EventMiddlewareInterface.php
interface EventMiddlewareInterface {
    public function process(DomainEvent $event, EventHandlerInterface $next): void;
}

// packages/foundation/src/Middleware/EventHandlerInterface.php
interface EventHandlerInterface {
    public function handle(DomainEvent $event): void;
}
```

- `DomainEvent` = `Waaseyaa\Foundation\Event\DomainEvent`

### Job pipeline

```php
// packages/foundation/src/Middleware/JobMiddlewareInterface.php
interface JobMiddlewareInterface {
    public function process(Job $job, JobHandlerInterface $next): void;
}

// packages/foundation/src/Middleware/JobHandlerInterface.php
interface JobHandlerInterface {
    public function handle(Job $job): void;
}
```

- `Job` = `Waaseyaa\Queue\Job`

### Discovery attribute

```php
// packages/foundation/src/Attribute/AsMiddleware.php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsMiddleware {
    public function __construct(
        public readonly string $pipeline,   // 'http', 'event', or 'job'
        public readonly int $priority = 0,  // Higher = runs first (outermost)
    ) {}
}
```

### Access checking

```php
// packages/routing/src/AccessChecker.php
final class AccessChecker {
    public function __construct(private readonly ?GateInterface $gate = null) {}
    public function check(Route $route, AccountInterface $account): AccessResult;
}
```

Route options read by `AccessChecker`: `_public` (bool), `_permission` (string), `_role` (string), `_gate` (array).

## Architecture

### Pipeline construction pattern

Pipelines are immutable. `withMiddleware()` returns a new instance:

```php
$pipeline = (new HttpPipeline())
    ->withMiddleware(new SessionMiddleware($userStorage))
    ->withMiddleware(new AuthorizationMiddleware($accessChecker));

$response = $pipeline->handle($request, $finalHandler);
```

Middleware added first runs outermost (first in, first to execute). The `array_reverse()` inside the pipeline builds the onion from inside out.

### Onion execution order

Given `[A, B]`:
```
A::process() ->
  B::process() ->
    finalHandler::handle()
  <- B returns
<- A returns
```

A middleware can short-circuit by returning without calling `$next->handle()`.

### HTTP authorization chain in public/index.php

The front controller wires the pipeline in this order:

1. **CORS handling** (pre-pipeline, bare headers)
2. **Route matching** (pre-pipeline, sets `_route_object` on request)
3. **HttpPipeline** with `SessionMiddleware` then `AuthorizationMiddleware`
4. **Dispatch** to controllers (post-pipeline)

SessionMiddleware always sets `_account` on the request. AuthorizationMiddleware reads both `_route_object` and `_account` to enforce access. If authorization fails, a 403 response is returned and dispatch never runs.

### Access result semantics

- Route with no access options: `AccessChecker` returns `AccessResult::neutral()` -> AuthorizationMiddleware passes through (open-by-default)
- Route with `_public => true`: always allowed
- Route with `_permission`: checks `$account->hasPermission()`
- Route with `_role`: checks intersection of required roles and account roles
- Route with `_gate`: delegates to `GateInterface::allows()`
- Multiple options combine with AND logic

### Middleware discovery flow

1. `PackageManifestCompiler` scans Composer classmap for `Waaseyaa\\*` classes
2. Classes with `#[AsMiddleware]` attribute are collected with their `pipeline` and `priority`
3. Stacks are sorted by priority descending (`$b['priority'] <=> $a['priority']`)
4. Compiled into `storage/framework/packages.php` (atomic write)
5. `PackageManifest::$middleware` type: `array<string, list<array{class: string, priority: int}>>`

## Common Mistakes

### 1. Wrong handler interface name for jobs

The design document references `JobNextHandlerInterface` but the actual implementation uses `JobHandlerInterface`. Always use the name from `packages/foundation/src/Middleware/JobHandlerInterface.php`.

### 2. php://input double-read

`HttpRequest::createFromGlobals()` consumes `php://input`. After that call, you must use `$httpRequest->getContent()` to read the body. Never call `file_get_contents('php://input')` after `createFromGlobals()`.

```php
// WRONG
$httpRequest = HttpRequest::createFromGlobals();
$raw = file_get_contents('php://input'); // empty string!

// CORRECT
$httpRequest = HttpRequest::createFromGlobals();
$raw = $httpRequest->getContent();
```

### 3. Naming convention violations

Handler interfaces must be `{Type}HandlerInterface`, middleware interfaces must be `{Type}MiddlewareInterface`. Do not use generic names like `MiddlewareInterface` or `HandlerInterface`.

### 4. Circular dependency between access and user packages

`AccountInterface` lives in the access package. `AnonymousUser` and `User` live in the user package. Access must not depend on user. Middleware that needs an account must type-hint `AccountInterface`, never `AnonymousUser` or `User` directly. `AuthorizationMiddleware` correctly depends on `AccountInterface` only.

### 5. Final classes cannot be mocked

All pipeline and middleware classes are `final class`. PHPUnit `createMock()` will fail on them. In tests, use real instances. For handlers, use anonymous classes implementing the interface:

```php
$next = new class implements HttpHandlerInterface {
    public function handle(Request $request): Response {
        return new Response('ok', 200);
    }
};
```

### 6. Missing _route_object on request

`AuthorizationMiddleware` reads `$request->attributes->get('_route_object')`. If this is not set, it passes through without checking access. In tests, you must explicitly set it:

```php
$route = new Route('/api/node');
$route->setOption('_permission', 'access content');
$request->attributes->set('_route_object', $route);
```

### 7. Confusing entity-level and route-level access semantics

Route-level: `AuthorizationMiddleware` uses `$result->isForbidden()` to decide. Neutral means pass through (open-by-default). This is different from entity-level access which uses `$result->isAllowed()` (deny-by-default).

### 8. Middleware ordering matters

SessionMiddleware must run before AuthorizationMiddleware because authorization reads `_account` which session sets. The pipeline preserves insertion order -- first `withMiddleware()` call is outermost.

### 9. Using error_log not psr/log

This project does not use `psr/log`. Both `SessionMiddleware` and `AuthorizationMiddleware` use `error_log()` for logging failures. Follow this pattern in new middleware.

## Testing Patterns

### Unit testing a middleware

Create the middleware, a mock or real handler, and a Symfony `Request`. Capture state via anonymous handler classes:

```php
// From packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php
$capturedAccount = null;
$next = new class($capturedAccount) implements HttpHandlerInterface {
    public function __construct(private ?AccountInterface &$ref) {}
    public function handle(Request $request): Response {
        $this->ref = $request->attributes->get('_account');
        return new Response('ok');
    }
};
$middleware->process($request, $next);
$this->assertInstanceOf(AnonymousUser::class, $capturedAccount);
```

### Integration testing the full pipeline

Use `PdoDatabase::createSqlite()` for in-memory storage, real `User` entities, and the actual pipeline:

```php
// From tests/Integration/Phase11/AuthorizationPipelineTest.php
$pipeline = (new HttpPipeline())
    ->withMiddleware(new SessionMiddleware($userStorage))
    ->withMiddleware(new AuthorizationMiddleware($accessChecker));

$route = new Route('/api/node');
$route->setOption('_permission', 'access content');
$request = Request::create('/api/node');
$request->attributes->set('_route_object', $route);

$response = $pipeline->handle($request, $successHandler);
$this->assertSame(403, $response->getStatusCode());
```

### Setting session data in tests

Do not call `session_start()` in tests. Set session data via request attributes:

```php
$request->attributes->set('_session', ['waaseyaa_uid' => 42]);
```

`SessionMiddleware` reads `$request->attributes->get('_session')` first, falling back to `$_SESSION`.

### Testing route access options

Create a bare `Symfony\Component\Routing\Route` and set options directly:

```php
$route = new Route('/api/node');
$route->setOption('_permission', 'access content');  // permission-protected
$route->setOption('_public', true);                   // public route
$route->setOption('_role', 'admin,editor');            // role-protected
```

### Test file locations

| Test file | Covers |
|-----------|--------|
| `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php` | `SessionMiddleware` |
| `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php` | `AuthorizationMiddleware` |
| `tests/Integration/Phase11/AuthorizationPipelineTest.php` | Full Session + Auth pipeline |

### PHPUnit attributes

Unit tests use `#[CoversClass(TargetClass::class)]`. Integration tests use `#[CoversNothing]`. All test methods use `#[Test]` attribute.

## Related Specs

- `docs/specs/middleware-pipeline.md` -- full specification with interface signatures and file reference
- `docs/plans/2026-03-01-laravel-integration-layer-design.md` -- original design document for middleware pipelines
- `docs/plans/2026-03-01-authorization-wiring-design.md` -- design for Session + Authorization middleware wiring
