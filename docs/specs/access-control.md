# Access Control

<!-- Spec reviewed 2026-04-01 - post-M10 provider-owned user/auth routes, manifest-discovered policy wiring, C18 drift remediation (#1017) -->

Waaseyaa's access control system spans three packages: `packages/access/` (core primitives), `packages/routing/` (route-level checks), and `packages/user/` (session resolution, password reset). This document covers entity-level and route-level access. For field-level access, see `docs/specs/field-access.md`.

## Packages

| Package | Path | Provides |
|---------|------|----------|
| access | `packages/access/src/` | AccessPolicyInterface, AccessResult, AccessStatus, EntityAccessHandler, AccountInterface, FieldAccessPolicyInterface, PermissionHandler, Gate, EntityAccessGate, AuthorizationMiddleware |
| routing | `packages/routing/src/` | AccessChecker (route-level access) |
| user | `packages/user/src/` | SessionMiddleware (account resolution), UserServiceProvider (package-owned user/auth routes) |

## Core Interfaces

### AccessPolicyInterface

**File:** `packages/access/src/AccessPolicyInterface.php`
**Namespace:** `Waaseyaa\Access`

```php
interface AccessPolicyInterface
{
    public function access(
        EntityInterface $entity,
        string $operation, // 'view', 'update', or 'delete'
        AccountInterface $account,
    ): AccessResult;

    public function createAccess(
        string $entityTypeId,
        string $bundle,
        AccountInterface $account,
    ): AccessResult;

    public function appliesTo(string $entityTypeId): bool;
}
```

- `access()` checks an existing entity for a given operation.
- `createAccess()` checks whether an entity of the given type/bundle can be created.
- `appliesTo()` scopes which entity types this policy governs. EntityAccessHandler skips policies that return `false`.

### AccountInterface

**File:** `packages/access/src/AccountInterface.php`
**Namespace:** `Waaseyaa\Access`

```php
interface AccountInterface
{
    public function id(): int|string;
    public function hasPermission(string $permission): bool;
    public function getRoles(): array; // string[]
    public function isAuthenticated(): bool;
}
```

**Critical:** `AccountInterface` lives in the `access` package, not `user`. The `User` entity and `AnonymousUser` live in `packages/user/`. Access must never depend on User to avoid circular package dependencies. Middleware needing an account should type-hint `AccountInterface`, not concrete `AnonymousUser`.

## Access Result Semantics

**File:** `packages/access/src/AccessResult.php`
**Namespace:** `Waaseyaa\Access`

AccessResult is a `final readonly class` with three states defined in the `AccessStatus` enum:

```php
enum AccessStatus: string
{
    case ALLOWED = 'allowed';
    case NEUTRAL = 'neutral';
    case FORBIDDEN = 'forbidden';
}
```

### Factory Methods

```php
AccessResult::allowed(string $reason = ''): AccessResult
AccessResult::neutral(string $reason = ''): AccessResult
AccessResult::forbidden(string $reason = ''): AccessResult
AccessResult::unauthenticated(string $reason = ''): AccessResult
```

### State Checks

```php
$result->isAllowed(): bool   // status === ALLOWED
$result->isNeutral(): bool   // status === NEUTRAL
$result->isForbidden(): bool // status === FORBIDDEN
```

### Combination Logic

**`orIf()`** -- OR logic, used by EntityAccessHandler to combine policy results:

- Forbidden wins over everything (short-circuit)
- Either Allowed yields Allowed
- Both Neutral yields Neutral

**`andIf()`** -- AND logic, used by AccessChecker to combine route requirements:

- Forbidden wins over everything (short-circuit)
- Both must be Allowed for Allowed
- At least one Neutral yields Neutral

### Entity-Level Evaluation Pattern

Entity access uses **deny-by-default** with `isAllowed()`:

```php
// EntityAccessHandler::check() starts with Neutral, combines via orIf().
// Controller checks: $result->isAllowed()
// Neutral means "no policy granted" = denied.
```

This is intentionally asymmetric with field-level access, which uses `!isForbidden()`. See `docs/specs/field-access.md`.

## Entity Access Handler

**File:** `packages/access/src/EntityAccessHandler.php`
**Namespace:** `Waaseyaa\Access`

Orchestrates policy evaluation. Not a `final class` (can be extended).

```php
class EntityAccessHandler
{
    public function __construct(array $policies = []) // AccessPolicyInterface[]
    public function addPolicy(AccessPolicyInterface $policy): void

    public function check(
        EntityInterface $entity,
        string $operation,       // 'view', 'update', 'delete'
        AccountInterface $account,
    ): AccessResult;

    public function checkCreateAccess(
        string $entityTypeId,
        string $bundle,
        AccountInterface $account,
    ): AccessResult;

    public function checkFieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,       // 'view' or 'edit'
        AccountInterface $account,
    ): AccessResult;

    public function filterFields(
        EntityInterface $entity,
        array $fieldNames,       // string[]
        string $operation,       // 'view' or 'edit'
        AccountInterface $account,
    ): array; // string[] — fields not forbidden
}
```

### Evaluation Algorithm

For `check()` and `checkCreateAccess()`:

1. Start with `AccessResult::neutral('No policy provided an opinion.')`.
2. Iterate registered policies. Skip those where `appliesTo($entityTypeId)` returns false.
3. Call `$policy->access(...)` or `$policy->createAccess(...)`.
4. Combine results with `orIf()` (any Allowed grants access).
5. Short-circuit on Forbidden -- nothing can override it.
6. Return final result.

For `checkFieldAccess()` and `filterFields()`, see `docs/specs/field-access.md`.

### Policy Registration

Policies are passed to the constructor or added via `addPolicy()`. In the current post-M10 boot flow, `AccessPolicyRegistry` builds the handler from `PackageManifest::$policies`, while the kernel still exposes the resulting gate to `AccessChecker` during boot:

```php
$accessHandler = new EntityAccessHandler([
    new NodeAccessPolicy(),
    new TermAccessPolicy(),
    new ConfigEntityAccessPolicy(entityTypeIds: ['node_type', 'taxonomy_vocabulary', ...]),
]);
$gate = new EntityAccessGate($accessHandler);
$accessChecker = new AccessChecker(gate: $gate);
```

## Gate System

The Gate is a separate access mechanism from EntityAccessHandler. It resolves policies by entity type and delegates ability checks to method calls.

### GateInterface

**File:** `packages/access/src/Gate/GateInterface.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
interface GateInterface
{
    public function allows(string $ability, mixed $subject, ?object $user = null): bool;
    public function denies(string $ability, mixed $subject, ?object $user = null): bool;
    public function authorize(string $ability, mixed $subject, ?object $user = null): void;
        // throws AccessDeniedException
}
```

### Gate (Implementation)

**File:** `packages/access/src/Gate/Gate.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
final class Gate implements GateInterface
{
    public function __construct(private readonly array $policies = [])
}
```

Policy resolution strategy:
1. Check for `#[PolicyAttribute(entityType: '...')]` on the policy class.
2. Fall back to naming convention: `NodePolicy` maps to entity type `node` (PascalCase to snake_case).

Ability delegation: `$gate->allows('update', $node)` calls `$policy->update($user, $node)`. If the method does not exist, ability is denied.

### EntityAccessGate (Adapter)

**File:** `packages/access/src/Gate/EntityAccessGate.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
final class EntityAccessGate implements GateInterface
{
    public function __construct(private readonly EntityAccessHandler $handler)
}
```

Adapter that bridges `GateInterface` to `EntityAccessHandler`, reusing existing `AccessPolicyInterface` policies. Translation logic:

- `allows($ability, EntityInterface $subject, AccountInterface $user)` → `$handler->check($subject, $ability, $user)->isAllowed()`
- `allows('create', string $entityTypeId, AccountInterface $user)` → `$handler->checkCreateAccess($entityTypeId, '', $user)->isAllowed()`
- String subject + non-`create` ability → `false` (instance required for view/update/delete)
- Non-`AccountInterface` user or unsupported subject type → `false` with `error_log()` diagnostic

Wired in `public/index.php`: wraps `EntityAccessHandler` and is passed to `AccessChecker(gate: $gate)`. Policy exceptions are caught, logged, and treated as denial.

### PolicyAttribute

**File:** `packages/access/src/Gate/PolicyAttribute.php`
**Namespace:** `Waaseyaa\Access\Gate`

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PolicyAttribute
{
    public function __construct(
        public readonly string $entityType,
    ) {}
}
```

### AccessPolicy (Plugin Discovery Attribute)

**File:** `packages/access/src/Attribute/AccessPolicy.php`
**Namespace:** `Waaseyaa\Access\Attribute`

Extends `WaaseyaaPlugin`. Used for attribute-based plugin discovery (distinct from `PolicyAttribute` for the Gate).

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
class AccessPolicy extends WaaseyaaPlugin
{
    public function __construct(
        string $id,
        public readonly array $entityTypes = [],
        string $label = '',
        string $description = '',
    ) {}
}
```

### AccessDeniedException

**File:** `packages/access/src/Gate/AccessDeniedException.php`

```php
final class AccessDeniedException extends \RuntimeException
{
    public function __construct(
        public readonly string $ability,
        public readonly mixed $subject,
        string $message = '',
    ) {}
}
```

## Route Access Control

**File:** `packages/routing/src/AccessChecker.php`
**Namespace:** `Waaseyaa\Routing`

```php
final class AccessChecker
{
    public function __construct(private readonly ?GateInterface $gate = null)

    public function check(Route $route, AccountInterface $account): AccessResult

    public static function applyGateToRoute(
        Route $route,
        string $ability,
        mixed $subject = null,
    ): void
}
```

### Route Options

Routes declare access requirements via Symfony Route options. Multiple requirements combine with AND logic (all must pass).

| Option | Type | Behavior |
|--------|------|----------|
| `_public` | `true` | Always allow (no auth required) |
| `_authenticated` | `true` | Require non-anonymous identity; returns `AccessResult::unauthenticated()` (401) if anonymous. Short-circuits before other checks. |
| `_session` | `true` or `string[]` | Require active session. When array, requires specific session keys to be present. |
| `_permission` | `string` | Require specific permission via `$account->hasPermission()` |
| `_role` | `string` | Require role (comma-separated for multiple); checks `$account->getRoles()` |
| `_gate` | `array{ability: string, subject?: mixed}` | Require gate ability check |

If no access requirements are present on the route, returns `AccessResult::neutral()`. AuthorizationMiddleware treats Neutral as passthrough (open-by-default at the route level).

### Evaluation

1. Check `_authenticated` first (short-circuit: returns `unauthenticated` immediately if anonymous).
2. Check `_session` (short-circuit: returns `forbidden` if session requirements not met).
3. Start with `AccessResult::allowed()`.
4. For each remaining requirement present (`_public`, `_permission`, `_role`, `_gate`), compute its result and combine via `andIf()`.
5. If no requirements found, return `AccessResult::neutral()`.
6. Return combined result.

## Permission Handler

**File:** `packages/access/src/PermissionHandler.php`
**Namespace:** `Waaseyaa\Access`

```php
class PermissionHandler implements PermissionHandlerInterface
{
    public function registerPermission(string $id, string $title, string $description = ''): void
    public function getPermissions(): array // array<string, array{title: string, description: string}>
    public function hasPermission(string $permission): bool
}
```

Permissions are declared in `composer.json` under `extra.waaseyaa.permissions` and collected into the package manifest by `PackageManifestCompiler`.

```json
{
  "extra": {
    "waaseyaa": {
      "permissions": {
        "access content": { "title": "Access published content" },
        "create article": { "title": "Create Article content" }
      }
    }
  }
}
```

## Authorization Pipeline

**Entry point:** `public/index.php`

The authorization pipeline is a pair of HTTP middleware executed in order:

```
Request -> SessionMiddleware -> AuthorizationMiddleware -> Final Handler -> Response
```

### SessionMiddleware

**File:** `packages/user/src/Middleware/SessionMiddleware.php`
**Namespace:** `Waaseyaa\User\Middleware`

```php
final class SessionMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly EntityStorageInterface $userStorage,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
}
```

Behavior:
1. Reads `$_SESSION['waaseyaa_uid']` (via `$request->attributes->get('_session')` or `$_SESSION`).
2. Loads User entity via `$this->userStorage->load($uid)`.
3. Falls back to `AnonymousUser` if: no UID in session, load fails, or loaded entity is not `AccountInterface`.
4. Sets `$request->attributes->set('_account', $account)`.
5. Calls `$next->handle($request)`.

Does not handle login/logout. Only resolves "who is making this request."

Lives in the `user` package because it depends on `User`, `AnonymousUser`, and entity storage.

### AuthorizationMiddleware

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php`
**Namespace:** `Waaseyaa\Access\Middleware`

```php
final class AuthorizationMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly AccessChecker $accessChecker,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
}
```

Behavior:
1. Reads matched `Route` from `$request->attributes->get('_route_object')`. If null, passes through.
2. Reads `AccountInterface` from `$request->attributes->get('_account')`. If missing/invalid, returns 403 JSON:API error.
3. Delegates to `$this->accessChecker->check($route, $account)`.
4. If Forbidden: returns 403 JSON:API response with `$result->reason`.
5. If Neutral (no requirements on route): passes through (open-by-default).
6. If Allowed: calls `$next->handle($request)`.

Requires SessionMiddleware to run first. Enforced by middleware priority ordering.

### 403 Response Format

```json
{
  "jsonapi": { "version": "1.1" },
  "errors": [{
    "status": "403",
    "title": "Forbidden",
    "detail": "The 'administer site' permission is required."
  }]
}
```

Content-Type: `application/vnd.api+json`.

## Discovery

Policies and permissions are discovered at build time via `PackageManifestCompiler`:

- **Policy discovery:** `#[AccessPolicy]` attribute is scanned during class scanning. Discovered policies stored as `array<string, string>` (entity type ID => FQCN) in the manifest.
- **Permission discovery:** `composer.json` `extra.waaseyaa.permissions` collected into `PackageManifest::$permissions`.

