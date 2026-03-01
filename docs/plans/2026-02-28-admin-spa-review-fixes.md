# Admin SPA PR Review Fixes Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix all critical and important issues found in the PR review of the Nuxt 3 admin SPA and PHP HTTP front controller, plus fix inaccurate comments.

**Architecture:** Direct edits to existing files — no new architectural patterns introduced. PHP fixes harden the HTTP front controller. Vue/TS fixes add error handling, sanitization, and correctness to the admin SPA.

**Tech Stack:** PHP 8.3+, Vue 3 + Nuxt 3, TypeScript

---

## Task 1: Fix XSS via `v-html` in RichText widget

**Files:**
- Modify: `packages/admin/app/components/widgets/RichText.vue`

**Context:** The `v-html` directive renders raw HTML from `modelValue` without sanitization. Since `modelValue` comes from API responses (entity attributes from the database), stored malicious HTML/JS executes in the admin context. Fix by sanitizing with a simple DOM-based approach (no extra dependency needed — `contenteditable` already requires a browser).

**Step 1: Implement sanitization**

Replace the entire file contents of `packages/admin/app/components/widgets/RichText.vue` with:

```vue
<script setup lang="ts">
import type { SchemaProperty } from '~/composables/useSchema'

defineProps<{
  modelValue: string
  label?: string
  description?: string
  required?: boolean
  disabled?: boolean
  schema?: SchemaProperty
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const allowedTags = new Set([
  'P', 'BR', 'B', 'I', 'U', 'STRONG', 'EM', 'A',
  'UL', 'OL', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
  'BLOCKQUOTE', 'PRE', 'CODE', 'SUB', 'SUP', 'HR',
])

function sanitizeHtml(html: string): string {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html, 'text/html')
  function walk(node: Node): string {
    if (node.nodeType === Node.TEXT_NODE) return node.textContent ?? ''
    if (node.nodeType !== Node.ELEMENT_NODE) return ''
    const el = node as Element
    if (!allowedTags.has(el.tagName)) {
      return Array.from(el.childNodes).map(walk).join('')
    }
    const tag = el.tagName.toLowerCase()
    const children = Array.from(el.childNodes).map(walk).join('')
    if (tag === 'a') {
      const href = el.getAttribute('href') ?? ''
      if (href.startsWith('http://') || href.startsWith('https://') || href.startsWith('/')) {
        return `<${tag} href="${href}">${children}</${tag}>`
      }
      return children
    }
    if (tag === 'br' || tag === 'hr') return `<${tag}>`
    return `<${tag}>${children}</${tag}>`
  }
  return Array.from(doc.body.childNodes).map(walk).join('')
}

const sanitized = computed(() => sanitizeHtml(props.modelValue))

function onInput(event: Event) {
  const el = event.target as HTMLDivElement
  emit('update:modelValue', el.innerHTML)
}
</script>

<template>
  <div class="field">
    <label v-if="label" class="field-label">
      {{ label }}
      <span v-if="required" class="required">*</span>
    </label>
    <div
      contenteditable
      class="field-input field-richtext"
      :class="{ disabled }"
      v-html="sanitized"
      @input="onInput"
    />
    <p v-if="description" class="field-description">{{ description }}</p>
  </div>
</template>
```

**Step 2: Verify by inspection**

Confirm the `v-html` now uses the `sanitized` computed property instead of raw `modelValue`.

**Step 3: Commit**

```bash
git add packages/admin/app/components/widgets/RichText.vue
git commit -m "fix(admin): sanitize HTML in RichText widget to prevent stored XSS"
```

---

## Task 2: Harden PHP front controller — input validation and error handling

**Files:**
- Modify: `public/index.php`

**Context:** Six issues in one file. Fix them all together since they're tightly coupled:
1. `parse_url` can return `false` — validate immediately
2. Malformed JSON body silently discarded — check `json_last_error()`
3. Exception messages leaked to client — return generic message, log server-side
4. Routing exceptions beyond 404/405 escape uncaught — add catch-all to routing try/catch
5. CORS fallback sends header for disallowed origins — only send when origin matches
6. Fix the docblock to include `/api/entity-types`

