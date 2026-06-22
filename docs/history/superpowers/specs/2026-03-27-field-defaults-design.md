# Apply Field Defaults from EntityType on create() (#646)

## Context

`SqlEntityStorage::create()` instantiates entities without merging field defaults from `EntityType::getFieldDefinitions()`. Entity constructors must hardcode defaults, working around the framework. The `default` key in field definitions is never applied.

## Change

Modify `SqlEntityStorage::create()` to merge `default` values from field definitions into `$values` before calling `instantiateEntity()`. Only apply when the key is absent from `$values`.

**File**: `packages/entity-storage/src/SqlEntityStorage.php:61-71`

Uses `array_key_exists('default', $def)` (not `isset()`) to allow `null` as a valid default. Existing timestamp population in `instantiateEntity()` is unaffected — it runs after this merge.

## Tests

Add to existing or new test file in `packages/entity-storage/tests/Unit/`:

1. `create_applies_field_defaults` — EntityType with `'status' => ['type' => 'integer', 'default' => 1]`, create with no values → entity has `status = 1`
2. `create_explicit_values_override_defaults` — same definition, create with `['status' => 0]` → entity has `status = 0`
3. `create_skips_fields_without_default_key` — field definition without `default` key → value not set

## Verification

1. `./vendor/bin/phpunit packages/entity-storage/tests/` — all tests pass
2. `composer cs-check` — no violations
3. `composer phpstan` — no new errors