Layer discipline: Foundation (layer 0) uses string constants for attribute class names to avoid importing from higher layers. `ReflectionClass::getAttributes()` accepts string class names.

## User/Auth HTTP Surfaces (post-M10 package ownership)

**Packages:** `packages/auth/`, `packages/user/`
**Registered by:** package service providers discovered from composer metadata. `UserServiceProvider` owns the foundational request surfaces `GET /api/user/me`, `POST /api/auth/login`, and `POST /api/auth/logout`; `AuthServiceProvider` continues to own registration, password-reset, and email-verification controllers.

### Endpoint Access Requirements

#### UserServiceProvider-owned routes

| Endpoint | Route option | Controller |
|----------|-------------|------------|
| `GET /api/user/me` | `_public: true` | `user.me` |
| `POST /api/auth/login` | `_public: true` | `auth.login` |
| `POST /api/auth/logout` | `_public: true` | `auth.logout` |

These three request surfaces are registered by `packages/user/src/UserServiceProvider.php` as part of the package-owned route model introduced by M10.

#### AuthServiceProvider-owned routes

| Endpoint | Route option | Controller |
|----------|-------------|------------|
| `POST /api/auth/register` | `_public: true` | `RegisterController` |
| `POST /api/auth/forgot-password` | `_public: true` | `ForgotPasswordController` |
| `POST /api/auth/reset-password` | `_public: true` | `ResetPasswordController` |
| `POST /api/auth/verify-email` | `_public: true` | `VerifyEmailController` |
| `POST /api/auth/resend-verification` | `_authenticated: true` | `ResendVerificationController` |