**Step 1: Fix the docblock to include missing route**

In `public/index.php`, replace:

```php
/**
 * Aurora CMS HTTP front controller.
 *
 * Usage:
 *   php -S localhost:8080 -t public
 *
 * Routes:
 *   GET|POST        /api/{entityType}       — JSON:API collection / create
 *   GET|PATCH|DELETE /api/{entityType}/{id}  — JSON:API resource CRUD
 *   GET              /api/schema/{entity_type} — JSON Schema with widget hints
 *   GET              /api/openapi.json       — OpenAPI 3.1 specification
 */
```

with:

```php
/**
 * Aurora CMS HTTP front controller.
 *
 * Usage:
 *   php -S localhost:8080 -t public
 *
 * Routes:
 *   GET|POST        /api/{entityType}         — JSON:API collection / create
 *   GET|PATCH|DELETE /api/{entityType}/{id}    — JSON:API resource CRUD
 *   GET              /api/schema/{entity_type} — JSON Schema with widget hints
 *   GET              /api/openapi.json         — OpenAPI 3.1 specification
 *   GET              /api/entity-types         — list registered entity types
 */
```

**Step 2: Fix CORS — only send header when origin matches, add Vary header**

Replace lines 63-67:

```php
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
} else {
    header('Access-Control-Allow-Origin: http://localhost:3000');
}
```

with:

```php
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}
```

**Step 3: Fix `parse_url` returning false**

Replace line 117:

```php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
```

with:

```php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($path)) {
    sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Malformed request URI.']]]);
}
```

**Step 4: Add catch-all to routing try/catch**

Replace lines 159-165:

```php
try {
    $params = $router->match($path);
} catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {
    sendJson(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => "No route matches {$path}."]]]);
} catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException $e) {
    sendJson(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} not allowed for {$path}."]]]);
}
```

with:

```php
try {
    $params = $router->match($path);
} catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException) {
    sendJson(404, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => 'No route matches the requested path.']]]);
} catch (\Symfony\Component\Routing\Exception\MethodNotAllowedException) {
    sendJson(405, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '405', 'title' => 'Method Not Allowed', 'detail' => "Method {$method} is not allowed for this route."]]]);
} catch (\Throwable $e) {
    error_log(sprintf('[Aurora] Routing error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'A routing error occurred.']]]);
}
```

**Step 5: Fix malformed JSON body handling**

Replace lines 170-174:

```php
// Parse request body for POST/PATCH.
$body = null;
if (in_array($method, ['POST', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
}
```

with:

```php
// Parse request body for POST/PATCH.
$body = null;
if (in_array($method, ['POST', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendJson(400, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Invalid JSON in request body.']]]);
        }
    } else {
        $body = [];
    }
}
```

**Step 6: Fix exception message leakage — log and return generic message**

Replace lines 241-250:

```php
} catch (\Throwable $e) {
    sendJson(500, [
        'jsonapi' => ['version' => '1.1'],
        'errors' => [[
            'status' => '500',
            'title' => 'Internal Server Error',
            'detail' => $e->getMessage(),
        ]],
    ]);
}
```

with:

```php
} catch (\Throwable $e) {
    error_log(sprintf('[Aurora] Unhandled exception: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    sendJson(500, [
        'jsonapi' => ['version' => '1.1'],
        'errors' => [[
            'status' => '500',
            'title' => 'Internal Server Error',
            'detail' => 'An unexpected error occurred.',
        ]],
    ]);
}
```

**Step 7: Run existing PHP tests to ensure nothing is broken**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (these are unit/integration tests for the packages, not the front controller itself).

**Step 8: Commit**

```bash
git add public/index.php
git commit -m "fix(http): harden front controller — input validation, CORS, error handling

- Validate parse_url result before routing
- Return 400 on malformed JSON request body instead of silent empty
- Log exceptions server-side, return generic 500 to client
- Add catch-all for unexpected routing exceptions
- Only send CORS header when origin matches allowed list
- Add missing /api/entity-types to route table docblock"
```

