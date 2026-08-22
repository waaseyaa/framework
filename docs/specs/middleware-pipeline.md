# Middleware Pipeline
<!-- Spec reviewed 2026-08-16 - #2150: ResponseCacheControlMiddleware is the outermost HTTP response-policy layer. SessionMiddleware marks every stateful request and disables PHP's independent session cache limiter before startup; after all inner cookie writers unwind, the response layer replaces any cache policy with private, no-store when the request is session-bound or the Symfony response carries Set-Cookie. Cookie-free stateless responses preserve their public SSR policy. -->
<!-- Spec reviewed 2026-08-21 - #2494 FrankenPHP worker mode includes auto_prepend_file once per process, not once per request. The repo `public/index.php` `$handler` therefore optionally requires a test-only fixture when `WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE` points at an existing file (the worker-runtime acceptance lane). The branch is idle when that env is unset. Skeleton / `make:public` stub / golden stay unchanged. -->
<!-- Spec reviewed 2026-08-10 - #2327 corrected runtime wiring: HttpKernel explicitly installs CommunityMiddleware at priority 20 because compiled AsMiddleware metadata is not itself an executable registration path (#2330 tracks that broader discovery/runtime mismatch). CommunityMiddleware preserves route/session precedence, falls back to the authoritative active configured context, writes `_community_id` before FieldReadContextMiddleware, and restores the prior configured context after dispatch for long-lived workers. Fixed-community immutable principals therefore align with storage/controllers; inactive contexts remain unscoped. -->
<!-- Spec reviewed 2026-07-30 - #2154 (follow-up to #2146): a session.stateless_paths entry of exactly "/" now means the ROOT PATH only, not a prefix of every path. Prefix-matching it made every anonymous GET stateless including /admin/login (a GET that must mint a CSRF token, withheld when no session exists), so an app could not express a cookie-free homepage without silently breaking its own authentication. Named prefixes are unchanged. See middleware-pipeline.md "Stateless path gate". -->

<!-- Spec reviewed 2026-07-30 - #2146 stateless session paths: SessionMiddleware gains an opt-in session.stateless_paths gate (anonymous GET/HEAD on configured prefixes skip session_start; session-cookie-carrying requests resume; other methods unchanged; default [] is exact behavior parity). Access-control semantics unchanged: skipped sessions resolve to AnonymousUser under deny-unless-granted. Full contract in middleware-pipeline.md "SessionMiddleware". -->
<!-- Spec reviewed 2026-07-17 - #2064 WP2 production-wires FieldReadContextMiddleware at priority 15 through the audit provider. It runs after bearer/session identity (40/30) and before route authorization (10), installs a strictly audited immutable principal, restores the fiber-local scope in finally, and wraps deferred streamed-response callbacks with the same bounded principal. Field enforcement remains dormant. -->

<!-- Spec reviewed 2026-06-19 - classic-FrankenPHP fallback in the front controller: `frankenphp_handle_request()` is defined under BOTH worker mode AND classic FrankenPHP (`php-server`) / FPM, where *calling* it throws "called while not in worker mode" — so branching on `function_exists()` alone mis-detected classic mode and `frankenphp php-server --root public` 500'd on every request (alpha.225 only had a worker-mode harness; no native binary tested classic mode). The worker loop now attempts the loop and, if the FIRST call throws before any request is handled, falls through to the single synchronous `$handler()` path (classic FrankenPHP, `php -S`, FPM); worker mode is unchanged. Verified against a real FrankenPHP 1.12 binary (classic `php-server` → 200; worker mode → 200 on repeated warm requests). Applied identically across all four front-controller copies (repo `public/index.php`, `skeleton/public/index.php`, the `make:public` stub, and the `golden-public-index.php` drift reference) so the byte-for-byte convergence audit (`waaseyaa-audit-site`) still holds; locked by `tests/Architecture/FrontControllerRuntimeDispatchTest.php`. The skeleton also adds a `composer serve:franken` script (classic `php-server` on `127.0.0.1:8080`, loopback + non-privileged → no privileged-port/auto-TLS prompt). -->
<!-- Spec reviewed 2026-06-19 - runtime-agnostic dev-serve refactor: the FrankenPHP worker-mode adapter is now identical across all three front controllers (repo `public/index.php`, the `make:public` template stub, and `skeleton/public/index.php`), so `public/index.php` is the single source of runtime awareness. The concurrent runtime is launched by the NATIVE `frankenphp` command + a committed `config/frankenphp/Caddyfile` (worker mode) — the framework no longer wraps it in a `serve` subcommand (`serve` is now ONLY plain single-worker `php -S`). The three runtimes and the SessionMiddleware -> AuthorizationMiddleware pipeline below are otherwise unchanged. -->
<!-- Spec reviewed 2026-06-04 - PR #1614: the front controller (`public/index.php`) now supports three runtimes ahead of the same authorization pipeline: (1) FrankenPHP worker mode — boot once then loop on `frankenphp_handle_request()` so the app stays warm and requests are served concurrently across threads (a long-lived SSE `/api/broadcast` stream pins one thread while the rest stay responsive); (2) FrankenPHP/FPM classic — one request per invocation; (3) `php -S` cli-server — single request with static-file passthrough. The middleware pipeline (SessionMiddleware -> AuthorizationMiddleware) and its onion/attribute model are unchanged; only the request-loop wrapper differs by runtime. -->
<!-- Spec reviewed 2026-04-22 - public/index.php: optional Dotenv loadEnv(..., APP_ENV, production), REQUEST_URI ?? '/' in cli-server guard, outer Throwable catch JSON:API 500 -->

