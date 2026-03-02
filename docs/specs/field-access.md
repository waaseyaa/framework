# Field-Level Access

Field-level access control allows policies to restrict which fields a user can view or edit on entities. It is a companion to entity-level access, sharing the same handler and discovery infrastructure but with intentionally different semantics.

## Overview

- **Interface:** `FieldAccessPolicyInterface` in `packages/access/src/`
- **Handler:** `EntityAccessHandler` in `packages/access/src/` (same class that handles entity access)
- **Companion:** Classes implement both `AccessPolicyInterface` AND `FieldAccessPolicyInterface`
- **Discovery:** Same `#[AccessPolicy]` attribute; no separate discovery pipeline
- **Default:** Open-by-default. No field policies = all fields accessible.

## Asymmetric Semantics

Access result interpretation differs between entity-level and field-level checks. This asymmetry is intentional.

| Level | Check | Default | Meaning |
|-------|-------|---------|---------|
| Entity | `$result->isAllowed()` | Deny unless granted | Neutral = no policy granted = denied |
| Field | `!$result->isForbidden()` | Allow unless denied | Neutral = no policy denied = accessible |

Entity-level is deny-by-default: a policy must explicitly return `Allowed` for access to be granted. Field-level is open-by-default: access is granted unless a policy explicitly returns `Forbidden`.

```php
// Entity access check (deny-by-default):
$result = $handler->check($entity, 'view', $account);
if ($result->isAllowed()) { /* grant */ }

// Field access check (open-by-default):
$result = $handler->checkFieldAccess($entity, 'title', 'view', $account);
if (!$result->isForbidden()) { /* grant */ }
```

## FieldAccessPolicyInterface

**File:** `packages/access/src/FieldAccessPolicyInterface.php`
**Namespace:** `Waaseyaa\Access`

```php
interface FieldAccessPolicyInterface
{
    /**
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $fieldName The field name being checked.
     * @param string           $operation 'view' or 'edit'
     * @param AccountInterface $account   The account requesting access.
     */
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation, // 'view' or 'edit'
        AccountInterface $account,
    ): AccessResult;
}
```

### Operations

| Operation | Meaning | Denial Effect |
|-----------|---------|---------------|
| `'view'` | Can the account see this field value? | Field omitted from JSON:API response |
| `'edit'` | Can the account modify this field? | 403 error if submitted in POST/PATCH; shown as disabled in admin form |

## Open-by-Default Design

When `EntityAccessHandler::checkFieldAccess()` runs:

1. Starts with `AccessResult::neutral('No field access policy provided an opinion.')`.
2. Iterates policies. Skips those where `appliesTo()` returns false.
3. Skips policies that do not implement `FieldAccessPolicyInterface` (uses `instanceof`).
4. Calls `$policy->fieldAccess(...)` on qualifying policies.
5. Combines with `orIf()`. Forbidden short-circuits.
6. Returns result.

When no policy implements `FieldAccessPolicyInterface` for the entity type, the result is Neutral. Neutral is not Forbidden, so all fields pass through. This ensures zero behavioral change when no field policies exist.

```php
// EntityAccessHandler::checkFieldAccess() excerpt:
$result = AccessResult::neutral('No field access policy provided an opinion.');
foreach ($this->policies as $policy) {
    if (!$policy->appliesTo($entityTypeId)) { continue; }
    if (!$policy instanceof FieldAccessPolicyInterface) { continue; }
    $policyResult = $policy->fieldAccess($entity, $fieldName, $operation, $account);
    $result = $result->orIf($policyResult);
    if ($result->isForbidden()) { return $result; }
}
return $result;
```

### Bulk Filtering

`EntityAccessHandler::filterFields()` is a convenience method:

```php
public function filterFields(
    EntityInterface $entity,
    array $fieldNames,   // string[]
    string $operation,   // 'view' or 'edit'
    AccountInterface $account,
): array // string[] -- fields not forbidden
```

Implementation: filters via `!$this->checkFieldAccess(...)->isForbidden()`.

## Intersection Types for Policies

Policy classes must implement both interfaces to participate in field access checks. A class that only implements `AccessPolicyInterface` is skipped for field checks. A class that only implements `FieldAccessPolicyInterface` would never be registered (policies are typed as `AccessPolicyInterface[]`).

```php
#[AccessPolicy(id: 'node_access', entityTypes: ['node'])]
final class NodeAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        // entity-level logic
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        // create-level logic
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        // field-level logic
        if ($fieldName === 'status' && !$account->hasPermission('administer nodes')) {
            return AccessResult::forbidden('Only administrators can edit the status field.');
        }
        return AccessResult::neutral();
    }
}
```

The `appliesTo()` method from `AccessPolicyInterface` scopes both entity-level and field-level access to the same entity types.

## View vs Edit Denial

### JSON:API Serialization (ResourceSerializer)

**File:** `packages/api/src/ResourceSerializer.php`

