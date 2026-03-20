# Admin SPA

## Package

- Path: `packages/admin/`
- Package name: `@waaseyaa/admin` (private, version 0.1.0)
- Entry point: `packages/admin/app/app.vue` wraps `<NuxtLayout>` + `<NuxtPage />`
- Default layout: `packages/admin/app/layouts/default.vue` renders `<LayoutAdminShell>`
- Source directory: `packages/admin/app/` (configured via `srcDir: 'app/'` in nuxt.config.ts)

## Tech Stack

| Dependency     | Version   | Purpose                         |
|----------------|-----------|---------------------------------|
| Nuxt           | ^3.15.0   | SSR/SPA framework, file-based routing, auto-imports |
| Vue            | ^3.5.0    | Composition API, reactivity     |
| vue-router     | ^4.5.0    | Client-side routing             |
| TypeScript     | ^5.6.0    | Type checking (devDependency)   |
| @types/node    | ^22.0.0   | Node type definitions           |

No CSS framework. Styles are defined in `packages/admin/app/components/layout/AdminShell.vue` as global CSS using CSS custom properties (`--color-primary`, `--color-surface`, etc.).

## API Proxy

Configured in `packages/admin/nuxt.config.ts`:

```ts
const backendUrl = process.env.NUXT_BACKEND_URL ?? 'http://127.0.0.1:8080'

routeRules: {
  '/api/**': { proxy: `${backendUrl}/api/**` },
  '/admin/**': { proxy: `${backendUrl}/admin/**` },
},
```

All `/api/*` and `/admin/*` requests proxy to the PHP backend defined by `NUXT_BACKEND_URL`. The default is `http://127.0.0.1:8080`, matching the repo's PHP dev server and CI workflows. The PHP backend is served by the built-in PHP server with `public/index.php` as the front controller.

## Composables

All composables are in `packages/admin/app/composables/`. Nuxt auto-imports them.

### useEntity (`packages/admin/app/composables/useEntity.ts`)

CRUD operations against the JSON:API backend. Returns plain functions (not reactive state).

```ts
function useEntity(): {
  list(type: string, query?: { page?: { offset: number; limit: number }; sort?: string }):
    Promise<{ data: JsonApiResource[]; meta: Record<string, any>; links: Record<string, string> }>
  get(type: string, id: string): Promise<JsonApiResource>
  create(type: string, attributes: Record<string, any>): Promise<JsonApiResource>
  update(type: string, id: string, attributes: Record<string, any>): Promise<JsonApiResource>
  remove(type: string, id: string): Promise<void>
  search(type: string, labelField: string, query: string, limit?: number): Promise<JsonApiResource[]>
}
```

Key types:
```ts
interface JsonApiResource {
  type: string; id: string; attributes: Record<string, any>
  relationships?: Record<string, any>; links?: Record<string, string>; meta?: Record<string, any>
}
interface JsonApiDocument {
  jsonapi: { version: string }; data: JsonApiResource | JsonApiResource[] | null
  errors?: Array<{ status: string; title: string; detail?: string }>
  meta?: Record<string, any>; links?: Record<string, string>
}
```

- `list()` uses offset-based pagination: `page[offset]`, `page[limit]`
- `search()` uses `filter[{labelField}][operator]=STARTS_WITH` with 250ms debounce on the widget side. Minimum 2 characters required.
- All methods use Nuxt `$fetch` (not `useFetch`) for imperative data fetching.

### useSchema (`packages/admin/app/composables/useSchema.ts`)

Fetches and caches JSON Schema for an entity type. Drives all form rendering.

```ts
function useSchema(entityType: string): {
  schema: Ref<EntitySchema | null>; loading: Ref<boolean>; error: Ref<string | null>
  fetch(): Promise<void>; invalidate(): void
  sortedProperties(editable?: boolean): [string, SchemaProperty][]
}
```

Key types:
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

- Endpoint: `GET /api/schema/{entityType}` returns `{ meta: { schema: EntitySchema } }`
- Module-level `Map<string, EntitySchema>` cache. Call `invalidate()` to clear a single type.
- `sortedProperties(true)` filters out system `readOnly` fields (id, uuid) and hidden widgets, but keeps `x-access-restricted` fields (rendered as disabled inputs). Sorted by `x-weight` ascending.
- `sortedProperties(false)` returns all properties sorted by weight.

### useLanguage (`packages/admin/app/composables/useLanguage.ts`)

Simple i18n with token replacement.

```ts
function useLanguage(): {
  t(key: string, replacements?: Record<string, string>): string
  locale: ComputedRef<string>; setLocale(locale: string): void
}
```

- Translation file: `packages/admin/app/i18n/en.json`
- Replacement syntax: `{token}` in translation strings
- Module-level `currentLocale` ref shared across all callers
- Falls back to the key itself when no translation is found

### useRealtime (`packages/admin/app/composables/useRealtime.ts`)

Server-Sent Events connection for real-time entity updates.

```ts
function useRealtime(channels?: string[]): {
  messages: Ref<BroadcastMessage[]>; connected: Ref<boolean>; error: Ref<string | null>
  disconnect(): void; reconnect(): void
}
interface BroadcastMessage {
  channel: string; event: string; data: Record<string, unknown>; timestamp: number
}
```

- Endpoint: `GET /api/broadcast?channels={comma-separated}` (SSE)
- Default channel: `['admin']`
- Auto-connects on instantiation; auto-disconnects on `onUnmounted`
- Exponential backoff reconnect: delay = `min(3000 * 2^(retryCount-1), 30000)`, max 10 retries
- Message buffer: last 100 messages (ring buffer via `slice(-99)`)
- Event types: `entity.saved`, `entity.deleted` (used by SchemaList for auto-refresh)

## Schema-Driven Forms

The form rendering pipeline:

1. `SchemaForm` calls `useSchema(entityType).fetch()` to get the JSON Schema
2. `sortedProperties(true)` returns editable fields sorted by `x-weight`
3. For each field, `SchemaField` resolves the widget component from `x-widget`
4. Each widget receives `modelValue`, `label`, `description`, `required`, `disabled`, `schema`

### Widget Resolution (`packages/admin/app/components/schema/SchemaField.vue`)

`x-widget` value maps to a component via `widgetMap`:

| x-widget             | Component                  | HTML element         |
|----------------------|----------------------------|----------------------|
| `text` (default)     | `WidgetsTextInput`         | `<input type="text">` |
| `email`              | `WidgetsTextInput`         | `<input type="email">` |
| `url`                | `WidgetsTextInput`         | `<input type="url">` |
| `password`           | `WidgetsTextInput`         | `<input type="text">` |
| `textarea`           | `WidgetsTextArea`          | `<textarea>`         |
| `richtext`           | `WidgetsRichText`          | `<div contenteditable>` |
| `number`             | `WidgetsNumberInput`       | `<input type="number">` |
| `boolean`            | `WidgetsToggle`            | `<input type="checkbox">` |
| `select`             | `WidgetsSelect`            | `<select>`           |
| `datetime`           | `WidgetsDateTimeInput`     | `<input type="datetime-local">` |
| `entity_autocomplete`| `WidgetsEntityAutocomplete`| `<input type="text">` + dropdown |
| `hidden`             | `WidgetsHiddenField`       | (renders nothing)    |
| `image`, `file`      | `WidgetsTextInput`         | `<input type="text">` |

### Access-Restricted Fields

When the PHP `SchemaPresenter` marks a field with `readOnly: true` + `x-access-restricted: true`:
- `sortedProperties(true)` keeps the field (unlike system readOnly which is excluded)
- `SchemaForm` passes `:disabled="!!fieldSchema['x-access-restricted']"`
- The `@update:model-value` handler guards: `if (!fieldSchema['x-access-restricted']) formData[fieldName] = val`
- Result: field is visible but not editable in the UI

### RichText Sanitization

`WidgetsRichText` (`packages/admin/app/components/widgets/RichText.vue`) sanitizes HTML client-side using DOMParser. Allowed tags: `P, BR, B, I, U, STRONG, EM, A, UL, OL, LI, H1-H6, BLOCKQUOTE, PRE, CODE, SUB, SUP, HR`. Links restricted to `http://`, `https://`, or `/` prefixes.

### EntityAutocomplete Widget

`WidgetsEntityAutocomplete` (`packages/admin/app/components/widgets/EntityAutocomplete.vue`):
- Uses `x-target-type` from schema to determine which entity type to search
- Calls `useEntity().search(targetType, 'title', query)` with 250ms debounce
- Keyboard navigation: ArrowUp/ArrowDown/Enter/Escape
- ARIA: `role="combobox"`, `aria-expanded`, `aria-autocomplete="list"`, dropdown has `role="listbox"`, items have `role="option"`

## SSE Integration

### Frontend Flow

1. `SchemaList` instantiates `useRealtime(['admin'])` on mount
2. `useRealtime` opens `EventSource` to `GET /api/broadcast?channels=admin`
3. Incoming messages are parsed as JSON and appended to `messages` ref
4. `SchemaList` watches `messages` and auto-refreshes entity list when:
   - `latest.event === 'entity.saved'` or `'entity.deleted'`
   - `latest.data?.entityType === props.entityType`

### Connection Status

- Green pulsing dot indicator in pagination bar when connected
- Error message with reconnect button when connection lost after max retries
- CSS animation: `@keyframes pulse` on `.sse-status`

## i18n

Translation file: `packages/admin/app/i18n/en.json`

Key categories:
- UI chrome: `app_name`, `dashboard`, `content`, `sidebar_nav`
- CRUD actions: `save`, `create`, `create_new`, `edit`, `delete`, `back_to_list`
- States: `loading`, `saving`
- Feedback: `entity_created`, `entity_saved`, `confirm_delete`
- Pagination: `showing`, `of`, `previous`, `next`, `no_items`
- Errors: `error_generic`, `error_not_found`, `error_loading_schema`, `error_loading_types`, `error_loading_entities`, `error_deleting`, `error_nav`
- Autocomplete: `autocomplete_placeholder`, `autocomplete_no_results`, `autocomplete_loading`
- Realtime: `realtime_connected`

Token replacement pattern: `t('key', { token: 'value' })` replaces `{token}` in the string.

## Component Patterns

### Directory Structure

```
packages/admin/app/
  app.vue                          # Root: <NuxtLayout> + <NuxtPage />
  layouts/
    default.vue                    # Wraps content in <LayoutAdminShell>
  components/
    layout/
      AdminShell.vue               # Top bar + sidebar + content area + global styles
      NavBuilder.vue               # Dynamic sidebar nav from /api/entity-types
    schema/
      SchemaForm.vue               # Entity create/edit form driven by JSON Schema
      SchemaField.vue              # Single field: resolves x-widget to widget component
      SchemaList.vue               # Entity list table with sort, pagination, SSE auto-refresh
    widgets/
      TextInput.vue                # text/email/url/password/image/file
      TextArea.vue                 # textarea
      RichText.vue                 # contenteditable with HTML sanitization
      NumberInput.vue              # number with min/max
      Toggle.vue                   # checkbox for booleans
      Select.vue                   # dropdown from enum + x-enum-labels
      DateTimeInput.vue            # datetime-local
      EntityAutocomplete.vue       # Typeahead search for entity references
      HiddenField.vue              # Renders nothing (excluded from editable forms)
  composables/
    useEntity.ts                   # JSON:API CRUD + search
    useSchema.ts                   # Schema fetch/cache/sort
    useLanguage.ts                 # i18n
    useRealtime.ts                 # SSE connection
  pages/
    index.vue                      # Dashboard: entity type cards
    [entityType]/
      index.vue                    # Entity list (delegates to SchemaList)
      create.vue                   # Entity create form (delegates to SchemaForm)
      [id].vue                     # Entity edit form (delegates to SchemaForm with entityId)
  i18n/
    en.json                        # English translations
```

### Naming Conventions

- Components use PascalCase in Nuxt auto-import paths: `LayoutAdminShell`, `SchemaForm`, `WidgetsTextInput`
- Composables follow Vue convention: `use{Name}` returning an object of refs and functions
- Pages use Nuxt file-based routing with `[param]` dynamic segments

### Widget Interface Contract

Every widget component must accept these props:
```ts
{
  modelValue: any       // Current field value
  label?: string        // Human label from x-label or field name
  description?: string  // Help text from x-description or description
  required?: boolean    // From x-required
  disabled?: boolean    // True when x-access-restricted
  schema?: SchemaProperty  // Full schema property for widget-specific behavior
}
```
And emit: `'update:modelValue'` with the new value.

## Routing

File-based routing via Nuxt 3:

| Route                    | Page File                                | Purpose              |
|--------------------------|------------------------------------------|----------------------|
| `/`                      | `pages/index.vue`                        | Dashboard            |
| `/:entityType`           | `pages/[entityType]/index.vue`           | Entity list          |
| `/:entityType/create`    | `pages/[entityType]/create.vue`          | Create form          |
| `/:entityType/:id`       | `pages/[entityType]/[id].vue`            | Edit form            |

## Accessibility

- Skip-to-content link: `<a href="#main-content" class="skip-link">`
- ARIA landmarks: `role="banner"` (topbar), `role="navigation"` (sidebar), `role="main"` (content)
- Sidebar label: `aria-label` bound to `t('sidebar_nav')`
- Autocomplete: full `combobox` pattern with `listbox`/`option` roles
- Delete buttons include entity label in `aria-label`
- Live region: `<div role="status" aria-live="polite">` announces pagination changes
- Screen-reader-only class: `.sr-only` for visually hidden announcements
- Responsive: sidebar collapses to off-canvas drawer below 768px with overlay

## Build & Testing

### Build

```bash
cd packages/admin && npm run build
```

No dedicated test framework. The build step verifies TypeScript compilation and Nuxt module resolution. Build scripts from `packages/admin/package.json`:
- `dev`: `nuxt dev` (development server with HMR)
- `build`: `nuxt build` (production build)
- `generate`: `nuxt generate` (static site generation)
- `preview`: `nuxt preview` (preview production build)
- `postinstall`: `nuxt prepare` (generate `.nuxt` types)

### Backend Testing

Backend JSON:API and schema endpoints are tested via PHPUnit integration tests in `tests/Integration/PhaseN/`. The admin SPA relies on these endpoints being correct.

## File Reference

| File | Purpose |
|------|---------|
| `packages/admin/package.json` | NPM package definition |
| `packages/admin/nuxt.config.ts` | Nuxt configuration, API proxy |
| `packages/admin/app/app.vue` | Root component |
| `packages/admin/app/layouts/default.vue` | Default layout (AdminShell wrapper) |
| `packages/admin/app/composables/useEntity.ts` | JSON:API CRUD composable |
| `packages/admin/app/composables/useSchema.ts` | Schema fetching and caching |
| `packages/admin/app/composables/useLanguage.ts` | i18n composable |
| `packages/admin/app/composables/useRealtime.ts` | SSE connection composable |
| `packages/admin/app/components/layout/AdminShell.vue` | Shell layout + global CSS |
| `packages/admin/app/components/layout/NavBuilder.vue` | Dynamic sidebar navigation |
| `packages/admin/app/components/schema/SchemaForm.vue` | Schema-driven entity form |
| `packages/admin/app/components/schema/SchemaField.vue` | Widget resolver for a single field |
| `packages/admin/app/components/schema/SchemaList.vue` | Entity list with sort/pagination/SSE |
| `packages/admin/app/components/widgets/TextInput.vue` | Text/email/url input widget |
| `packages/admin/app/components/widgets/TextArea.vue` | Textarea widget |
| `packages/admin/app/components/widgets/RichText.vue` | Contenteditable rich text widget |
| `packages/admin/app/components/widgets/NumberInput.vue` | Number input widget |
| `packages/admin/app/components/widgets/Toggle.vue` | Checkbox toggle widget |
| `packages/admin/app/components/widgets/Select.vue` | Dropdown select widget |
| `packages/admin/app/components/widgets/DateTimeInput.vue` | Datetime-local input widget |
| `packages/admin/app/components/widgets/EntityAutocomplete.vue` | Typeahead entity reference widget |
| `packages/admin/app/components/widgets/HiddenField.vue` | Hidden field (renders nothing) |
| `packages/admin/app/pages/index.vue` | Dashboard page |
| `packages/admin/app/pages/[entityType]/index.vue` | Entity list page |
| `packages/admin/app/pages/[entityType]/create.vue` | Entity create page |
| `packages/admin/app/pages/[entityType]/[id].vue` | Entity edit page |
| `packages/admin/app/i18n/en.json` | English translation strings |
