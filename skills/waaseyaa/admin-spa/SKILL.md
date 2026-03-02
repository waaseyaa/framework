---
name: waaseyaa:admin-spa
description: Use when working with the admin interface, Vue composables, schema-driven forms, SSE integration, i18n translations, or files in packages/admin/
---

# Admin SPA Specialist

## Scope

This skill covers the Nuxt 3 admin SPA at `packages/admin/`. Use it when:
- Adding or modifying Vue components in `packages/admin/app/components/`
- Working with composables in `packages/admin/app/composables/`
- Adding pages to `packages/admin/app/pages/`
- Modifying i18n translations in `packages/admin/app/i18n/en.json`
- Working on schema-driven form rendering
- Integrating SSE real-time updates
- Adding or modifying field widgets
- Debugging API proxy or JSON:API communication issues

Out of scope: PHP backend code, entity storage, access policies (see project-level CLAUDE.md for those).

## Key Interfaces

### JsonApiResource / JsonApiDocument (`packages/admin/app/composables/useEntity.ts`)

```ts
interface JsonApiResource {
  type: string; id: string; attributes: Record<string, any>
  relationships?: Record<string, any>; links?: Record<string, string>; meta?: Record<string, any>
}
interface JsonApiDocument {
  jsonapi: { version: string }; data: JsonApiResource | JsonApiResource[] | null
  errors?: Array<{ status: string; title: string; detail?: string }>
}
```

### SchemaProperty / EntitySchema (`packages/admin/app/composables/useSchema.ts`)

```ts
interface SchemaProperty {
  type: string; description?: string; format?: string; readOnly?: boolean
  enum?: string[]; minimum?: number; maximum?: number; maxLength?: number
  'x-widget'?: string; 'x-label'?: string; 'x-description'?: string
  'x-weight'?: number; 'x-required'?: boolean; 'x-enum-labels'?: Record<string, string>
  'x-target-type'?: string; 'x-access-restricted'?: boolean
}
interface EntitySchema {
  $schema: string; title: string; description: string; type: string
  'x-entity-type': string; 'x-translatable': boolean; 'x-revisionable': boolean
  properties: Record<string, SchemaProperty>; required?: string[]
}
```

### BroadcastMessage (`packages/admin/app/composables/useRealtime.ts`)

```ts
interface BroadcastMessage {
  channel: string; event: string; data: Record<string, unknown>; timestamp: number
}
```

### Widget Component Props Contract

Every widget in `packages/admin/app/components/widgets/` must accept:

```ts
defineProps<{
  modelValue: any
  label?: string
  description?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
}>()
defineEmits<{ 'update:modelValue': [value: any] }>()
```

## Architecture

### Data Flow

1. **Routing**: Nuxt file-based routing. Pages at `packages/admin/app/pages/`. Dynamic segments use `[param]` syntax.
2. **Layout**: `app.vue` -> `layouts/default.vue` -> `LayoutAdminShell` (topbar + sidebar + main content area).
3. **Navigation**: `NavBuilder` fetches entity types from `GET /api/entity-types` and renders sidebar links.
4. **Schema loading**: `useSchema(entityType).fetch()` calls `GET /api/schema/{entityType}`, caches in module-level `Map`.
5. **Form rendering**: `SchemaForm` iterates `sortedProperties(true)` -> `SchemaField` resolves widget from `x-widget` -> widget component renders the input.
6. **CRUD**: `useEntity()` provides `list`, `get`, `create`, `update`, `remove`, `search` against `GET/POST/PATCH/DELETE /api/{type}[/{id}]`.
7. **Real-time**: `useRealtime(['admin'])` opens SSE to `GET /api/broadcast?channels=admin`. `SchemaList` watches messages and auto-refreshes on `entity.saved` / `entity.deleted` events.

### API Proxy

All `/api/*` requests are proxied to `http://localhost:8081/api` via Nuxt config. This is configured in `packages/admin/nuxt.config.ts` under both `nitro.devProxy` (dev) and `routeRules` (production).

### Widget Resolution

`SchemaField` (`packages/admin/app/components/schema/SchemaField.vue`) maps `x-widget` to components:

| x-widget             | Component resolved via                   |
|----------------------|------------------------------------------|
| `text` (default)     | `resolveComponent('WidgetsTextInput')`   |
| `email`, `url`, `password`, `image`, `file` | `WidgetsTextInput` |
| `textarea`           | `WidgetsTextArea`                        |
| `richtext`           | `WidgetsRichText`                        |
| `number`             | `WidgetsNumberInput`                     |
| `boolean`            | `WidgetsToggle`                          |
| `select`             | `WidgetsSelect`                          |
| `datetime`           | `WidgetsDateTimeInput`                   |
| `entity_autocomplete`| `WidgetsEntityAutocomplete`              |
| `hidden`             | `WidgetsHiddenField`                     |

Fallback for unknown widgets: `WidgetsTextInput`.

### Access-Restricted vs ReadOnly

Two distinct concepts in schema properties:
- **System readOnly** (`readOnly: true`, no `x-access-restricted`): Fields like `id`, `uuid`. Excluded from forms entirely by `sortedProperties(true)`.
- **Access-restricted** (`readOnly: true` + `x-access-restricted: true`): Fields the user can view but not edit. Rendered as disabled inputs. `SchemaForm` guards the `@update:model-value` handler to prevent writes.

### SSE Reconnection Strategy

`useRealtime` (`packages/admin/app/composables/useRealtime.ts`):
- Max retries: 10
- Backoff formula: `Math.min(3000 * Math.pow(2, retryCount - 1), 30000)`
- Retry counter resets on successful connection
- Auto-disconnects on component unmount via `onUnmounted`
- `reconnect()` resets error state and retry counter, then reconnects