`ResendVerificationController` requires an active authenticated session. `AccessChecker` short-circuits with `unauthenticated` (401) if the `_account` attribute on the request is anonymous. The other four endpoints are public — no session required.

### Rate Limiting

All auth endpoints apply rate limiting via `RateLimiter` keyed on IP or user identity:

| Endpoint | Limit |
|----------|-------|
| `POST /api/auth/register` | 5 per IP per 15 min |
| `POST /api/auth/forgot-password` | 3 per email per 15 min, 10 per IP per hour |
| `POST /api/auth/reset-password` | 10 per IP per hour |
| `POST /api/auth/verify-email` | 10 per IP per hour |
| `POST /api/auth/resend-verification` | 3 per user per hour |

Rate limit responses return 429 with a `Retry-After` header.

### Anti-Enumeration

All user-facing responses from `ForgotPasswordController` and `RegisterController` are generic — the system never reveals whether an account exists for a given email. Constant-time comparisons are used where needed to prevent timing side-channels.

### AuthTokenRepository

Replaces `PasswordResetTokenRepository` (which used raw PDO). Uses `DatabaseInterface` (DBAL). Tokens are 64-char hex strings hashed with HMAC-SHA256 using `auth.token_secret` from config. Plain tokens are never persisted.

**Token types and default TTLs:**

