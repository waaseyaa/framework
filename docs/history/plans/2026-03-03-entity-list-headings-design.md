# Entity List Headings Design

Fixes GitHub issue #26 (entity list page headings show machine names instead of labels).

## Root Cause

All three page components (`index.vue`, `create.vue`, `[id].vue`) use `{{ entityType }}` — the route param (machine name like "node") — as the heading. The entity type label ("Content") is available via `useSchema()` → `schema.value.title` but isn't used.

## Design

Use `useSchema()` in each page component to get the label. The schema is already cached by `SchemaList`/`SchemaForm`, so no extra API calls.

- `index.vue`: heading changes from `{{ entityType }}` to `{{ entityLabel }}`
- `create.vue`: heading changes from `Create {{ entityType }}` to `Create {{ entityLabel }}`
- `[id].vue`: heading changes from `Edit {{ entityType }} #{{ entityId }}` to `Edit {{ entityLabel }} #{{ entityId }}`

Where `entityLabel` is `schema.value?.title ?? entityType` (fallback to machine name while loading).