When access context is provided, `serialize()` omits view-denied fields from the attributes object:

```php
public function serialize(
    EntityInterface $entity,
    ?EntityAccessHandler $accessHandler = null,
    ?AccountInterface $account = null,
): array
```

- View-denied field: omitted entirely from response attributes.
- Edit-denied field: still included in view response (edit denial only affects mutation).

### Schema Generation (SchemaPresenter)

**File:** `packages/api/src/Schema/SchemaPresenter.php`

When access context is provided, `present()` annotates the JSON Schema:

```php
public function present(
    EntityTypeInterface $entityType,
    array $fieldDefinitions = [],
    ?EntityInterface $entity = null,
    ?EntityAccessHandler $accessHandler = null,
    ?AccountInterface $account = null,
): array
```

- View-denied fields: removed from schema entirely (frontend never sees them).
- Edit-denied fields: marked `readOnly: true` with `x-access-restricted: true`.

### `x-access-restricted` Extension

`x-access-restricted: true` is a JSON Schema extension that signals the admin SPA to show the field as a disabled widget. This is distinct from system `readOnly` (used for `id`, `uuid`) which hides the field from forms entirely.

```json
{
  "properties": {
    "status": {
      "type": "boolean",
      "readOnly": true,
      "x-access-restricted": true
    }
  }
}
```

Frontend behavior:
- System `readOnly` without `x-access-restricted`: field hidden from edit forms.
- `readOnly` with `x-access-restricted`: field shown as disabled widget (user can see value but not change it).

### JSON:API Controller (JsonApiController)

**File:** `packages/api/src/JsonApiController.php`

- GET (index/show): passes access context to serializer; view-denied fields omitted.
- POST (store) / PATCH (update): checks edit access for each submitted field before applying. Returns 403 JSON:API error if any submitted field is edit-denied.

## Paired Nullable Parameters

`ResourceSerializer::serialize()` and `SchemaPresenter::present()` accept `?EntityAccessHandler` + `?AccountInterface`. Both must be non-null or both null -- only two of four states are meaningful.

```php
// Correct guard pattern:
if ($accessHandler !== null && $account !== null) {
    $viewableFields = $accessHandler->filterFields($entity, $fieldNames, 'view', $account);
}
```

When both are null, no field filtering occurs and the full entity/schema is returned.

## Wiring

**File:** `public/index.php`

The front controller creates the access handler and passes it through the call chain:

```php
$account = $httpRequest->attributes->get('_account');
$accessHandler = new EntityAccessHandler([]);
// Pass to JsonApiController constructor (already accepts optional params)
// Pass to SchemaController constructor (accepts optional params)
```

For `SchemaController`, a prototype entity is created for policy evaluation:

```php
$class = $entityType->getEntityClass();
$prototypeEntity = new $class([]); // User/Node accept (array $values)
$schema = $schemaPresenter->present($entityType, $fields, $prototypeEntity, $accessHandler, $account);
```

With no policies registered, `EntityAccessHandler` returns Neutral for all fields. Field-level semantics (`!isForbidden()`) means all fields pass through unchanged.

## Testing Field Access

### Anonymous Classes for Intersection Types

PHPUnit `createMock()` cannot mock intersection types. Use real anonymous classes:

```php
$policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
    {
        if ($fieldName === 'secret_field' && $operation === 'view') {
            return AccessResult::forbidden('Restricted.');
        }
        return AccessResult::neutral();
    }
};

$handler = new EntityAccessHandler([$policy]);
```

### Testing Patterns

- Test that Neutral from `checkFieldAccess()` passes `!isForbidden()` (open-by-default).
- Test that Forbidden from any policy short-circuits.
- Test that policies not implementing `FieldAccessPolicyInterface` are skipped.
- Test `filterFields()` with mixed access results.
- Avoid double `$storage->create()` in access checks: when checking field access before persisting a new entity, create once and reuse for both the access check and the save.

### Unit Test Locations

```
packages/access/tests/Unit/FieldAccessPolicyTest.php
packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php
packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php
packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php
packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php
tests/Integration/Phase6/FieldAccessIntegrationTest.php
```

## File Reference

```
packages/access/src/
    FieldAccessPolicyInterface.php   - Field access policy contract
    AccessPolicyInterface.php        - Entity access policy contract (companion)
    AccessResult.php                 - Tri-state value object
    EntityAccessHandler.php          - checkFieldAccess(), filterFields()

packages/api/src/
    ResourceSerializer.php           - Omits view-denied fields
    JsonApiController.php            - Checks edit access on mutations
    Schema/
        SchemaPresenter.php          - x-access-restricted annotation

packages/admin/app/
    composables/useSchema.ts         - Reads x-access-restricted
    components/schema/SchemaForm.vue - Disabled prop for restricted fields
    components/schema/SchemaField.vue - Passes disabled to widgets

public/index.php                     - Wires access context into controllers
```