## Common Mistakes

### Using `useFetch` instead of `$fetch`

All composables use `$fetch` (imperative) not `useFetch` (SSR-aware). The admin SPA fetches data in `onMounted` callbacks and event handlers, not during SSR. Using `useFetch` would cause hydration issues and unwanted caching behavior.

### Forgetting `x-access-restricted` guard in form handlers

When adding new form submission logic, always check `x-access-restricted` before writing to `formData`. The pattern in `SchemaForm`:
```ts
@update:model-value="(val: any) => { if (!fieldSchema['x-access-restricted']) formData[fieldName] = val }"
```
Omitting this guard allows users to modify access-restricted fields via browser devtools.

### Widget component naming

Nuxt auto-import resolves components by directory path in PascalCase. A widget at `components/widgets/TextInput.vue` is referenced as `WidgetsTextInput` (not `TextInput`). When adding widgets to `widgetMap` in `SchemaField.vue`, use `resolveComponent('Widgets{Name}')`.

### Schema cache invalidation

`useSchema` uses a module-level `Map` for caching. If you modify schema data on the backend and the frontend still shows stale data, call `invalidate()` on the relevant `useSchema` instance. The cache is never automatically invalidated.

### Mutating the messages array directly

`useRealtime` replaces the array reference on each message (`messages.value = [...messages.value.slice(-99), msg]`). Do not push to `messages.value` directly -- Vue reactivity tracks the ref assignment, not array mutations.

### Adding translations without updating en.json

All user-facing strings must go through `useLanguage().t('key')`. When adding new UI text, add the key to `packages/admin/app/i18n/en.json` first. The `t()` function falls back to the key string itself if no translation exists, which can mask missing translations.

### RichText sanitization bypass

The `RichText` widget emits raw `innerHTML` from the contenteditable div. The sanitization runs on render (via `computed`), not on emit. Server-side sanitization is required. Do not trust client-side sanitization for security.

### EntityAutocomplete hardcoded label field

`EntityAutocomplete` currently hardcodes `labelField` to `'title'`. For entity types where the label field is not `title`, this must be updated. The schema's `x-target-type` is used but there is no `x-label-field` extension yet.

### SSE endpoint dependency

`useRealtime` connects to `GET /api/broadcast?channels=admin`. This endpoint must be wired in `public/index.php`. If the endpoint is not available, the composable will retry 10 times with exponential backoff and then show an error. The frontend works without SSE (graceful degradation).

## Testing Patterns

### Build Verification

No frontend test framework is configured. TypeScript correctness is verified via build:

```bash
cd packages/admin && npm run build
```

This catches type errors, missing imports, and component resolution failures.

### Manual Testing Workflow

1. Start PHP backend: `php -S localhost:8081 public/index.php`
2. Start Nuxt dev server: `cd packages/admin && npm run dev`
3. Access admin at `http://localhost:3000/`
4. Verify: dashboard loads entity types, list views show data, forms render widgets, autocomplete searches, SSE connection indicator appears

### Adding a New Widget

1. Create `packages/admin/app/components/widgets/{Name}.vue` implementing the widget props contract
2. Add entry to `widgetMap` in `packages/admin/app/components/schema/SchemaField.vue`
3. Ensure the PHP `SchemaPresenter` sets the corresponding `x-widget` value on fields
4. Run `cd packages/admin && npm run build` to verify compilation

### Adding a New Composable

1. Create `packages/admin/app/composables/use{Name}.ts`
2. Export a function named `use{Name}` returning reactive refs and functions
3. Nuxt auto-imports from `composables/` -- no explicit import needed in components
4. Run `cd packages/admin && npm run build` to verify

### Adding a New Page

1. Create file under `packages/admin/app/pages/` following Nuxt file-based routing conventions
2. Use `[param]` for dynamic route segments
3. Import composables as needed (auto-imported by Nuxt)
4. Wrap content in the default layout (automatic via `layouts/default.vue`)
5. Add navigation link in `NavBuilder` if the page should appear in the sidebar

### Adding i18n Keys

1. Add the key-value pair to `packages/admin/app/i18n/en.json`
2. Use `const { t } = useLanguage()` in the component
3. Reference with `t('your_key')` or `t('your_key', { param: 'value' })` for replacements

## Key Files

| File | Role |
|------|------|
| `packages/admin/nuxt.config.ts` | Nuxt config, API proxy setup |
| `packages/admin/app/composables/useEntity.ts` | JSON:API CRUD + search |
| `packages/admin/app/composables/useSchema.ts` | Schema fetching, caching, field sorting |
| `packages/admin/app/composables/useLanguage.ts` | i18n with token replacement |
| `packages/admin/app/composables/useRealtime.ts` | SSE connection management |
| `packages/admin/app/components/schema/SchemaForm.vue` | Schema-driven entity form |
| `packages/admin/app/components/schema/SchemaField.vue` | Widget resolver (x-widget -> component) |
| `packages/admin/app/components/schema/SchemaList.vue` | Entity list with SSE auto-refresh |
| `packages/admin/app/components/widgets/EntityAutocomplete.vue` | Typeahead entity reference widget |
| `packages/admin/app/components/layout/AdminShell.vue` | Layout shell + global CSS |
| `packages/admin/app/components/layout/NavBuilder.vue` | Dynamic sidebar navigation |
| `packages/admin/app/i18n/en.json` | English translations |

## Related Specs

- `docs/specs/admin-spa.md` -- Full specification with all interfaces, widget mapping, and component details