---

## Task 3: Fix `String(undefined)` bug in `useEntity.list()` pagination

**Files:**
- Modify: `packages/admin/app/composables/useEntity.ts`

**Context:** When `list()` is called with `{ page: {} }` (offset/limit undefined), `String(undefined)` produces the literal `"undefined"` in query params. Also `query.page.offset` and `query.page.limit` could be `undefined` even when `query.page` is set.

**Step 1: Fix the pagination parameter construction**

In `packages/admin/app/composables/useEntity.ts`, replace lines 23-28:

```typescript
    const params = new URLSearchParams()

    if (query.page) {
      params.set('page[offset]', String(query.page.offset ?? 0))
      params.set('page[limit]', String(query.page.limit ?? 25))
    }
```

with:

```typescript
    const params = new URLSearchParams()

    if (query.page) {
      const offset = typeof query.page.offset === 'number' ? query.page.offset : 0
      const limit = typeof query.page.limit === 'number' ? query.page.limit : 25
      params.set('page[offset]', String(offset))
      params.set('page[limit]', String(limit))
    }
```

**Step 2: Commit**

```bash
git add packages/admin/app/composables/useEntity.ts
git commit -m "fix(admin): guard against undefined pagination params in useEntity.list()"
```

---

## Task 4: Add error handling to NavBuilder and dashboard

**Files:**
- Modify: `packages/admin/app/components/layout/NavBuilder.vue`
- Modify: `packages/admin/app/pages/index.vue`
- Modify: `packages/admin/app/i18n/en.json`

**Context:** Both NavBuilder and the dashboard page silently swallow API errors, showing an empty UI with no feedback. Add error states and console logging.

**Step 1: Add i18n keys for error messages**

In `packages/admin/app/i18n/en.json`, replace the entire file with:

```json
{
  "app_name": "Aurora CMS",
  "dashboard": "Dashboard",
  "content": "Content",
  "loading": "Loading...",
  "saving": "Saving...",
  "save": "Save",
  "create": "Create",
  "create_new": "Create new",
  "edit": "Edit",
  "delete": "Delete",
  "actions": "Actions",
  "back_to_list": "Back to list",
  "confirm_delete": "Are you sure you want to delete this item?",
  "entity_created": "Created successfully.",
  "entity_saved": "Saved successfully.",
  "no_items": "No items found.",
  "showing": "Showing",
  "of": "of",
  "previous": "Previous",
  "next": "Next",
  "error_generic": "An error occurred.",
  "error_not_found": "Not found.",
  "error_loading_schema": "Failed to load schema.",
  "error_loading_types": "Failed to load entity types.",
  "error_loading_entities": "Failed to load entities.",
  "error_deleting": "Failed to delete item.",
  "error_nav": "Navigation unavailable."
}
```

**Step 2: Fix NavBuilder to show error state**

Replace the entire file `packages/admin/app/components/layout/NavBuilder.vue` with:

```vue
<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()

interface EntityTypeInfo {
  id: string
  label: string
}

const entityTypes = ref<EntityTypeInfo[]>([])
const loadError = ref(false)

onMounted(async () => {
  try {
    const response = await $fetch<{ data: EntityTypeInfo[] }>('/api/entity-types')
    entityTypes.value = response.data
  } catch (e: unknown) {
    console.error('[Aurora] Failed to load navigation entity types:', e)
    loadError.value = true
  }
})
</script>

<template>
  <nav class="nav">
    <NuxtLink to="/" class="nav-item">
      {{ t('dashboard') }}
    </NuxtLink>
    <div class="nav-section">{{ t('content') }}</div>
    <div v-if="loadError" class="nav-error">{{ t('error_nav') }}</div>
    <NuxtLink
      v-for="et in entityTypes"
      :key="et.id"
      :to="`/${et.id}`"
      class="nav-item"
    >
      {{ et.label }}
    </NuxtLink>
  </nav>
</template>

<style scoped>
.nav { display: flex; flex-direction: column; }
.nav-section {
  padding: 12px 16px 4px;
  font-size: 11px;
  font-weight: 600;
  color: var(--color-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.nav-item {
  padding: 8px 16px;
  color: var(--color-text);
  text-decoration: none;
  font-size: 14px;
}
.nav-item:hover { background: var(--color-bg); }
.nav-item.router-link-active { color: var(--color-primary); font-weight: 500; }
.nav-error {
  padding: 8px 16px;
  font-size: 12px;
  color: var(--color-danger, #c00);
}
</style>
```

