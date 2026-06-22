# Data Model: Live Entity Validation & Key Protection

**Date**: 2026-06-12 | **Plan**: [plan.md](plan.md)

No new persisted entities and no schema changes. The "data model" of this mission is the constraint-resolution model, the identity-key set, and the error shapes.

## Constraint sources and precedence

For a field `F` on entity type `T` (bundle-aware definitions resolved via `EntityRepository::resolveValidationFieldDefinitions()`):

| Layer | Source | Semantics |
|---|---|---|
| 1. Derived | Field settings → `FieldDefinitionConstraintBuilder` | required → NotBlank/NotNull; min_length/max_length → Length; email → Email; allowed_values/enum → Choice; scalar type → Type; **NEW:** numeric `min`/`max` settings → Range |
| 2. Declared per-field | `FieldDefinition::getConstraints()` (`Constraint[]`) | **NEW:** appended after derived (tightens, never replaces); non-Constraint entry → `InvalidArgumentException` |
| 3. Manual per-type | `EntityType::getConstraints()` (field → constraints map) | Existing behavior preserved: **replaces** layers 1+2 for that field |

Range derivation applies to types `integer`, `int`, `float`, `double` when `min` and/or `max` settings are numeric. Both, either, or neither may be present (mirror the Length builder's shape).

## Identity-key refusal set (per entity type)

```
refused(T) = { getKeys(T)[k] : k ∈ {id, uuid, revision, langcode, default_langcode} }
           ∪ { "uuid", "langcode", "default_langcode" }
```

- `label` and `bundle` key kinds are NEVER refused (ordinary content / create-time structure).
- Applies identically to `entity.create` and `entity.update` values payloads (whole-write rejection: one refused key rejects the entire call before any construction or mutation).
- `revision` covers the revision id key on revisionable types; revision *log* remains writable via the dedicated `revision_log` argument.

## Error shapes

### EntityValidationException (existing, now reachable framework-wide)

Carries `ConstraintViolationListInterface`; each violation has `propertyPath` = field name (with optional sub-path), message, invalid value. Violation ordering at the tool boundary: sorted by field name, then insertion order (NFR-003 determinism).

### Agent tool validation error (new surface)

```
message: "entity.update: validation failed: <field>: <message>[; <field>: <message>...]"
payload (when result type supports error content):
  { "error": "validation_failed",
    "violations": [ { "field": "score", "message": "...", "invalid_value_type": "string" } ] }
```

### Agent tool identity-key refusal (new surface)

```
message: "entity.create: refused identity keys: langcode, uuid — identity fields cannot be written through this tool"
payload: { "error": "identity_keys_refused", "refused_keys": ["langcode", "uuid"] }
```

## Configuration surface

| Name | Kind | Default | Effect |
|---|---|---|---|
| `WAASEYAA_ENTITY_VALIDATION` | env var | enabled | `0`/`false`/`off` (case-insensitive) → kernel builds repositories without a validator (pre-mission behavior) |
| `save($entity, validate: false)` | per-call flag (existing) | `true` | Skips validation for one save; sanctioned for migrations/bootstrap |

## State transitions

None. Validation is a pre-persistence gate: save either completes exactly as before or throws before any write (including bundle subtable and revision writes).
