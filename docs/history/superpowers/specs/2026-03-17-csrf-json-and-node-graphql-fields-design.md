# CSRF JSON Exemption (#468) & Node GraphQL Field Definitions (#469)

**Date:** 2026-03-17
**Issues:** #468, #469
**Status:** Approved

## Problem

Two framework blockers prevent Claudriel's GraphQL adoption:

1. **#468 — CSRF blocks `application/json` POST**: The CSRF middleware only exempts `application/vnd.api+json`. GraphQL endpoints send `application/json`, which triggers CSRF validation and returns 403.

2. **#469 — `_data` blob fields not in GraphQL schema**: Node's `title`, `type`, and `slug` fields are stored in the `_data` JSON blob but not declared in `fieldDefinitions`. Since `EntityTypeBuilder::buildOutputFields()` only iterates `getFieldDefinitions()`, these fields are invisible to GraphQL. Additionally, the `uid` entity reference uses `settings.target_type` instead of the top-level `target_entity_type_id` that `EntityTypeBuilder` expects, so it doesn't resolve as a reference.

## Architectural Decision

**#469 fix location: Entity type declarations (Option A), not schema builder introspection (Option B).**

Rationale:
- Entity types are the source of truth for field schema, validation, GraphQL exposure, and access control
- GraphQL should not know about `_data`, SQL columns, or storage internals
- Undeclared fields in `_data` are a bug in the entity type, not in GraphQL
- Automatic introspection would create unpredictable schemas dependent on content

## Changes

### #468 — CsrfMiddleware + GraphQlRouteProvider

#### `packages/user/src/Middleware/CsrfMiddleware.php`

Add `'application/json'` to `CSRF_EXEMPT_CONTENT_TYPES`:

```php
private const CSRF_EXEMPT_CONTENT_TYPES = ['application/vnd.api+json', 'application/json'];
```

**Rationale:** Browsers cannot submit `application/json` from HTML forms. Same security reasoning as the existing `application/vnd.api+json` exemption.

#### `packages/graphql/src/GraphQlRouteProvider.php`

Add `->csrfExempt()` to the `/graphql` route:

```php
RouteBuilder::create('/graphql')
    ->controller('graphql.endpoint')
    ->allowAll()
    ->csrfExempt()
    ->methods('GET', 'POST')
    ->build(),
```

**Rationale:** Belt-and-suspenders. GraphQL always uses JSON, so this ensures no regressions if content-type negotiation changes.

### #469 — NodeServiceProvider field definitions

#### `packages/node/src/NodeServiceProvider.php`

Add three missing field definitions and fix the `uid` reference:

| Field | Type | Required | ReadOnly | Notes |
|-------|------|----------|----------|-------|
| `title` | string | yes | no | Label key — node's display name |
| `type` | string | yes | yes | Bundle key — set at creation, not editable. `readOnly` excludes it from GraphQL mutation inputs (correct behavior). |
| `slug` | string | yes | no | URL-safe identifier |

Fix `uid` reference — replace `settings.target_type` with top-level `target_entity_type_id` (remove the orphaned `settings` key entirely):

```php
// Before:
'settings' => ['target_type' => 'user']

// After:
'target_entity_type_id' => 'user'
```

This aligns with `EntityTypeBuilder`'s lookup at lines 116-118 which checks `target_entity_type_id` or `targetEntityTypeId` at the top level of the field definition.

#### Fields NOT included

`summary` and `image` are deferred until Node bundles are formalized. They are not consistently present across all nodes today.

## Test Plan

### Unit tests
- `NodeServiceProviderTest` — verify all field definitions are registered (title, type, slug, uid with correct reference format)

### Integration tests
- GraphQL schema test: confirm `title`, `type`, `slug` appear on the Node output type
- GraphQL schema test: confirm `uid` resolves as a User entity reference, not a raw scalar
- CSRF test: confirm `application/json` POST requests bypass CSRF validation
- CSRF test: confirm the GraphQL route is CSRF-exempt via route option

## Files Modified

- `packages/user/src/Middleware/CsrfMiddleware.php`
- `packages/graphql/src/GraphQlRouteProvider.php`
- `packages/node/src/NodeServiceProvider.php`
- `packages/node/tests/Unit/NodeServiceProviderTest.php` (updated)
- New integration test file for GraphQL schema field coverage