**Step 3: Fix dashboard to show error state**

Replace the entire file `packages/admin/app/pages/index.vue` with:

```vue
<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()

interface EntityTypeInfo {
  id: string
  label: string
  keys: Record<string, string>
}

const entityTypes = ref<EntityTypeInfo[]>([])
const loading = ref(true)
const loadError = ref<string | null>(null)

onMounted(async () => {
  try {
    const response = await $fetch<{ data: EntityTypeInfo[] }>('/api/entity-types')
    entityTypes.value = response.data
  } catch (e: any) {
    console.error('[Aurora] Failed to load entity types for dashboard:', e)
    loadError.value = e.data?.errors?.[0]?.detail ?? e.message ?? t('error_loading_types')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('dashboard') }}</h1>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="loadError" class="error">{{ loadError }}</div>

    <div v-else class="card-grid">
      <NuxtLink
        v-for="et in entityTypes"
        :key="et.id"
        :to="`/${et.id}`"
        class="card"
      >
        <h2 class="card-title">{{ et.label }}</h2>
        <p class="card-sub">{{ et.id }}</p>
      </NuxtLink>
    </div>
  </div>
</template>

<style scoped>
.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}
.card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 20px;
  text-decoration: none;
  color: var(--color-text);
  transition: border-color 0.15s;
}
.card:hover { border-color: var(--color-primary); }
.card-title { font-size: 18px; margin-bottom: 4px; }
.card-sub { font-size: 13px; color: var(--color-muted); }
</style>
```

**Step 4: Commit**

```bash
git add packages/admin/app/i18n/en.json packages/admin/app/components/layout/NavBuilder.vue packages/admin/app/pages/index.vue
git commit -m "fix(admin): show error states in NavBuilder and dashboard instead of silent empty"
```

---

## Task 5: Add error handling to SchemaList (fetch + delete)

**Files:**
- Modify: `packages/admin/app/components/schema/SchemaList.vue`

**Context:** `fetchEntities` has a `try/finally` with no `catch` — errors become unhandled promise rejections. `deleteEntity` has no error handling at all. Add proper catch blocks with user-visible error messages.

**Step 1: Add error handling to SchemaList**

In `packages/admin/app/components/schema/SchemaList.vue`, replace the `<script setup>` section (lines 1-80) with:

```vue
<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'

const props = defineProps<{
  entityType: string
}>()

const { t } = useLanguage()
const { schema, loading: schemaLoading, fetch: fetchSchema, sortedProperties } = useSchema(props.entityType)
const { list, remove } = useEntity()

const entities = ref<JsonApiResource[]>([])
const loading = ref(false)
const total = ref(0)
const offset = ref(0)
const limit = ref(25)
const sortField = ref<string | null>(null)
const sortAsc = ref(true)
const listError = ref<string | null>(null)

// Visible columns: non-hidden fields, sorted by weight (take first 6).
const columns = computed(() => {
  return sortedProperties(false)
    .filter(([, prop]) => prop['x-widget'] !== 'hidden')
    .slice(0, 6)
})

async function fetchEntities() {
  loading.value = true
  listError.value = null
  try {
    const query: Record<string, any> = {
      page: { offset: offset.value, limit: limit.value },
    }
    if (sortField.value) {
      query.sort = (sortAsc.value ? '' : '-') + sortField.value
    }
    const result = await list(props.entityType, query)
    entities.value = result.data
    total.value = result.meta?.total ?? result.data.length
  } catch (e: any) {
    console.error('[Aurora] Failed to fetch entities:', e)
    listError.value = e.data?.errors?.[0]?.detail ?? e.message ?? t('error_loading_entities')
  } finally {
    loading.value = false
  }
}

function toggleSort(field: string) {
  if (sortField.value === field) {
    sortAsc.value = !sortAsc.value
  } else {
    sortField.value = field
    sortAsc.value = true
  }
  fetchEntities()
}

function nextPage() {
  if (offset.value + limit.value < total.value) {
    offset.value += limit.value
    fetchEntities()
  }
}

function prevPage() {
  if (offset.value > 0) {
    offset.value = Math.max(0, offset.value - limit.value)
    fetchEntities()
  }
}

async function deleteEntity(entity: JsonApiResource) {
  if (!confirm(t('confirm_delete'))) return
  try {
    await remove(props.entityType, entity.id)
    await fetchEntities()
  } catch (e: any) {
    console.error('[Aurora] Failed to delete entity:', e)
    listError.value = e.data?.errors?.[0]?.detail ?? e.message ?? t('error_deleting')
  }
}

onMounted(async () => {
  await fetchSchema()
  await fetchEntities()
})
</script>
```