Waaseyaa implements typed middleware pipelines for two execution contexts: HTTP requests and background jobs. Each pipeline uses the onion pattern with separate, type-safe interface pairs. Middleware is discovered via PHP 8 attributes and compiled into sorted stacks.

## Packages

| Package | Role | Key files |
|---------|------|-----------|
| `packages/foundation/` | Interfaces, pipeline classes, `AsMiddleware` attribute, `PackageManifestCompiler` | `src/Middleware/`, `src/Attribute/AsMiddleware.php`, `src/Discovery/` |
| `packages/routing/` | `RouteBuilder` (route option helpers) | `src/RouteBuilder.php` |
| `packages/access/` | `AccessChecker` (reads route access options) | `src/AccessChecker.php` |
| `packages/user/` | `SessionMiddleware` (resolves `_account` from PHP session) | `src/Middleware/SessionMiddleware.php` |
| `packages/access/` | `AuthorizationMiddleware` (enforces route-level access) | `src/Middleware/AuthorizationMiddleware.php` |

## Three Typed Pipeline Interfaces

Each pipeline context has a paired middleware interface and handler interface. They are structurally identical but type-safe to prevent cross-pipeline wiring. (An Event pipeline was planned but never implemented; the interfaces were removed in #1075.)

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
| Job | `JobHandlerInterface` | `JobMiddlewareInterface` |

All four interfaces live in `Waaseyaa\Foundation\Middleware` namespace. The design document references `JobNextHandlerInterface` but the implemented interface is `JobHandlerInterface` -- use the actual name from the codebase.

## Onion Pattern

Each pipeline class (`HttpPipeline`, `JobPipeline`) wraps a stack of middleware around a final handler. Execution order is outer-to-inner going in, inner-to-outer coming back.

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
- `JobPipeline` follows the same pattern but returns `void`.

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

The production HTTP pipeline in `HttpKernel::serveHttpRequest()` wires middleware in priority order around the real dispatch handler:

```
ResponseCacheControlMiddleware -> SecurityHeadersMiddleware -> SessionMiddleware -> CommunityMiddleware -> CsrfMiddleware -> FieldReadContextMiddleware -> AuthorizationMiddleware -> controller/domain-router dispatch
```

### Pre-boot maintenance gate (outside this pipeline)

`MaintenanceModeMiddleware` (`waaseyaa/foundation`) is the one HTTP middleware NOT wired into the pipeline above. It is invoked once, explicitly, at the very top of `HttpKernel::handle()` — **before** `boot()` — via `maintenanceGate()`. This is deliberate: `boot()` runs migrations and schema validation against the database, so a maintenance 503 has to be decided before any DB work to survive a database that is mid-swap (the SFN live-SQLite-swap incident, #2122). Because the branded 503 short-circuits before the pipeline exists, the middleware applies its own security headers via `SecurityHeadersMiddleware::applyResponseDefaults()`.

To keep exactly one invocation path, `MaintenanceModeMiddleware` deliberately carries **no** `#[AsMiddleware]` attribute, so `PackageManifestCompiler` never discovers it into any pipeline stack — it cannot run twice per request. See `docs/specs/operations-playbooks.md` "Playbook I: Maintenance Mode (quiesce for deploys / DB swaps)".

### Wiring code (from HttpKernel::serveHttpRequest())

```php
$pipeline = new HttpPipeline();
foreach ($middlewares as $middleware) {
    $pipeline = $pipeline->withMiddleware($middleware);
}

$response = $pipeline->handle(
    $httpRequest,
    $dispatchHandler,
);
```

The terminal handler performs the real controller/domain-router dispatch. The
same onion pass therefore supports both request-side checks and response-side
work: middleware enters before dispatch, may short-circuit by returning its own
response, and otherwise unwinds over the final response. Provider middleware
contributed through `HasMiddlewareInterface::middleware()` has this same
contract; no separate app response hook is required.

`CommunityMiddleware` is an explicit kernel built-in. Its `#[AsMiddleware]`
attribute supplies priority metadata, but compiled manifest metadata is not an
executable registration path in the current kernel. Issue #2330 tracks that
broader discovery/runtime contract without implicitly activating or
double-registering other attribute-only middleware.
Like the field-read principal middleware, it rebinds its request community
around deferred streamed-response callbacks before restoring worker state.

`public/index.php` is a thin entry point that boots the kernel and sends the returned response. Under FrankenPHP worker mode, `auto_prepend_file` runs once per process; the repo copy therefore optionally `require`s `WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE` at the start of each `$handler` invocation so the worker-runtime acceptance lane can emit per-request identity headers. Production leaves that env unset and the branch does not run. Production apps typically load `.env` **before** constructing `HttpKernel` via Symfony `Dotenv::loadEnv($projectRoot . '/.env', 'APP_ENV', 'production')` when the file exists — the third argument defaults missing `APP_ENV` to **`production`**, not Symfony's implicit **`dev`**. The monorepo entry wraps malformed `.env` in try/catch; skeleton / `make:public` stub match Minoo's optional-load + outer `Throwable` catch returning JSON:API 500. The file also contains a `cli-server` guard (see [cli-server static file guard](#cli-server-static-file-guard)) so static assets are served directly by the built-in server without passing through `HttpKernel`:

```php
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
if (is_file($projectRoot . '/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
}
$kernel = new HttpKernel($projectRoot);
$response = $kernel->handle();
$response->send();
```

### SessionMiddleware

**File:** `packages/user/src/Middleware/SessionMiddleware.php`
**Namespace:** `Waaseyaa\User\Middleware`
**Implements:** `HttpMiddlewareInterface`

Behavior:
1. **Stateless path gate (#2146):** if `session.stateless_paths` is configured (a list of path prefixes wired by `HttpKernel`), an anonymous `GET`/`HEAD` request matching a prefix skips `session_start()` entirely, so informational surfaces stay `Set-Cookie`-free and shared-cache friendly. Three guards bound the skip: a request that already carries the session cookie resumes its session normally (authenticated visitors keep their identity on stateless pages); every non-`GET`/`HEAD` method always gets a session (login, form and CSRF flows are unaffected); and the default `[]` disables the gate, preserving pre-#2146 behavior exactly. Prefix matching is exact-segment (`/docs` matches `/docs` and `/docs/x`, never `/docsearch`). An entry of exactly **`/` means the root path only** (#2154) — it is not a prefix of every path, because prefix-matching it would silently make the whole site stateless including `/admin/login`, a GET that must mint a CSRF token; an app listing `/` for a cookie-free homepage composes it with named prefixes (`['/', '/news']`) as usual. With no active session, `CsrfMiddleware`'s token-presence guard withholds the `XSRF-TOKEN` cookie on its own. Access control is unaffected: a skipped session resolves the account to `AnonymousUser`, and deny-unless-granted still governs the route.
2. If `session.cookie` options are configured (see `HttpKernel` / `config/waaseyaa.php`), applies matching `ini_set` calls for `session.cookie_*` and `session.use_strict_mode` **before** `session_start()`. Supported keys: `httponly` (bool), `secure` (bool or `'auto'` to enable only when the request is HTTPS or `X-Forwarded-Proto: https`), `samesite` (string), `use_strict_mode` (bool).
3. Reads `$_SESSION['waaseyaa_uid']` (or `$request->attributes->get('_session')` for testability).
4. Loads `User` entity via `EntityRepositoryInterface::find($uid)`.
5. Falls back to `AnonymousUser` if uid is null, user not found, or storage throws.
6. Sets `AccountInterface` instance on `$request->attributes->set('_account', $account)`.
7. Marks non-stateless requests as session-bound for final cache reconciliation,
   disables PHP's independent session cache limiter before framework-owned
   session startup, and calls `$next->handle($request)`.

### Final response cache policy

`ResponseCacheControlMiddleware` is priority 110, outside every built-in
response mutator. After inner middleware and controller dispatch complete, it
replaces the entire `Cache-Control` field with `private, no-store` when either
the request was session-bound or the final Symfony response carries any
`Set-Cookie`. This removes `public`/`s-maxage` contradictions and leaves exactly
one response-level cache authority. Cookie-free stateless responses retain the
SSR renderer's public policy.

This middleware always calls the next handler. It never short-circuits.

See also [`http-entry-point.md`](./http-entry-point.md) — do not set session ini in `public/index.php`.

### AuthorizationMiddleware

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php`
**Namespace:** `Waaseyaa\Access\Middleware`
**Implements:** `HttpMiddlewareInterface`

Behavior:
1. Reads `Route` from `$request->attributes->get('_route_object')`. If null, passes through.
2. Reads `AccountInterface` from `$request->attributes->get('_account')`. If missing/invalid, returns 403.
3. Delegates to `AccessChecker::check($route, $account)`.
4. If `$result->isForbidden()`, attempts HTML error output via optional `ErrorPageRendererInterface` (e.g. Twig in `SsrServiceProvider`); otherwise returns a 403 JSON:API response.
5. Otherwise (allowed or neutral), calls `$next->handle($request)`.

This middleware can short-circuit with a 403 response.

### Pre-pipeline steps in `HttpKernel`

CORS handling and route matching happen **before** the pipeline runs (inside `HttpKernel::serveHttpRequest()`). The matched `Route` object is set on `$request->attributes->set('_route_object', $matchedRoute)` before the pipeline starts. This is required because `AuthorizationMiddleware` reads it from the request.

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
| `_authenticated` | `bool` | If `true`, require a non-anonymous account; otherwise `AccessChecker` denies (401-class) before other checks. |
| `_session` | `bool` \| `list<string>` | Require an active session; a list restricts to the named session scopes. |
| `_permission` | `string` | Require `$account->hasPermission($permission)` to return `true`. |
| `_role` | `string` | Comma-separated role list. Account must have at least one. |
| `_gate` | `array{ability: string, subject?: mixed}` | Delegates to `GateInterface::allows()`. |

**Combination logic:** Multiple options are combined with AND. All must pass.

**No requirements:** If no options are set, `AccessChecker` returns `AccessResult::neutral()`. The `AuthorizationMiddleware` treats neutral as "pass through" (open-by-default).

### RouteBuilder helpers

```php
// File: packages/routing/src/RouteBuilder.php
RouteBuilder::create('/api/nodes')
    ->requireAuthentication()              // sets _authenticated = true
    ->requireSession()                     // sets _session option
    ->requirePermission('access content')  // sets _permission option
    ->requireRole('editor')                // sets _role option
    ->allowAll()                           // sets _public = true
    ->build();
```

## php://input Single-Read Constraint

`HttpRequest::createFromGlobals()` consumes `php://input`. The stream cannot be read again.

**Rule:** After creating the Symfony `Request` object, always use `$httpRequest->getContent()` to read the request body. Never call `file_get_contents('php://input')` afterward.

`HttpRequest::createFromGlobals()` is called inside `HttpKernel::serveHttpRequest()`, not in `public/index.php`. Any code that needs the request body must receive the `Request` object and call `$request->getContent()`:

```php
// Inside HttpKernel or middleware — correct pattern:
$raw = $request->getContent();  // reads from the Request object, not php://input
```

## Built-in HTTP Middleware

All HTTP middleware implement `HttpMiddlewareInterface` and use `#[AsMiddleware(pipeline: 'http', priority: N)]` for auto-discovery. Higher priority runs first (outer onion layer).

| Priority | Class | Package | Purpose |
|----------|-------|---------|---------|
| 100 | `SecurityHeadersMiddleware` | foundation | Runtime-safe defaults (`X-Frame-Options: SAMEORIGIN` + `nosniff`) around the final response; CSP and HSTS remain opt-in. Constructor: `(?string $csp, bool $hstsEnabled, int $hstsMaxAge, string $frameOptions)`. Explicit construction retains the historical CSP/HSTS-on defaults; `HttpKernel` passes `null`/`false` for those deployment-sensitive headers. |
| 90 | `CompressionMiddleware` | foundation | gzip compression for responses above minimum size. Constructor: `(int $minimumSize = 1024)` |
| 80 | `RateLimitMiddleware` | foundation | IP-based rate limiting via `RateLimiterInterface`. Constructor: `(RateLimiterInterface, int $maxAttempts = 60, int $windowSeconds = 60)` |
| 70 | `BodySizeLimitMiddleware` | foundation | Rejects payloads over max bytes (413). Constructor: `(int $maxBytes = 1_048_576)` |
| 60 | `RequestLoggingMiddleware` | foundation | Logs method, URI, status, duration. Constructor: `(?Closure $logger = null)` |
| 50 | `ETagMiddleware` | foundation | ETag generation + 304 Not Modified for GET/HEAD |
| 40 | `BearerAuthMiddleware` | user | JWT and API key auth via Bearer header. Constructor: `(EntityRepositoryInterface, string $jwtSecret, array $apiKeys, ?LoggerInterface)` |
| 30 | `SessionMiddleware` | user | Resolves `AccountInterface` from session |
| 20 | `CsrfMiddleware` | user | Double-submit / header CSRF validation for state-changing requests. JSON content types are exempt by default; a route with `_csrf = true` (`RouteBuilder::requireCsrf()`) validates every content type, and `_csrf = false` (`csrfExempt()`) skips validation. Writes the `XSRF-TOKEN` cookie on `text/html` responses and on any response carrying an authenticated `_account` plus the `waaseyaa_uid` login-session marker (SPA boot via JSON; bearer-only requests excluded); the cookie's `Secure`/`SameSite` attributes come from the resolved `session.cookie` policy shared with the session cookie (`SessionCookiePolicy`, #2149) |
| 10 | `AuthorizationMiddleware` | access | Route-level access enforcement via `AccessChecker` |

## File Reference

### Interfaces (packages/foundation/src/Middleware/)

| File | Interface |
|------|-----------|
| `HttpMiddlewareInterface.php` | `process(Request, HttpHandlerInterface): Response` |
| `HttpHandlerInterface.php` | `handle(Request): Response` |
| `JobMiddlewareInterface.php` | `process(Job, JobHandlerInterface): void` |
| `JobHandlerInterface.php` | `handle(Job): void` |

### Pipeline classes (packages/foundation/src/Middleware/)

| File | Class |
|------|-------|
| `HttpPipeline.php` | `HttpPipeline` -- immutable, `withMiddleware()` returns new instance |
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

### Access checking

| File | Class |
|------|-------|
| `packages/access/src/AccessChecker.php` | `AccessChecker` -- reads `_public`, `_authenticated`, `_session`, `_permission`, `_role`, `_gate` from Route options |
| `packages/routing/src/RouteBuilder.php` | `RouteBuilder` -- fluent API with `requirePermission()`, `requireRole()`, `requireAuthentication()`, `requireSession()`, `allowAll()` |

### Front controller

| File | Role |
|------|------|
| `public/index.php` | Thin entry point: optional pre-kernel `Dotenv::loadEnv(..., 'APP_ENV', 'production')`, boots `HttpKernel`, sends returned `Response`. `cli-server` guard uses `$_SERVER['REQUEST_URI'] ?? '/'` when resolving paths. |
| `HttpKernel::serveHttpRequest()` | Wires CORS, route matching, `HttpPipeline`, dispatch |

#### cli-server static file guard

`public/index.php` includes the following guard at the top (after `declare(strict_types=1)`):

```php
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}
```

`return false` tells PHP's built-in server to serve the file directly from disk. Without this, requests for Vite build assets, images, and other static files would be routed through `HttpKernel` and return 404. This guard has no effect on production servers (Caddy, nginx) which never use the `cli-server` SAPI.

### Tests

| File | Coverage |
|------|----------|
| `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php` | SessionMiddleware unit tests |
| `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php` | AuthorizationMiddleware unit tests |
| `tests/Integration/Phase11/AuthorizationPipelineTest.php` | Full pipeline integration (Session + Auth + final handler) |

## Symfony decoupling (mission 1107)

Middleware classes implement `Waaseyaa\Foundation\Http\HttpMiddlewareInterface`, which type-hints Symfony's `HttpFoundation\Request` and `Response`. Per ratified contract C-002 of mission 1107-api-symfony-decoupling, those Symfony types remain in the foundation-internal middleware contract — the mission narrows app-level decoupling to controllers and event-dispatch (Path R-narrow). App code that authors middleware can use the `Waaseyaa\Foundation\Http\Request` alias on the inbound side; the response side stays Symfony's `Response` until a future major version revisits it.

For event-dispatch in middleware (e.g., emitting `DomainEvent` instances during request handling), inject `Waaseyaa\Foundation\Event\EventDispatcherInterface` rather than `Symfony\Contracts\EventDispatcher\EventDispatcherInterface`. The kernel binds `SymfonyEventDispatcherAdapter` as the default, so existing Symfony-typed services continue to work.

## Implementation gotchas

- **Interface naming**: handler interfaces follow `{Type}HandlerInterface` (`HttpHandlerInterface`, `EventHandlerInterface`, `JobHandlerInterface`); middleware interfaces follow `{Type}MiddlewareInterface`. The attribute-discovery for `#[AsMiddleware]` only picks up classes that implement the right interface for their pipeline — naming a class `FooMiddleware` without implementing the matching `{Type}MiddlewareInterface` silently skips it during compilation.
