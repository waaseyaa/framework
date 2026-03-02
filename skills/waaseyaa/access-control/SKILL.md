---
name: waaseyaa:access-control
description: Use when working with access policies, field access, authorization middleware, gates, route access control, AccessResult semantics, permission handlers, or files in packages/access/, packages/routing/src/AccessChecker.php, packages/user/src/Middleware/. Covers entity-level access (deny-by-default via isAllowed), field-level access (open-by-default via !isForbidden), the Gate system, route options (_public, _permission, _role, _gate), and the SessionMiddleware/AuthorizationMiddleware pipeline in public/index.php.
---

# Access Control Specialist

## Scope

This skill covers the Waaseyaa access control system:

- Entity-level access: `AccessPolicyInterface`, `EntityAccessHandler`, `AccessResult`
- Field-level access: `FieldAccessPolicyInterface`, `checkFieldAccess()`, `filterFields()`
- Route-level access: `AccessChecker`, route options, `AuthorizationMiddleware`
- Gate system: `Gate`, `GateInterface`, `PolicyAttribute`, `AccessDeniedException`
- Session resolution: `SessionMiddleware`, `AccountInterface`
- Permission registry: `PermissionHandler`, `PermissionHandlerInterface`
- API integration: `ResourceSerializer` field filtering, `SchemaPresenter` access annotations
- Discovery: `#[AccessPolicy]` attribute, manifest compilation

## Key Interfaces

### AccessPolicyInterface

**File:** `packages/access/src/AccessPolicyInterface.php`

```php
interface AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult;
    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult;
    public function appliesTo(string $entityTypeId): bool;
}
```

Operations for `access()`: `'view'`, `'update'`, `'delete'`.

### FieldAccessPolicyInterface

**File:** `packages/access/src/FieldAccessPolicyInterface.php`

```php
interface FieldAccessPolicyInterface
{
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation, // 'view' or 'edit'
        AccountInterface $account,
    ): AccessResult;
}
```

Must be implemented alongside `AccessPolicyInterface`. EntityAccessHandler finds field policies via `instanceof FieldAccessPolicyInterface`.

### AccountInterface

**File:** `packages/access/src/AccountInterface.php`

```php
interface AccountInterface
{
    public function id(): int|string;
    public function hasPermission(string $permission): bool;
    public function getRoles(): array; // string[]
    public function isAuthenticated(): bool;
}
```

### AccessResult

**File:** `packages/access/src/AccessResult.php`

Three states: `AccessResult::allowed()`, `AccessResult::neutral()`, `AccessResult::forbidden()`.

Combination operators:
- `orIf()`: Forbidden wins, either Allowed yields Allowed. Used by EntityAccessHandler.
- `andIf()`: Forbidden wins, both must be Allowed. Used by AccessChecker for route requirements.

### EntityAccessHandler

**File:** `packages/access/src/EntityAccessHandler.php`

```php
class EntityAccessHandler
{
    public function __construct(array $policies = []);
    public function addPolicy(AccessPolicyInterface $policy): void;
    public function check(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult;
    public function checkCreateAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult;
    public function checkFieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult;
    public function filterFields(EntityInterface $entity, array $fieldNames, string $operation, AccountInterface $account): array;
}
```

### GateInterface

**File:** `packages/access/src/Gate/GateInterface.php`

```php
interface GateInterface
{
    public function allows(string $ability, mixed $subject, ?object $user = null): bool;
    public function denies(string $ability, mixed $subject, ?object $user = null): bool;
    public function authorize(string $ability, mixed $subject, ?object $user = null): void;
}
```

### AccessChecker

**File:** `packages/routing/src/AccessChecker.php`

```php
final class AccessChecker
{
    public function __construct(private readonly ?GateInterface $gate = null);
    public function check(Route $route, AccountInterface $account): AccessResult;
    public static function applyGateToRoute(Route $route, string $ability, mixed $subject = null): void;
}
```

Route options: `_public` (bool), `_permission` (string), `_role` (string, comma-separated), `_gate` (array).

## Architecture

### Authorization Pipeline

```
Request -> SessionMiddleware -> AuthorizationMiddleware -> Final Handler -> Response
```

- `SessionMiddleware` (`packages/user/src/Middleware/SessionMiddleware.php`): reads `$_SESSION['waaseyaa_uid']`, loads User entity, falls back to `AnonymousUser`, sets `_account` on request attributes.
- `AuthorizationMiddleware` (`packages/access/src/Middleware/AuthorizationMiddleware.php`): reads `_account` and `_route_object` from request, delegates to `AccessChecker::check()`, returns 403 JSON:API response on Forbidden.

### Asymmetric Access Semantics

This is the most critical architectural detail:

| Level | Check Method | Default Stance | Interpretation |
|-------|-------------|----------------|----------------|
| Entity | `$result->isAllowed()` | Deny unless granted | Neutral = denied |
| Field | `!$result->isForbidden()` | Allow unless denied | Neutral = accessible |

Entity access: deny-by-default. A policy must return `Allowed`.
Field access: open-by-default. Only explicit `Forbidden` restricts.

### Package Dependencies

```
access (layer 3) -- owns AccountInterface, AccessPolicyInterface, FieldAccessPolicyInterface, EntityAccessHandler
    |
    +-- Does NOT depend on user package
    |
user (layer 3) -- owns User, AnonymousUser, SessionMiddleware
    |
    +-- Depends on access (for AccountInterface)
    |
routing (layer 5) -- owns AccessChecker
    |
    +-- Depends on access (for AccessResult, AccountInterface, GateInterface)
```

