# Field type → column spec (`deriveColumnSpec`)

This document describes **`Waaseyaa\EntityStorage\SqlSchemaHandler::deriveColumnSpec()`** (`packages/entity-storage/src/SqlSchemaHandler.php`). The method no longer owns a type map. It normalizes a `FieldDefinition`, asks the registered field-type plugin for its canonical direct entity-column projection, and then layers definition-level `length`, `not_null`, and `default` settings onto that result.

Downstream, **`Waaseyaa\Database\Schema\DBALSchema::mapFieldType()`** (`packages/database-legacy/src/Schema/DBALSchema.php`) translates those Waaseyaa `type` strings into Doctrine DBAL abstract types for DDL. Vendor-specific SQL (e.g. MySQL `LONGTEXT`, `INT UNSIGNED`) is **not** spelled in `deriveColumnSpec()`; it emerges from the active DBAL platform when SQL is generated.

## Canonical mapping authority

`FieldTypeManagerInterface::entityStorageColumnSchemaFor()` is the only
field-type-to-column authority. A simple one-column plugin inherits the
projection from `AbstractFieldType`: choose `value`, then a column matching the
field name, then the sole declared column. A genuinely multi-column plugin must
override the seam and choose its direct entity value explicitly.

Notable explicit projections are:

| Plugin | Direct entity column | Reason |
|---|---|---|
| `link`, `file`, `image` | `uri` (`varchar`) | Entity surfaces carry the URI string. |
| `entity_reference` | bounded `varchar` | Entity values may be UUID/config identifiers, not only integer PKs. |
| `decimal` | `text` | Preserve lossless decimal text; never coerce through binary float. |
| `classification_label` | `classification_label` (`varchar`, indexed) | Provenance columns remain lifecycle-managed physical state. |

Unknown plugin ids raise `UnknownFieldTypeException`. An ambiguous multi-column
plugin without an explicit projection raises `LogicException`. Neither case
logs and falls back to `text`; DDL is refused.

### Temporary legacy compatibility

The one-release compatibility window retains dedicated plugins for `int`,
`bool`, `uri`, `timestamp`, `map`, and `list_string`. They are deliberately
not blueprint vocabulary and do not alias a canonical plugin. Their entity
value and GraphQL projections preserve the old public shapes; in particular,
the timestamp's generic `jsonSchema()` remains a plain string while its
entity-value schema is a date-time string. It accepts the existing Unix domain
value, stores text, and is serialized as an ISO date-time string. `uri` is the
only legacy id whose old physical shape differed by path: base-table derivation
remains `varchar(2048)` by default (or the definition's `length` setting) while
the former `ColumnSpecMap` primary/revision/translation paths remain `text`.
Callers select that distinction through the explicit `FieldStorageSchemaContext`;
no consumer may invent another context.

## `FieldStorage` interaction

`FieldStorage::Data` vs `FieldStorage::Column` is decided **before** `deriveColumnSpec()` runs: fields marked **`Data`** are not materialized as bundle columns, so **no column spec** is built for them on that path. Registry admission nevertheless validates their entity-value schema; column-stored definitions validate both projections. See `docs/specs/bundle-scoped-storage.md` and `FieldStorage` in `packages/field/src/FieldStorage.php`.

## Foreign keys

Bundle subtables already declare an FK from the subtable’s id column to the base table (`buildBundleSubtableSpec()` in `SqlSchemaHandler`). **`entity_reference`** column specs here are **not** automatically paired with cross-table FK constraints to arbitrary target entity tables; that remains application/migration responsibility unless extended later.

## Related specs

- `docs/specs/bundle-scoped-storage.md` — bundle subtables and `deriveColumnSpec()` introduction.
- `docs/specs/extraction-log.md` — shared-mapper promotion notes.
