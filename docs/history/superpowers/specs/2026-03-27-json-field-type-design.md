# Add JSON Field Type with Typed Accessor (#647)

## Context

Fields with structured data (arrays, objects) currently require manual `json_decode()` on every access. Issue #647 adds a `json` field type that automatically encodes/decodes, eliminating ~15 manual calls in consumer apps.

## New File: `packages/field/src/Item/JsonItem.php`

- **Attribute**: `#[FieldType(id: 'json', label: 'JSON', category: 'general', defaultCardinality: 1)]`
- **Extends**: `FieldItemBase`
- `schema()`: `['value' => ['type' => 'text']]` — stored as TEXT column
- `defaultValue()`: `null`
- `defaultSettings()`: `[]`
- `jsonSchema()`: `['type' => 'object']`

## Storage Encode/Decode

Modify `SqlEntityStorage` to handle json field type during save and load.

**Save** (`splitForStorage()` or before it): For fields whose type is `json` in field definitions, `json_encode()` array/object values before storage. Use `JSON_THROW_ON_ERROR` per project convention.

**Load** (`mapRowToEntity()` or after it): For fields whose type is `json` in field definitions, `json_decode()` string values back to arrays. Use `JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT` is NOT needed — decode as associative array with `json_decode($value, true, 512, JSON_THROW_ON_ERROR)`.

The entity type's `getFieldDefinitions()` provides the type info. Only fields with `'type' => 'json'` are affected.

## GraphQL Mapping

Add `'json'` to the existing type map in `packages/graphql/src/Schema/FieldTypeMapper.php`:
- `toOutputType()`: map to `Type::string()` (JSON serialized as string, standard GraphQL convention)
- `toInputType()`: map to `Type::string()`

## Tests

1. **`JsonItemTest`** in `packages/field/tests/Unit/Item/JsonItemTest.php` — verify schema returns text column, defaultValue is null, jsonSchema returns object type
2. **Round-trip storage test** in `packages/entity-storage/tests/Unit/SqlEntityStorageTest.php` — create entity type with json field definition, save entity with array value, load it back, assert value is a PHP array (not a JSON string)

## Verification

1. `./vendor/bin/phpunit packages/field/tests/` — all tests pass
2. `./vendor/bin/phpunit packages/entity-storage/tests/` — all tests pass
3. `composer cs-check` — no violations
4. `composer phpstan` — no new errors
