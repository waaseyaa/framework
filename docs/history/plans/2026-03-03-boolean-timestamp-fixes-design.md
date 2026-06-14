# Boolean + Timestamp Fixes Design

Fixes GitHub issues #18 (booleans render as 1/0) and #19 (timestamps show as 0).

## Root Causes

- **#18:** `ResourceSerializer` passes `toArray()` values to JSON with no type conversion. SQLite stores booleans as integers; JSON encodes them as `1`/`0`.
- **#19a:** `Node.php` defaults `created`/`changed` to `0`. No auto-population on save.
- **#19b:** API sends raw integer `0` instead of ISO 8601 string. Frontend datetime widget expects `YYYY-MM-DDTHH:mm`.

## Design

### Backend

**ResourceSerializer — type-aware casting** (`packages/api/src/ResourceSerializer.php`)

Add `castAttributes()` using entity type field definitions:
- `boolean` → `(bool)`
- `timestamp`/`datetime` → Unix timestamp to ISO 8601 string, `null` if `0`

Field definitions available via `EntityTypeInterface::getFieldDefinitions()`.

**SqlEntityStorage — timestamp auto-population** (`packages/entity-storage/src/SqlEntityStorage.php`)

In `save()`, before `splitForStorage()`:
- If `isNew()` and `created` field value is `0` → set to `time()`
- Always set `changed` to `time()` on every save

Needs field definitions to identify timestamp fields. Storage receives entity type definition via constructor.

**Node.php** — no changes. Default `0` signals "not yet set".

### Frontend

**SchemaList.vue — formatted cell rendering** (`packages/admin/app/components/schema/SchemaList.vue`)

Format list cells based on schema type:
- `type: boolean` → checkmark/dash or Yes/No
- `format: date-time` → `toLocaleString()`

**No widget changes needed.** Once API returns proper `true`/`false` and ISO 8601 strings, Toggle and DateTimeInput work correctly.

## Decision: Casting at API boundary

Type casting happens in `ResourceSerializer` (API layer), not in storage. Rationale:
- Single fix point, minimal change
- Storage stays type-agnostic
- Can push casting deeper later if other consumers need it
