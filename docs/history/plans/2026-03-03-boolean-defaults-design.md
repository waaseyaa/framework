# Boolean Field Defaults Design

Fixes GitHub issue #24 (create form defaults all boolean fields to checked).

## Root Cause

Three-layer gap:

1. **Field definitions** (`public/index.php`): No `default` keys on boolean fields
2. **SchemaPresenter** (`packages/api/src/Schema/SchemaPresenter.php`): `buildFieldSchema()` never extracts `$definition['default']`
3. **SchemaForm** (`packages/admin/app/components/schema/SchemaForm.vue`): Create mode leaves `formData = {}`, Toggle receives `''` (empty string) which HTML checkboxes coerce to checked

## Design

**Backend-driven defaults through JSON Schema.**

### Backend

**Field definitions:** Add `'default'` keys to boolean fields in `public/index.php`. `status` gets `1`, `promote` and `sticky` get `0`.

**SchemaPresenter:** Extract `$definition['default']` in `buildFieldSchema()`. For booleans, cast to `(bool)` so JSON Schema emits `"default": true` / `"default": false`.

### Frontend

**SchemaForm:** In create mode (no `entityId`), after `fetchSchema()`, initialize `formData` from each field's `default` value. Convention: boolean fields without a declared default get `false`.

### Convention

Boolean fields default to `false` unless explicitly declared otherwise. This matches HTML checkbox semantics and keeps config minimal.
