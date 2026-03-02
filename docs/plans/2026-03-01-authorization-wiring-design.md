# Authorization Wiring Design

Wire the existing access primitives (Gate, EntityAccessHandler, AccessChecker, PermissionHandler) into the new middleware pipeline and discovery infrastructure from PR #4.

## Context

PR #4 shipped three subsystems: package discovery, middleware pipelines, and config caching. The access package already has Gate, policies, EntityAccessHandler, AccessChecker, and PermissionHandler. These two layers are completely disconnected — `public/index.php` is a procedural dispatcher that never touches the middleware pipeline or the access system.

This design connects them.

## Decisions

- **Authentication:** Session-based (PHP sessions). JWT/API keys deferred to future work.
- **Refactor scope:** Minimal shim in `public/index.php`. Existing dispatch logic becomes the final handler in an HttpPipeline wrapper.
- **Permission discovery:** Static declarations in `composer.json` under `extra.waaseyaa.permissions`.
- **Default access policy:** Open-by-default. Routes without access requirements pass through. Lockdown is opt-in via route options.
- **Approach:** Pipeline-first (Approach A). Build middleware, extend discovery, shim index.php.

## Components

### 1. SessionMiddleware

**File:** `packages/user/src/Middleware/SessionMiddleware.php`

Implements `HttpMiddlewareInterface`. Resolves the current user from the PHP session.

Behavior:
- Calls `session_start()` if not already started
- Reads `$_SESSION['waaseyaa_uid']`
- Loads `User` entity via entity storage; falls back to `AnonymousUser`
- Sets `AccountInterface` on `$request->attributes->set('_account', $user)`
- Calls `$next->handle($request)`

Does not handle login/logout — only resolves "who is making this request."

Lives in the `user` package because it depends on `User`, `AnonymousUser`, and entity storage.

### 2. AuthorizationMiddleware

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php`

Implements `HttpMiddlewareInterface`. Enforces route-level access using the existing `AccessChecker`.

Behavior:
- Reads `AccountInterface` from `$request->attributes->get('_account')`
- Reads matched `Route` from `$request->attributes->get('_route_object')`
- Delegates to `AccessChecker::check($route, $account)`
- Forbidden → returns 403 JSON:API response
- Neutral (no requirements on route) → passes through (open-by-default)
- Allowed → calls `$next->handle($request)`

Requires SessionMiddleware to run first. Enforced by middleware priority.

### 3. Discovery Extensions

#### 3a. Permission discovery from composer.json

Packages declare permissions:

```json
{
  "extra": {
    "waaseyaa": {
      "permissions": {
        "access content": { "title": "Access published content" },
        "create article": { "title": "Create Article content", "description": "..." }
      }
    }
  }
}
```

`PackageManifestCompiler::compile()` collects these into a merged `$permissions` map.

#### 3b. Policy discovery via attribute scanning

`PackageManifestCompiler::scanClasses()` gains `PolicyAttribute` (from `Waaseyaa\Access\Gate\PolicyAttribute`) in its attribute scan list. Discovered policies are stored as `array<string, string>` (entity type ID => policy class FQCN).

#### 3c. PackageManifest changes

Two new properties:
- `permissions: array<string, array{title: string, description?: string}>`
- `policies: array<string, string>` (entity type => class FQCN)

`fromArray()` and `toArray()` updated for new keys. Cache file includes them.

### 4. index.php Shim

Wraps existing dispatch in `HttpPipeline`:

**Before:** match route → parse body → dispatch to controller → sendJson()

**After:** create Request → build HttpPipeline(session, auth) → pipeline->handle(request, finalHandler) → send Response

Changes:
- Build `Symfony\Component\HttpFoundation\Request` from globals
- Set matched `Route` object on `$request->attributes`
- Wrap existing dispatch in anonymous `HttpHandlerInterface` final handler
- Build `HttpPipeline` with `SessionMiddleware` + `AuthorizationMiddleware`
- Run pipeline

What stays unchanged:
- CORS handling (pre-pipeline)
- Route matching (pre-pipeline, middleware needs the matched route)
- `sendJson()` helper
- All controller/dispatch logic (just moved inside final handler)

## Testing

### Unit tests

- `SessionMiddlewareTest` — mock entity storage, verify anonymous fallback, verify user resolution, verify `_account` set on request
- `AuthorizationMiddlewareTest` — mock AccessChecker, verify 403 on forbidden, passthrough on allowed/neutral
- `PackageManifestCompilerTest` — extend for permission collection and policy scanning
- `PackageManifestTest` — extend for new `permissions`/`policies` fields

### Integration test (Phase 11)

Full pipeline: SessionMiddleware → AuthorizationMiddleware → final handler

Scenarios:
- Route with `_permission => 'access content'`: anonymous → 403, authenticated without permission → 403, authenticated with permission → 200
- Public route (`_public => true`): anyone → 200
- Route with no requirements: anyone → 200 (open-by-default)

Uses in-memory storage (`PdoDatabase::createSqlite()`) and real `User` entities.

## File Summary

New files:
- `packages/user/src/Middleware/SessionMiddleware.php`
- `packages/access/src/Middleware/AuthorizationMiddleware.php`
- `packages/user/tests/Unit/Middleware/SessionMiddlewareTest.php`
- `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`
- `tests/Integration/Phase11/AuthorizationPipelineTest.php`

Modified files:
- `packages/foundation/src/Discovery/PackageManifestCompiler.php` (add permission + policy scanning)
- `packages/foundation/src/Discovery/PackageManifest.php` (add permissions + policies properties)
- `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php` (extend)
- `packages/foundation/tests/Unit/Discovery/PackageManifestTest.php` (extend)
- `public/index.php` (pipeline shim)
