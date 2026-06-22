# Config Entity Machine Name Fix

Fixes GitHub issue #27 (config entity creation leaves ID field empty, causing 404 on load).

## Root Cause

Config entities (node_type, taxonomy_vocabulary, media_type, menu, workflow, pipeline) use string machine name IDs with no UUID key. Multiple layers assume integer auto-increment IDs and UUID presence:

1. **SchemaPresenter** marks ID field as `readOnly: true, x-widget: 'hidden'` — form never shows or submits it.
2. **EntityBase** auto-generates UUID even when no `uuid` key exists — creates spurious UUID.
3. **SqlEntityStorage::save()** strips null ID assuming auto-increment — but config entity IDs are strings, not auto-increment.
4. **ResourceSerializer** uses `entity->uuid()` as resource ID when non-empty — returns spurious UUID instead of machine name.
5. **loadByIdOrUuid()** receives UUID from list, tries UUID lookup on entity type with no UUID column, fails.

## Design

### Backend (PHP)

**EntityBase** — Only auto-generate UUID when `uuid` key is defined in `entityKeys`.

**SqlEntityStorage::save()** — Only skip ID key when null (auto-increment case). When ID is a non-null string, include it in INSERT. Don't cast returned ID to `(int)` for string-ID entities.

**ResourceSerializer** — Check if entity type has `uuid` key. If not, use `entity->id()` directly instead of `entity->uuid()`.

**SchemaPresenter** — When entity type has no `uuid` key (config entity), render ID field as `x-widget: 'machine_name'`, type `string`, NOT readOnly. Add `x-source-field` pointing to the label key so the frontend knows which field to auto-generate from. For edit schemas (entity provided), mark readOnly to prevent renaming.

### Frontend (Nuxt/Vue)

**SchemaField.vue** — Add `machine_name` widget: text input with `[a-z0-9_]+` pattern. Auto-slugifies from the source field value (lowercase, spaces/hyphens to underscores, strip non-alphanumeric). User can manually override. Disabled when readOnly (edit mode).

### End-to-End Flow

1. User navigates to `/node_type/create`, types "Blog Post" in Name field.
2. Machine name field auto-fills with `blog_post`.
3. Form submits `{ name: "Blog Post", type: "blog_post", ... }`.
4. `SqlEntityStorage::save()` inserts with string ID `blog_post`.
5. `ResourceSerializer` uses `blog_post` as JSON:API resource ID.
6. SPA redirects to `/node_type/blog_post` — loads successfully.

### Affected Entity Types

All 6 config entity types without UUID keys: `node_type`, `taxonomy_vocabulary`, `media_type`, `menu`, `workflow`, `pipeline`.
