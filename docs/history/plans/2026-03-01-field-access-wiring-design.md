# Field-Access Wiring Design

Wire the existing field-level access substrate into JSON:API serialization and schema generation.

## Context

The field-access substrate is complete: `EntityAccessHandler.checkFieldAccess()`, `filterFields()`, `FieldAccessPolicyInterface`, and the consuming code in `ResourceSerializer`, `JsonApiController`, and `SchemaPresenter` all accept optional access parameters and implement filtering/validation logic. None of this is activated because `public/index.php` never passes the access context.

## Approach: Minimal Wiring (Approach A)

No new classes, no discovery, no abstractions. Wire two existing values (`$account`, `$accessHandler`) through the call chain that already expects them.

## Changes

### 1. `public/index.php`

After the authorization pipeline resolves the account:

- Extract `$account` from `$httpRequest->attributes->get('_account')`
- Create `$accessHandler = new EntityAccessHandler([])`  (empty policy array — no policies yet)
- Pass both to `JsonApiController` constructor (already accepts them as optional params)
- Pass both to `SchemaController` constructor (new optional params, see below)
- Add `use Waaseyaa\Access\EntityAccessHandler`

### 2. `SchemaController`

- Add `?EntityAccessHandler $accessHandler = null` and `?AccountInterface $account = null` to constructor (matching `JsonApiController` pattern)
- In `show()`, when access context available:
  - Get entity class from entity type definition
  - Create prototype entity: `new $class([])` (User/Node accept `(array $values)`)
  - Pass prototype + handler + account to `$schemaPresenter->present()`
- When access context null: call `present()` with no access params (backward compat)
- Add `use Waaseyaa\Access\EntityAccessHandler` and `use Waaseyaa\Access\AccountInterface`

### 3. No changes needed

- **ResourceSerializer** — already accepts and uses `?EntityAccessHandler` + `?AccountInterface`
- **JsonApiController** — already accepts constructor params, checks field edit access in store/update, passes context to serializer
- **SchemaPresenter** — already omits view-denied fields, marks edit-denied as `readOnly: true` + `x-access-restricted: true`
- **EntityAccessHandler** — `checkFieldAccess()` and `filterFields()` fully implemented

## Decisions

- **Schema entity:** Use prototype entity (`new $class([])`) for policy evaluation on the schema endpoint
- **Policy loading:** Manual registration in `index.php` — add to the array when policies exist
- **SchemaController access context:** Constructor injection (matching JsonApiController pattern)

## Behavior

With no policies registered, `EntityAccessHandler` returns `Neutral` for all fields. Field-level semantics are open-by-default (`!isForbidden()`), so all fields pass through. Behavior is unchanged until a policy is added.

## Total scope

- ~6 lines in `public/index.php`
- ~10 lines in `SchemaController`
- 0 new files, 0 new classes, 0 test changes
