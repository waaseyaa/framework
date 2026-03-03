# Gate Wiring Design

**Date:** 2026-03-03
**Branch:** `feat/gate-wiring`
**Status:** Approved

## Problem

`AccessChecker` accepts a `?GateInterface` for `_gate` route options, but is constructed with `null` in `index.php`. Any route using `_gate` silently returns Forbidden. The existing entity access policies implement `AccessPolicyInterface`, not the Gate's method-dispatch pattern.

Additionally, `AccessChecker::checkGate()` does not pass the `$account` to `$this->gate->allows()`, so the Gate has no way to know who is requesting access.

## Approach: Adapter Pattern

Create an `EntityAccessGate` adapter that implements `GateInterface` and delegates to `EntityAccessHandler`. This reuses the existing `AccessPolicyInterface` policies without duplication.

### EntityAccessGate Translation Logic

- `allows('create', $entityTypeId, $account)` → `$handler->checkCreateAccess($entityTypeId, '', $account)->isAllowed()`
- `allows($operation, $entity, $account)` → `$handler->check($entity, $operation, $account)->isAllowed()` (entity subject)
- String subject + non-create ability → `false` (can't check instance access without an entity)
- No `AccountInterface` user → `false`

### Changes

| File | Change |
|---|---|
| `packages/access/src/Gate/EntityAccessGate.php` | New adapter implementing `GateInterface` |
| `packages/access/tests/Unit/Gate/EntityAccessGateTest.php` | Unit tests for adapter |
| `packages/routing/src/AccessChecker.php` | Pass `$account` to `checkGate()` and to gate's `allows()` |
| `packages/routing/tests/Unit/GateAccessTest.php` | Update tests for account passing |
| `public/index.php` | Reorder: handler before pipeline; pass gate to `AccessChecker` |

### index.php Reordering

`EntityAccessHandler` must be constructed before `AccessChecker` so the adapter can wrap it. Policies are stateless (accept account as method parameter), so this is safe:

```php
$accessHandler = new EntityAccessHandler([...policies...]);
$gate = new EntityAccessGate($accessHandler);
$accessChecker = new AccessChecker($gate);
$pipeline = (new HttpPipeline())
    ->withMiddleware(new SessionMiddleware($userStorage))
    ->withMiddleware(new AuthorizationMiddleware($accessChecker));
```

## Out of Scope

- Attaching `_gate` options to entity CRUD routes in `JsonApiRouteProvider` (separate PR)
- Gate-style policies for non-entity ability checks (e.g., `config.export`)
- `PackageManifest` auto-discovery of policies