| Type | Default TTL | Notes |
|------|-------------|-------|
| `password_reset` | 1 hour | Single-use; revokes previous tokens for same user |
| `email_verification` | 24 hours | Single-use; revokes previous tokens for same user |
| `invite` | 7 days | Single-use; `user_id` is NULL |

### Auth Configuration

Registered under `auth` key in `config/waaseyaa.php`:

```php
'auth' => [
    'registration' => 'admin',        // 'admin' | 'open' | 'invite'
    'require_verified_email' => false, // true = block unverified users from AdminShell
    'mail_missing_policy' => null,     // null = auto (dev-log in dev, fail in prod)
    'token_secret' => env('AUTH_TOKEN_SECRET', ''),
    'token_ttl' => [
        'password_reset' => 3600,
        'email_verification' => 86400,
        'invite' => 604800,
    ],
],
```

`mail_missing_policy` auto-resolves: `dev-log` when `APP_ENV` is `local`/`development`; `fail` in production. Explicit values `'dev-log'`, `'fail'`, and `'silent'` override the auto behavior.

## File Reference

```
packages/access/src/
    AccessPolicyInterface.php        - Entity access policy contract
    FieldAccessPolicyInterface.php   - Field access policy contract (see field-access.md)
    AccessResult.php                 - Tri-state value object (Allowed/Neutral/Forbidden)
    AccessStatus.php                 - Enum: ALLOWED, NEUTRAL, FORBIDDEN
    EntityAccessHandler.php          - Orchestrates policy evaluation
    AccountInterface.php             - User account contract (id, permissions, roles)
    PermissionHandler.php            - In-memory permission registry
    PermissionHandlerInterface.php   - Permission registry contract
    Attribute/
        AccessPolicy.php             - Plugin discovery attribute
    Gate/
        GateInterface.php            - Gate contract (allows/denies/authorize)
        Gate.php                     - Gate implementation with policy resolution
        EntityAccessGate.php         - Adapter bridging GateInterface to EntityAccessHandler
        PolicyAttribute.php          - Maps policy class to entity type
        AccessDeniedException.php    - Thrown by Gate::authorize()
    RedirectValidator.php            - Open-redirect prevention (isSafe/sanitize)
    ErrorPageRendererInterface.php   - Error page rendering contract (render -> ?Response)
    Middleware/
        AuthorizationMiddleware.php  - Route-level access enforcement

packages/routing/src/
    AccessChecker.php                - Route option access checks (_public, _authenticated, _session, _permission, _role, _gate)

packages/user/src/
    Middleware/
        SessionMiddleware.php        - Resolves AccountInterface from session
        BearerAuthMiddleware.php     - JWT and API key authentication via Bearer tokens (priority: 40)

public/index.php                     - Front controller; wires the pipeline
```
