# Field-Level Access Control — Design

## Summary

Add field-level access control to Waaseyaa, allowing policies to restrict which fields a user can view or edit on entities. Builds on the existing entity-level access system (AccessResult, AccessPolicyInterface, EntityAccessHandler) without changing existing behavior.

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Granularity | field + operation (view/edit) | Covers 90% of cases; no bundle scoping needed yet |
| Mechanism | Policy classes returning AccessResult | Consistent with entity-level pattern; allows entity-aware logic |
| View denial | Omit field from JSON:API response | Principle of least information; cleaner API |
| Edit denial in forms | Show as read-only display | Better UX; user sees value but can't change it |
| Architecture | Separate interface, shared handler (Approach C) | Avoids second discovery pipeline; clean interface separation |
| Default behavior | Open-by-default (NEUTRAL = accessible) | Zero behavioral change without field policies |

## Architecture

### FieldAccessPolicyInterface

New interface in `packages/access/src/`:

```php
interface FieldAccessPolicyInterface
{
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,  // 'view' or 'edit'
        AccountInterface $account,
    ): AccessResult;
}
```

Policy classes opt in by implementing both `AccessPolicyInterface` and `FieldAccessPolicyInterface`. Same `#[AccessPolicy]` attribute — no new discovery. `appliesTo()` from `AccessPolicyInterface` scopes field access to the same entity types.

### EntityAccessHandler extension

Two new methods on `EntityAccessHandler`:

- `checkFieldAccess(entity, fieldName, operation, account)` — runs field access policies with OR logic, FORBIDDEN short-circuits. Skips policies that don't implement `FieldAccessPolicyInterface`.
- `filterFields(entity, fieldNames, operation, account)` — convenience for bulk filtering. Returns field names that are not FORBIDDEN.

### JSON:API integration

**ResourceSerializer:** `serialize()` gains optional `EntityAccessHandler` + `AccountInterface` params. When provided, view-denied fields are omitted from the attributes object.

**JsonApiController:**
- GET (index/show): passes access context to serializer — view-denied fields omitted.
- POST (store) / PATCH (update): checks edit access for each submitted field before applying. Returns 403 JSON:API error if any submitted field is edit-denied.

### Admin form integration

**SchemaPresenter:** `present()` gains optional entity + access context params. When provided:
- View-denied fields: removed from schema entirely (frontend never sees them).
- Edit-denied fields: marked with `readOnly: true` and `x-access-restricted: true`.

**Frontend (useSchema.ts):** `sortedProperties(editable)` refined to keep `x-access-restricted` fields in editable mode (shown as disabled) while still filtering system-readOnly fields (id, uuid).

**Frontend (SchemaField.vue):** Passes `disabled` prop to widgets when field has `x-access-restricted`.

## Files changed

### New files
- `packages/access/src/FieldAccessPolicyInterface.php`

### Modified files
- `packages/access/src/EntityAccessHandler.php` — add `checkFieldAccess()`, `filterFields()`
- `packages/api/src/ResourceSerializer.php` — optional field access filtering in `serialize()`
- `packages/api/src/JsonApiController.php` — field edit checks on store/update
- `packages/api/src/Schema/SchemaPresenter.php` — access metadata in schema
- `packages/admin/app/composables/useSchema.ts` — `x-access-restricted` support
- `packages/admin/app/components/schema/SchemaForm.vue` — disabled prop for restricted fields
- `packages/admin/app/components/schema/SchemaField.vue` — pass disabled to widgets

### Test files
- `packages/access/tests/Unit/FieldAccessPolicyTest.php` — interface contract tests
- `packages/access/tests/Unit/EntityAccessHandlerFieldAccessTest.php` — checkFieldAccess, filterFields
- `packages/api/tests/Unit/ResourceSerializerFieldAccessTest.php` — field omission
- `packages/api/tests/Unit/JsonApiControllerFieldAccessTest.php` — 403 on edit-denied
- `packages/api/tests/Unit/Schema/SchemaPresenterFieldAccessTest.php` — schema annotations
- `tests/Integration/Phase6/FieldAccessIntegrationTest.php` — full round-trip

## Scope boundary

This PR does NOT include:
- Permission string auto-generation from fields
- Admin UI for managing field permissions
- Bundle-scoped field access
- Field access caching

Those are follow-ups after this foundation ships.
