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
  '/admin/surface/**': { proxy: `${backendUrl}/admin/surface/**` },
  '/admin/bootstrap': { proxy: `${backendUrl}/admin/bootstrap` },
  '/login': { proxy: `${backendUrl}/login` },
},
```

All `/api/*` requests and specific `/admin/surface/*`, `/admin/bootstrap`, and `/login` routes proxy to the PHP backend defined by `NUXT_BACKEND_URL`. The default is `http://127.0.0.1:8080`, matching the repo's PHP dev server and CI workflows. The PHP backend is served by the built-in PHP server with `public/index.php` as the front controller.

### Base URL

The admin SPA is served under the `/admin/` subpath, configured via `app.baseURL: '/admin/'` in nuxt.config.ts. Playwright E2E tests also use `http://localhost:3000/admin` as the base URL.

### Runtime Config

Exposed via `useRuntimeConfig().public`:

| Key | Env Var | Default | Purpose |
|-----|---------|---------|---------|
| `enableRealtime` | `NUXT_PUBLIC_ENABLE_REALTIME` | `'0'` in dev, `'1'` in production | Disable SSE in dev to avoid php -S single-process request starvation |
| `appName` | `NUXT_PUBLIC_APP_NAME` | `'Waaseyaa'` | Override site name (e.g. "Minoo") |
| `docsUrl` | `NUXT_PUBLIC_DOCS_URL` | `'https://github.com/jonesrussell/waaseyaa'` | Quickstart docs link used by onboarding prompt |
| `baseUrl` | `NUXT_PUBLIC_BASE_URL` | `''` | Base URL for subpath mounting, used by admin plugin for bootstrap resolution |

### Nitro Prerender

`nitro.prerender.failOnError` is set to `false` because `/login` is proxied to PHP during `nuxt generate` and the backend may be unreachable in CI.

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

- Translation files: `packages/admin/app/i18n/en.json`, `packages/admin/app/i18n/fr.json`
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

Translation files: `packages/admin/app/i18n/en.json` (English), `packages/admin/app/i18n/fr.json` (French)

Key categories:
- UI chrome: `app_name`, `dashboard`, `content`, `sidebar_nav`, `toggle_menu`, `language`
- CRUD actions: `save`, `create`, `create_new`, `edit`, `delete`, `back_to_list`, `actions`, `cancel`
- States: `loading`, `saving`
- Feedback: `entity_created`, `entity_saved`, `confirm_delete`
- Pagination: `showing`, `of`, `previous`, `next`, `no_items`
- Errors: `error_generic`, `error_not_found`, `error_page_title`, `error_page_back`, `error_loading_schema`, `error_loading_types`, `error_loading_entities`, `error_deleting`, `error_nav`
- Autocomplete: `autocomplete_placeholder`, `autocomplete_no_results`, `autocomplete_loading`
- Realtime: `realtime_connected`
- Onboarding: `onboarding_title`, `onboarding_body`, `onboarding_use_note`, `onboarding_create_type`, `onboarding_quickstart`
- Type management: `disable_type`, `enable_type`, `type_disabled`, `disable_type_title`, `disable_type_body`, `disable_type_warning`, `disable_anyway`
- Navigation groups: `nav_group_people`, `nav_group_content`, `nav_group_taxonomy`, `nav_group_media`, `nav_group_structure`, `nav_group_workflows`, `nav_group_ai`, `nav_group_events`, `nav_group_community`, `nav_group_knowledge`, `nav_group_language`, `nav_group_ingestion`, `nav_group_other`
- Ingestion widget: `ingest_widget_title`, `ingest_widget_empty`, `ingest_status_pending_review`, `ingest_status_approved`, `ingest_status_rejected`, `ingest_status_failed`
- NC sync: `nc_sync_widget_title`, `nc_sync_last_sync`, `nc_sync_created`, `nc_sync_skipped`, `nc_sync_failed`, `nc_sync_open_dashboard`, `nc_sync_view_teachings`, `nc_sync_view_events`, `na`
- Entity type labels: `entity_type_user`, `entity_type_node`, `entity_type_node_type`, `entity_type_taxonomy_term`, etc.
- Field labels: `field_title`, `field_machine_name`, `field_published`, `field_description`, `field_weight`, `field_email`, etc.
- Parameterized: `create_entity`, `edit_entity` (with `{type}` token)
- Telescope: `telescope_codified_context`, `telescope_cc_sessions`, `telescope_cc_drift_score`, etc.

Token replacement pattern: `t('key', { token: 'value' })` replaces `{token}` in the string.

The `useLanguage` composable also exposes `entityLabel(id, fallback)` for resolving `entity_type_{id}` keys with a fallback to the raw label.

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
      MachineNameInput.vue         # Machine-readable name generator from label
      FileUpload.vue               # File upload input
    telescope/
      ContextHeatmap.vue           # Heatmap visualization of codified context events
      DriftScoreChart.vue          # Drift score indicator (0–100 with color intensity)
      EventStreamViewer.vue        # Expandable event log with collapsible rows
      ValidationReportCard.vue     # Validation report display with severity styling
    IngestSummaryWidget.vue        # Ingestion status counters + NC sync panel
    onboarding/
      OnboardingPrompt.vue         # Onboarding guide prompt
  adapters/
    AdminSurfaceTransportAdapter.ts  # AdminSurface API transport layer
    BootstrapAuthAdapter.ts          # Bootstrap authentication during app init
    JsonApiTransportAdapter.ts       # JSON:API protocol transport
    index.ts                         # Re-exports all adapters
  composables/
    useAdmin.ts                    # Admin panel context & utilities
    useAuth.ts                     # Authentication state & login/logout
    useCodifiedContext.ts          # Codified context session/event tracking
    useEntity.ts                   # JSON:API CRUD + search
    useSchema.ts                   # Schema fetch/cache/sort
    useLanguage.ts                 # i18n
    useNavGroups.ts                # Navigation group rendering & humanize() helper
    useRealtime.ts                 # SSE connection
  pages/
    index.vue                      # Dashboard: catalog-aware onboarding + entity type cards + IngestSummaryWidget
    [entityType]/
      index.vue                    # Entity list (delegates to SchemaList)
      create.vue                   # Entity create form (delegates to SchemaForm)
      [id].vue                     # Entity edit form (delegates to SchemaForm with entityId)
  i18n/
    en.json                        # English translations
    fr.json                        # French translations
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

## Dashboard (`pages/index.vue`)

The dashboard page uses the `useAdmin()` catalog (from the AdminSurface bootstrap endpoint) to render entity type cards. It includes:

1. **Onboarding detection**: On mount, probes for existing content by listing the first listable catalog type (prefers `node_type`). If no content exists, shows `OnboardingPrompt` with links to create a Note, create a custom type, or open the quickstart guide. Paths are computed from catalog capabilities.
2. **IngestSummaryWidget**: Renders ingestion status counters (pending_review, approved, rejected, failed) from the `ingest_log` entity type. Hides silently on 404 (entity type not registered). Each counter links to the filtered ingest_log list. Also includes a North Cloud Search sync panel fetched from `/api/admin/nc-sync-status` with last-sync timestamp, created/skipped/failed counts, and links to the ingestion dashboard, teachings, and events.
3. **Entity type card grid**: Renders a card for each catalog entry using `entityLabel(et.id, et.label)` for i18n-aware labels.

Error handling uses `TransportError` from `~/contracts/transport` to distinguish 404s from other failures.

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

The build step verifies TypeScript compilation and Nuxt module resolution. Build scripts from `packages/admin/package.json`:
- `dev`: `nuxt dev` (development server with HMR)
- `build`: `nuxt build` (production build)
- `generate`: `nuxt generate` (static site generation)
- `preview`: `nuxt preview` (preview production build)
- `postinstall`: `nuxt prepare` (generate `.nuxt` types)

### E2E Testing (Playwright)

Playwright config: `packages/admin/playwright.config.ts`. Tests live in `packages/admin/e2e/`.

- Base URL: `http://localhost:3000/admin` (matches the `/admin/` base URL)
- Browsers: Chromium, Firefox
- Web server: auto-starts `npm run dev` with 120s timeout; reuses existing server outside CI
- CI: `forbidOnly` enforced, 2 retries, trace on first retry; dashboard tests use `networkidle` wait and role-based selectors for hydration stability
- Reports: HTML reporter; `playwright-report/` and `test-results/` are gitignored

### Backend Testing

Backend JSON:API and schema endpoints are tested via PHPUnit integration tests in `tests/Integration/PhaseN/`. The admin SPA relies on these endpoints being correct.

## File Reference

| File | Purpose |
|------|---------|
| `packages/admin/package.json` | NPM package definition |
| `packages/admin/nuxt.config.ts` | Nuxt configuration, API proxy |
| `packages/admin/app/app.vue` | Root component |
| `packages/admin/app/layouts/default.vue` | Default layout (AdminShell wrapper) |
| `packages/admin/app/composables/useAdmin.ts` | Admin panel context & utilities |
| `packages/admin/app/composables/useAuth.ts` | Authentication state & login/logout |
| `packages/admin/app/composables/useCodifiedContext.ts` | Codified context session/event tracking |
| `packages/admin/app/composables/useEntity.ts` | JSON:API CRUD composable |
| `packages/admin/app/composables/useSchema.ts` | Schema fetching and caching |
| `packages/admin/app/composables/useLanguage.ts` | i18n composable |
| `packages/admin/app/composables/useNavGroups.ts` | Navigation group rendering |
| `packages/admin/app/composables/useRealtime.ts` | SSE connection composable |
| `packages/admin/app/adapters/AdminSurfaceTransportAdapter.ts` | AdminSurface API transport |
| `packages/admin/app/adapters/JsonApiTransportAdapter.ts` | JSON:API protocol transport |
| `packages/admin/app/adapters/BootstrapAuthAdapter.ts` | Bootstrap auth during init |
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
| `packages/admin/app/components/IngestSummaryWidget.vue` | Ingestion status counters + NC sync panel |
| `packages/admin/app/i18n/en.json` | English translation strings |
| `packages/admin/app/i18n/fr.json` | French translation strings |
| `packages/admin/playwright.config.ts` | Playwright E2E test configuration |