And replace the `<template>` section (lines 82-128) with:

```vue
<template>
  <div class="schema-list">
    <div v-if="schemaLoading || loading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="listError" class="error">{{ listError }}</div>
    <template v-else>
      <table class="entity-table">
        <thead>
          <tr>
            <th
              v-for="[fieldName, fieldSchema] in columns"
              :key="fieldName"
              class="sortable"
              @click="toggleSort(fieldName)"
            >
              {{ fieldSchema['x-label'] ?? fieldName }}
              <span v-if="sortField === fieldName">{{ sortAsc ? ' ↑' : ' ↓' }}</span>
            </th>
            <th>{{ t('actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="entities.length === 0">
            <td :colspan="columns.length + 1" class="empty">{{ t('no_items') }}</td>
          </tr>
          <tr v-for="entity in entities" :key="entity.id">
            <td v-for="[fieldName] in columns" :key="fieldName">
              {{ entity.attributes[fieldName] ?? '' }}
            </td>
            <td class="actions">
              <NuxtLink :to="`/${entityType}/${entity.id}`" class="btn btn-sm">
                {{ t('edit') }}
              </NuxtLink>
              <button class="btn btn-sm btn-danger" @click="deleteEntity(entity)">
                {{ t('delete') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="pagination">
        <span>{{ t('showing') }} {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} {{ t('of') }} {{ total }}</span>
        <button :disabled="offset === 0" class="btn btn-sm" @click="prevPage">{{ t('previous') }}</button>
        <button :disabled="offset + limit >= total" class="btn btn-sm" @click="nextPage">{{ t('next') }}</button>
      </div>
    </template>
  </div>
</template>
```

**Step 2: Commit**

```bash
git add packages/admin/app/components/schema/SchemaList.vue
git commit -m "fix(admin): add error handling to SchemaList fetch and delete operations"
```

---

## Task 6: Fix SSE reconnect — add backoff, max retries, and logging

**Files:**
- Modify: `packages/admin/app/composables/useRealtime.ts`

**Context:** The SSE error handler retries infinitely every 3s with no backoff, no max retries, and no logging. The `onmessage` handler silently swallows all parse errors. Also add a note that `/api/broadcast` is not yet implemented server-side.

**Step 1: Replace the entire file**

Replace the entire file `packages/admin/app/composables/useRealtime.ts` with:

```typescript
import { ref, onUnmounted, type Ref } from 'vue'

export interface BroadcastMessage {
  channel: string
  event: string
  data: Record<string, unknown>
  timestamp: number
}

const MAX_RETRIES = 10

// Requires a server-side SSE endpoint at /api/broadcast (not yet implemented in public/index.php).
export function useRealtime(channels: string[] = ['admin']) {
  const messages: Ref<BroadcastMessage[]> = ref([])
  const connected = ref(false)

  let eventSource: EventSource | null = null
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null
  let retryCount = 0

  function connect() {
    if (typeof window === 'undefined') return

    const channelParam = channels.join(',')
    eventSource = new EventSource(`/api/broadcast?channels=${channelParam}`)

    eventSource.onopen = () => {
      connected.value = true
      retryCount = 0
    }

    eventSource.onmessage = (event) => {
      if (!event.data || event.data.trim() === '') return

      try {
        const msg: BroadcastMessage = JSON.parse(event.data)
        messages.value = [...messages.value.slice(-99), msg]
      } catch (e) {
        console.warn('[Aurora] Failed to parse SSE message:', event.data)
      }
    }

    eventSource.onerror = () => {
      connected.value = false
      eventSource?.close()
      eventSource = null

      retryCount++
      if (retryCount > MAX_RETRIES) {
        console.error(`[Aurora] SSE connection failed after ${MAX_RETRIES} retries. Giving up.`)
        return
      }

      const delay = Math.min(3000 * Math.pow(2, retryCount - 1), 30000)
      console.warn(`[Aurora] SSE disconnected. Reconnecting in ${delay}ms (attempt ${retryCount}/${MAX_RETRIES})`)
      reconnectTimer = setTimeout(connect, delay)
    }
  }

  function disconnect() {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
    eventSource?.close()
    eventSource = null
    connected.value = false
  }

  connect()
  onUnmounted(disconnect)

  return { messages, connected, disconnect }
}
```

**Step 2: Commit**

```bash
git add packages/admin/app/composables/useRealtime.ts
git commit -m "fix(admin): add exponential backoff and max retries to SSE reconnect"
```

---

## Task 7: Fix SchemaForm — guard entity fetch behind schema success

**Files:**
- Modify: `packages/admin/app/components/schema/SchemaForm.vue`

**Context:** `SchemaForm.vue` continues to fetch the entity even when schema loading fails. Guard the entity fetch behind `schema.value` being non-null.

**Step 1: Fix the onMounted logic**

In `packages/admin/app/components/schema/SchemaForm.vue`, replace lines 24-36:

```typescript
// Load schema and optionally existing entity.
onMounted(async () => {
  await fetchSchema()

  if (props.entityId) {
    try {
      const resource = await get(props.entityType, props.entityId)
      formData.value = { ...resource.attributes }
    } catch (e: any) {
      loadError.value = e.data?.errors?.[0]?.detail ?? e.message ?? 'Failed to load entity'
    }
  }
})
```

with:

```typescript
// Load schema, then optionally load existing entity if schema succeeded.
onMounted(async () => {
  await fetchSchema()

  if (schema.value && props.entityId) {
    try {
      const resource = await get(props.entityType, props.entityId)
      formData.value = { ...resource.attributes }
    } catch (e: any) {
      loadError.value = e.data?.errors?.[0]?.detail ?? e.message ?? 'Failed to load entity'
    }
  }
})
```

**Step 2: Commit**

```bash
git add packages/admin/app/components/schema/SchemaForm.vue
git commit -m "fix(admin): guard entity fetch behind schema success in SchemaForm"
```

---

## Task 8: Restructure PHP dispatch to eliminate fragile null-document pattern

**Files:**
- Modify: `public/index.php`

**Context:** The `match(true)` returns `null` for `openapi` and `entity_types`, then separate `if` blocks call `sendJson()` (which exits). This is fragile. Move all non-document handlers into the match as `never`-returning closures, and remove the post-match `if` blocks.

**Step 1: Replace the dispatch block**

In `public/index.php`, replace the entire dispatch `try` block (from `try {` on the line containing `$document = match` through to the `} catch (\Throwable $e)` line). Replace lines 179-239 with:

```php
try {
    match (true) {
        // OpenAPI spec — returns directly, no JsonApiDocument.
        $controller === 'openapi' => (function () use ($entityTypeManager): never {
            $openApi = new OpenApiGenerator($entityTypeManager);
            sendJson(200, $openApi->generate());
        })(),

        // Entity types listing — returns directly, no JsonApiDocument.
        $controller === 'entity_types' => (function () use ($entityTypeManager): never {
            $types = [];
            foreach ($entityTypeManager->getDefinitions() as $id => $def) {
                $types[] = [
                    'id' => $id,
                    'label' => $def->getLabel(),
                    'keys' => $def->getKeys(),
                    'translatable' => $def->isTranslatable(),
                    'revisionable' => $def->isRevisionable(),
                ];
            }
            sendJson(200, ['data' => $types]);
        })(),

        // Schema controller.
        str_contains($controller, 'SchemaController') => (function () use ($entityTypeManager, $schemaPresenter, $params): never {
            $schemaController = new SchemaController($entityTypeManager, $schemaPresenter);
            $document = $schemaController->show($params['entity_type']);
            sendJson($document->statusCode, $document->toArray());
        })(),

        // JSON:API controller.
        str_contains($controller, 'JsonApiController') => (function () use ($entityTypeManager, $serializer, $params, $query, $body, $method): never {
            $jsonApiController = new JsonApiController($entityTypeManager, $serializer);
            $entityTypeId = $params['_entity_type'];
            $id = $params['id'] ?? null;

            $document = match (true) {
                $method === 'GET' && $id === null => $jsonApiController->index($entityTypeId, $query),
                $method === 'GET' && $id !== null => $jsonApiController->show($entityTypeId, $id),
                $method === 'POST' => $jsonApiController->store($entityTypeId, $body ?? []),
                $method === 'PATCH' && $id !== null => $jsonApiController->update($entityTypeId, $id, $body ?? []),
                $method === 'DELETE' && $id !== null => $jsonApiController->destroy($entityTypeId, $id),
                default => JsonApiDocument::fromErrors(
                    [new \Aurora\Api\JsonApiError('400', 'Bad Request', 'Unhandled method/resource combination.')],
                    statusCode: 400,
                ),
            };
            sendJson($document->statusCode, $document->toArray());
        })(),

        default => (function () use ($controller): never {
            error_log(sprintf('[Aurora] Unknown controller: %s', $controller));
            sendJson(500, ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Unknown route handler.']]]);
        })(),
    };

```

Note: The closing `} catch (\Throwable $e) {` and its body from Task 2 remains unchanged after this block.

**Step 2: Run PHP tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

**Step 3: Commit**

```bash
git add public/index.php
git commit -m "refactor(http): consolidate dispatch into single match block

Each match arm now calls sendJson() directly, eliminating the fragile
null-document pattern where two arms returned null and relied on
post-match if blocks to handle them."
```

---

## Task 9: Fix inaccurate comment in SchemaList and improve useSchema JSDoc

**Files:**
- Modify: `packages/admin/app/composables/useSchema.ts`

**Context:** The `sortedProperties` JSDoc omits what happens when `editable` is `false`. The comment in SchemaList was already fixed in Task 5.

**Step 1: Fix the JSDoc**

In `packages/admin/app/composables/useSchema.ts`, replace lines 65-68:

```typescript
  /**
   * Return properties sorted by x-weight, filtering out readOnly/hidden fields when
   * `editable` is true.
   */
```

with:

```typescript
  /**
   * Return properties sorted by x-weight. When `editable` is true, readOnly and
   * hidden fields are excluded. When false (default), all properties are returned.
   */
```

**Step 2: Commit**

```bash
git add packages/admin/app/composables/useSchema.ts
git commit -m "docs: fix sortedProperties JSDoc to document editable=false behavior"
```

---

## Execution Summary

| Task | Description | Priority |
|------|-------------|----------|
| 1 | Fix stored XSS in RichText.vue | Critical |
| 2 | Harden PHP front controller (6 fixes) | Critical |
| 3 | Fix `String(undefined)` bug in pagination | Critical |
| 4 | Add error handling to NavBuilder + dashboard | Important |
| 5 | Add error handling to SchemaList | Important |
| 6 | Fix SSE reconnect with backoff | Important |
| 7 | Guard entity fetch behind schema success | Important |
| 8 | Restructure PHP dispatch pattern | Important |
| 9 | Fix inaccurate comments | Important |

Total: 9 tasks, 9 commits.