`AccountInterface` lives in `access`, not `user`. This prevents circular dependencies. Always type-hint `AccountInterface`, not `AnonymousUser`.

### Paired Nullable Parameters

`ResourceSerializer::serialize()` and `SchemaPresenter::present()` accept `?EntityAccessHandler` + `?AccountInterface`. Both must be non-null or both null.

```php
if ($handler !== null && $account !== null) {
    // Apply field filtering
}
```

### x-access-restricted

JSON Schema extension marking fields viewable but not editable. The admin SPA reads this to show disabled widgets. Distinct from system `readOnly` (id, uuid) which hides the field entirely.

```json
{
  "status": {
    "type": "boolean",
    "readOnly": true,
    "x-access-restricted": true
  }
}
```

## Common Mistakes

### Wrong access check method for the level

```php
// WRONG: using isAllowed() for field check
if ($handler->checkFieldAccess($entity, 'title', 'view', $account)->isAllowed()) { ... }

// CORRECT: field access uses !isForbidden()
if (!$handler->checkFieldAccess($entity, 'title', 'view', $account)->isForbidden()) { ... }
```

### Circular dependency: access depending on user

```php
// WRONG: importing from user package into access package
use Waaseyaa\User\AnonymousUser;

// CORRECT: type-hint the interface from access package
use Waaseyaa\Access\AccountInterface;
```

### Trying to mock final classes or intersection types

```php
// WRONG: PHPUnit can't mock final class or intersection types
$policy = $this->createMock(AccessPolicyInterface::class); // may fail if final
$policy = $this->createMock(AccessPolicyInterface::class & FieldAccessPolicyInterface::class); // fails

// CORRECT: use anonymous class
$policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult {
        return AccessResult::allowed();
    }
    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult {
        return AccessResult::neutral();
    }
    public function appliesTo(string $entityTypeId): bool {
        return true;
    }
    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult {
        return AccessResult::neutral();
    }
};
```

### Forgetting that only FieldAccessPolicyInterface implementations participate

EntityAccessHandler skips policies that do not implement `FieldAccessPolicyInterface` during field access checks. An `AccessPolicyInterface`-only class will never have `fieldAccess()` called.

### Double entity creation in access checks

When checking field access before persisting a new entity, create the entity once and reuse it for both the access check and the save. Do not create a throwaway temp entity for the access check.

### Missing paired nullable parameters

Passing `EntityAccessHandler` without `AccountInterface` (or vice versa) results in no filtering. Both must be provided.

### Layer discipline for discovery

Foundation (layer 1) must never import from access (layer 3). Policy attribute scanning in `PackageManifestCompiler` uses string constants:

```php
private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute';
// NOT: use Waaseyaa\Access\Gate\PolicyAttribute;
```

### Gate naming convention mismatch

Gate resolves `NodePolicy` to entity type `node` via PascalCase-to-snake_case conversion. Use `str_replace('_', '', ucwords($name, '_'))` for the reverse (snake_case to PascalCase), not `ucfirst()`.

## Testing Patterns

### Unit Tests

Use real instances, not mocks, for `EntityAccessHandler` (not a final class but policies often are):

```php
$handler = new EntityAccessHandler([$policy1, $policy2]);
$result = $handler->check($entity, 'view', $account);
self::assertTrue($result->isAllowed());
```

### Anonymous Classes for Policies

```php
$policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
    // ... implement all methods with inline logic
};
```

### In-Memory Infrastructure

```php
$db = PdoDatabase::createSqlite(); // :memory: SQLite
$storage = new InMemoryEntityStorage(); // from Waaseyaa\Api\Tests\Fixtures
```

### Integration Test Location

```
tests/Integration/Phase6/FieldAccessIntegrationTest.php
tests/Integration/Phase11/AuthorizationPipelineTest.php
```

### PHPUnit Attributes

```php
#[Test]
#[CoversClass(EntityAccessHandler::class)]
public function checkFieldAccessReturnsForbiddenForRestrictedField(): void
```

Use `#[CoversNothing]` for integration tests.

## Related Specs

- `docs/specs/access-control.md` -- Entity-level access, route access, Gate system, authorization pipeline
- `docs/specs/field-access.md` -- Field-level access, asymmetric semantics, x-access-restricted, testing patterns

## Key Files

```
packages/access/src/AccessPolicyInterface.php
packages/access/src/FieldAccessPolicyInterface.php
packages/access/src/AccessResult.php
packages/access/src/AccessStatus.php
packages/access/src/EntityAccessHandler.php
packages/access/src/AccountInterface.php
packages/access/src/PermissionHandler.php
packages/access/src/PermissionHandlerInterface.php
packages/access/src/Attribute/AccessPolicy.php
packages/access/src/Gate/Gate.php
packages/access/src/Gate/GateInterface.php
packages/access/src/Gate/PolicyAttribute.php
packages/access/src/Gate/AccessDeniedException.php
packages/access/src/Middleware/AuthorizationMiddleware.php
packages/routing/src/AccessChecker.php
packages/user/src/Middleware/SessionMiddleware.php
packages/api/src/ResourceSerializer.php
packages/api/src/JsonApiController.php
packages/api/src/Schema/SchemaPresenter.php
public/index.php
```
