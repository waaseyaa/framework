<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'
import { useRealtime } from '~/composables/useRealtime'
import { useAdmin } from '~/composables/useAdmin'
import { normalizeListMetadata, type ListColumnMetadata } from '~/runtime/normalizeListMetadata'

const props = defineProps<{
  entityType: string
}>()

const { t } = useLanguage()
const { hasCapability } = useAdmin()
const canUpdate = hasCapability(props.entityType, 'update')
const canDelete = hasCapability(props.entityType, 'delete')
const { enableRealtime: realtimeEnabled } = useAdminConfig()
const { schema, loading: schemaLoading, fetch: fetchSchema, sortedProperties } = useSchema(props.entityType)
const { list, remove } = useEntity()
const { messages, connected, error: sseError, connect, reconnect } = useRealtime(['admin'], { autoConnect: false })

const entities = ref<JsonApiResource[]>([])
const loading = ref(false)
const total = ref(0)
const offset = ref(0)
const limit = ref(25)
// Content authoring is recency-oriented: a successful create must be visible
// immediately on page 1. Other entity lists keep their existing unsorted
// default unless a host declares x-list metadata.
const sortField = ref<string | null>(props.entityType === 'node' ? 'created' : null)
const sortAsc = ref(props.entityType !== 'node')
const listError = ref<string | null>(null)
// Delete failures are surfaced separately from listError so they read as a
// non-blocking "delete failed" notice ABOVE the table rather than replacing the
// whole list with what looked like a "Not found" page (D7).
const deleteError = ref<string | null>(null)
const pendingDelete = ref<JsonApiResource | null>(null)
const bundleFilter = ref<string | null>(null)
const searchValue = ref('')
const filterValues = reactive<Record<string, string | number | boolean | null>>({})
const selectedSort = ref('')
let latestListRequest = 0
// Id of the row whose Edit link was activated, held until navigation replaces
// this list. Drives an immediate aria-busy / disabled state on the clicked link
// so opening an entity is never a silent click (the destination editor still
// resolves asynchronously after the route changes).
const navigatingId = ref<string | null>(null)
// Unix-epoch seconds at component setup. Used to gate the messages watch so
// historical events the SSE server replayed on connect cannot trigger refetch
// floods. The framework's BroadcastRouter now starts new connections at the
// current high-water mark; this is a defensive second line if that regresses.
const mountedAtSec = Date.now() / 1000

// Bundle filter target: the property name that holds the bundle value (e.g.
// 'type' for nodes). Schema exposes this as `x-bundle-key` (M3A, #1413).
const bundleKey = computed(() => schema.value?.['x-bundle-key'] ?? null)

// Available bundles for the dropdown — null when the entity type isn't
// bundle-shaped or the backend SchemaPresenter wasn't built with a
// FieldDefinitionRegistry (pre-M3A behavior).
const bundleOptions = computed<string[] | null>(() => {
  const key = bundleKey.value
  if (!key) return null
  const property = schema.value?.properties?.[key]
  const values = property?.enum
  return values && values.length > 0 ? values : null
})

const hasDeclaredList = computed(() => Boolean(
  schema.value && Object.prototype.hasOwnProperty.call(schema.value, 'x-list'),
))
const listMetadata = computed(() => normalizeListMetadata(schema.value?.['x-list']))

// List-view column policy (UX-1). Long-text / rich-text bodies must never be
// dumped into a table cell (it blows out row height and makes the list
// unusable). Two complementary rules, framework-wide for every content type:
//   1. Rich-text / text-format fields (x-widget 'richtext', from the 'text_long'
//      field type) are DROPPED from the default column set entirely — they stay
//      on the detail (SchemaView) and edit (SchemaForm) views, which select
//      their own fields independently of these columns.
//   2. Every cell value is collapsed to one line and truncated to a snippet
//      (see truncateSnippet) so a long plain-text body (x-widget 'textarea')
//      that remains a column is still bounded.
const LIST_EXCLUDED_WIDGETS = new Set(['richtext'])
const SNIPPET_MAX_CHARS = 120

// Visible columns: an explicit x-list-display:true opt-in wins (the author chose
// exactly these columns — cell values are still snippet-truncated below).
// Otherwise the default policy drops rich-text/text-format columns and takes the
// first 6 of what remains.
const columns = computed(() => {
  if (hasDeclaredList.value) {
    const metadata = listMetadata.value
    if (!metadata) return []
    return metadata.columns.map((column): [string, Record<string, unknown>] => [
      column.field,
      {
        ...(schema.value?.properties?.[column.field] ?? { type: 'string' }),
        'x-label': column.label,
        'x-list-sortable': column.sortable,
        'x-list-formatter': column.formatter,
        'x-list-value-labels': column.valueLabels,
      },
    ])
  }
  const all = sortedProperties(false).filter(([, prop]) => prop['x-widget'] !== 'hidden')
  const explicit = all.filter(([, prop]) => prop['x-list-display'] === true)
  if (explicit.length > 0) return explicit
  const listable = all.filter(([, prop]) => !LIST_EXCLUDED_WIDGETS.has(prop['x-widget'] as string))
  return listable.slice(0, 6)
})

async function fetchEntities() {
  const requestId = ++latestListRequest
  loading.value = true
  listError.value = null
  try {
    const query: Record<string, any> = {
      page: { offset: offset.value, limit: limit.value },
    }
    if (sortField.value) {
      query.sort = (sortAsc.value ? '' : '-') + sortField.value
    }
    if (hasDeclaredList.value && listMetadata.value) {
      const sort = parseSelectedSort()
      if (sort) query.sort = (sort.direction === 'DESC' ? '-' : '') + sort.field
      const search = listMetadata.value.search
      if (search && searchValue.value.trim() !== '') {
        query.filter = {
          ...(query.filter ?? {}),
          [search.field]: { operator: search.operator, value: searchValue.value.trim() },
        }
      }
      for (const filter of listMetadata.value.filters) {
        const value = filterValues[filter.field]
        if (value !== '' && value !== null && value !== undefined) {
          query.filter = {
            ...(query.filter ?? {}),
            [filter.field]: { operator: filter.operator, value: String(value) },
          }
        }
      }
    } else if (bundleKey.value && bundleFilter.value) {
      query.filter = {
        ...(query.filter ?? {}),
        [bundleKey.value]: { operator: 'EQUALS', value: bundleFilter.value },
      }
    }
    const result = await list(props.entityType, query)
    if (requestId !== latestListRequest) return
    entities.value = result.data
    total.value = result.meta?.total ?? result.data.length
  } catch (e: any) {
    if (requestId !== latestListRequest) return
    console.error('[Waaseyaa] Failed to fetch entities:', e)
    listError.value = e.data?.errors?.[0]?.detail ?? e.message ?? t('error_loading_entities')
  } finally {
    if (requestId === latestListRequest) loading.value = false
  }
}

function toggleSort(field: string) {
  if (hasDeclaredList.value) {
    const column = listMetadata.value?.columns.find(item => item.field === field)
    const allowed = listMetadata.value?.sorts.filter(item => item.field === field) ?? []
    if (!column?.sortable || allowed.length === 0) return
    const current = parseSelectedSort()
    const currentIndex = allowed.findIndex(item => item.field === current?.field && item.direction === current.direction)
    const next = allowed[(currentIndex + 1) % allowed.length] ?? allowed[0]
    if (!next) return
    selectedSort.value = encodeSort(next.field, next.direction)
    void applyListControls()
    return
  }
  if (sortField.value === field) {
    sortAsc.value = !sortAsc.value
  } else {
    sortField.value = field
    sortAsc.value = true
  }
  fetchEntities()
}

function encodeSort(field: string, direction: 'ASC' | 'DESC'): string {
  return `${field}:${direction}`
}

function parseSelectedSort(): { field: string; direction: 'ASC' | 'DESC' } | null {
  const [field, direction] = selectedSort.value.split(':')
  return field && (direction === 'ASC' || direction === 'DESC') ? { field, direction } : null
}

function initializeListControls() {
  const metadata = listMetadata.value
  if (!metadata) return
  searchValue.value = ''
  for (const key of Object.keys(filterValues)) filterValues[key] = null
  for (const filter of metadata.filters) filterValues[filter.field] = filter.default ?? ''
  selectedSort.value = metadata.defaultSort
    ? encodeSort(metadata.defaultSort.field, metadata.defaultSort.direction)
    : ''
  restoreBrowserQuery()
}

function restoreBrowserQuery() {
  if (typeof window === 'undefined' || !listMetadata.value) return
  const params = new URL(window.location.href).searchParams
  const search = listMetadata.value.search
  if (search) searchValue.value = params.get(`filter[${search.field}][value]`) ?? searchValue.value
  for (const filter of listMetadata.value.filters) {
    const value = params.get(`filter[${filter.field}][value]`)
    if (value !== null) filterValues[filter.field] = value
  }
  const rawSort = params.get('sort')
  if (rawSort) {
    const direction = rawSort.startsWith('-') ? 'DESC' : 'ASC'
    const field = rawSort.replace(/^-/, '')
    if (listMetadata.value.sorts.some(sort => sort.field === field && sort.direction === direction)) {
      selectedSort.value = encodeSort(field, direction)
    }
  }
  offset.value = Math.max(0, Number(params.get('page[offset]') ?? 0) || 0)
}

function syncBrowserQuery() {
  if (typeof window === 'undefined' || !listMetadata.value) return
  const url = new URL(window.location.href)
  const declaredFields = [listMetadata.value.search?.field, ...listMetadata.value.filters.map(filter => filter.field)]
    .filter((field): field is string => Boolean(field))
  for (const field of declaredFields) {
    url.searchParams.delete(`filter[${field}][operator]`)
    url.searchParams.delete(`filter[${field}][value]`)
  }
  const search = listMetadata.value.search
  if (search && searchValue.value.trim() !== '') {
    url.searchParams.set(`filter[${search.field}][operator]`, search.operator)
    url.searchParams.set(`filter[${search.field}][value]`, searchValue.value.trim())
  }
  for (const filter of listMetadata.value.filters) {
    const value = filterValues[filter.field]
    if (value !== '' && value !== null && value !== undefined) {
      url.searchParams.set(`filter[${filter.field}][operator]`, filter.operator)
      url.searchParams.set(`filter[${filter.field}][value]`, String(value))
    }
  }
  const sort = parseSelectedSort()
  if (sort) url.searchParams.set('sort', (sort.direction === 'DESC' ? '-' : '') + sort.field)
  else url.searchParams.delete('sort')
  if (offset.value > 0) url.searchParams.set('page[offset]', String(offset.value))
  else url.searchParams.delete('page[offset]')
  window.history.replaceState(window.history.state, '', url)
}

async function applyListControls() {
  offset.value = 0
  syncBrowserQuery()
  await fetchEntities()
}

async function resetListControls() {
  initializeListControls()
  // Reset is authoritative; do not immediately re-read the URL values that it
  // is clearing.
  searchValue.value = ''
  for (const filter of listMetadata.value?.filters ?? []) filterValues[filter.field] = filter.default ?? ''
  selectedSort.value = listMetadata.value?.defaultSort
    ? encodeSort(listMetadata.value.defaultSort.field, listMetadata.value.defaultSort.direction)
    : ''
  await applyListControls()
}

function nextPage() {
  if (offset.value + limit.value < total.value) {
    void goToPage(currentPage.value + 1)
  }
}

function prevPage() {
  if (offset.value > 0) {
    void goToPage(currentPage.value - 1)
  }
}

function onEditNavigate(entity: JsonApiResource, event: MouseEvent) {
  // Swallow repeat activations once a navigation is already in flight so a
  // double-click can't start a second route change. The first click falls
  // through to NuxtLink's default navigation.
  if (navigatingId.value !== null) {
    event.preventDefault()
    return
  }
  navigatingId.value = entity.id
}

async function deleteEntity(entity: JsonApiResource) {
  deleteError.value = null
  try {
    await remove(props.entityType, entity.id)
    await fetchEntities()
  } catch (e: any) {
    console.error('[Waaseyaa] Failed to delete entity:', e)
    // Frame as a delete failure rather than echoing the raw backend title (a
    // bare "Not found" read like the list itself was missing). Keep the table
    // visible — deleteError renders as an inline notice, not a full-page error.
    const detail = e.data?.errors?.[0]?.detail ?? e.detail ?? e.message
    deleteError.value = detail ? `${t('error_deleting')} ${detail}` : t('error_deleting')
  }
}

function requestDelete(entity: JsonApiResource) {
  pendingDelete.value = entity
}

async function confirmDelete() {
  const entity = pendingDelete.value
  pendingDelete.value = null
  if (entity) await deleteEntity(entity)
}

function getCellValue(entity: JsonApiResource, fieldName: string, fieldSchema: Record<string, unknown>): unknown {
  const value = entity.attributes[fieldName]
  // machine_name fields may be excluded from attributes by the serializer (id key);
  // fall back to the resource-level id.
  if ((value === null || value === undefined || value === '') && fieldSchema['x-widget'] === 'machine_name') {
    return entity.id
  }
  return value
}

function formatCellValue(value: unknown, fieldSchema: Record<string, unknown>): string {
  if (value === null || value === undefined) return ''

  const formatter = fieldSchema['x-list-formatter'] as string | undefined
  const valueLabels = fieldSchema['x-list-value-labels'] as Record<string, string> | undefined
  if (valueLabels && Object.prototype.hasOwnProperty.call(valueLabels, String(value))) {
    return truncateSnippet(valueLabels[String(value)] ?? '')
  }
  if (formatter === 'boolean/status') return value ? t('yes') : t('no')
  if (formatter === 'date') return truncateSnippet(String(value).slice(0, 10))
  if (formatter === 'datetime' && typeof value === 'string') {
    const date = new Date(value)
    return Number.isNaN(date.valueOf()) ? truncateSnippet(value) : date.toLocaleString()
  }

  const type = fieldSchema.type as string
  const format = fieldSchema.format as string | undefined

  if (type === 'boolean') {
    return value ? '✓' : '—'
  }

  if (format === 'date-time' && typeof value === 'string') {
    try {
      return new Date(value).toLocaleString()
    } catch {
      return String(value)
    }
  }

  return truncateSnippet(String(value))
}

// Collapse internal whitespace/newlines so a multi-line body renders as a single
// line, then cap the length with an ellipsis. Bounds every text cell regardless
// of the underlying field size — the table-column half of the UX-1 policy (the
// other half is excluding rich-text widgets from `columns`).
function truncateSnippet(value: string): string {
  const oneLine = value.replace(/\s+/g, ' ').trim()
  return oneLine.length > SNIPPET_MAX_CHARS
    ? oneLine.slice(0, SNIPPET_MAX_CHARS).trimEnd() + '…'
    : oneLine
}

// Workflow-state pill (CW-v1 WP-4 Task C, #1920). If the schema already lists
// workflow_state as a column (most workflow-bound entity types do — it's a
// normal field), the pill renders inside that existing cell. Otherwise, when
// the fetched entities still carry attributes.workflow_state (e.g. it was
// excluded from the column set), a synthetic extra column is appended so the
// state is never silently dropped. Entity types with no workflow_state
// attribute at all get neither — the list renders exactly as before.
const workflowStateInColumns = computed(() => columns.value.some(([name]) => name === 'workflow_state'))
const hasWorkflowStateAttribute = computed(() =>
  entities.value.some(entity => Object.prototype.hasOwnProperty.call(entity.attributes ?? {}, 'workflow_state')),
)
const showSyntheticWorkflowStateColumn = computed(() => hasWorkflowStateAttribute.value && !workflowStateInColumns.value)
const currentPage = computed(() => Math.floor(offset.value / limit.value) + 1)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit.value)))
const paginationRef = ref<HTMLElement | null>(null)

type PaginationItem = number | `ellipsis-${number}`
const paginationItems = computed<PaginationItem[]>(() => {
  const count = totalPages.value
  if (count <= 5) return Array.from({ length: count }, (_, index) => index + 1)

  const pages = [...new Set([
    1,
    Math.max(1, currentPage.value - 1),
    currentPage.value,
    Math.min(count, currentPage.value + 1),
    count,
  ])].sort((a, b) => a - b)
  const items: PaginationItem[] = []
  pages.forEach((page, index) => {
    const previous = pages[index - 1]
    if (previous !== undefined && page - previous > 1) items.push(`ellipsis-${previous}`)
    items.push(page)
  })
  return items
})

function fieldLabel(fieldName: string, fieldSchema: Record<string, unknown>): string {
  return String(fieldSchema['x-label'] ?? fieldName)
}

function declaredColumn(fieldName: string): ListColumnMetadata | undefined {
  return listMetadata.value?.columns.find(column => column.field === fieldName)
}

function isColumnSortable(fieldName: string): boolean {
  return hasDeclaredList.value ? declaredColumn(fieldName)?.sortable === true : true
}

function columnAriaSort(fieldName: string): 'ascending' | 'descending' | 'none' | undefined {
  if (!isColumnSortable(fieldName)) return undefined
  if (hasDeclaredList.value) {
    const selected = parseSelectedSort()
    return selected?.field === fieldName ? (selected.direction === 'ASC' ? 'ascending' : 'descending') : 'none'
  }
  return sortField.value === fieldName ? (sortAsc.value ? 'ascending' : 'descending') : 'none'
}

function rowCan(entity: JsonApiResource, action: 'view' | 'edit' | 'delete'): boolean {
  if (hasDeclaredList.value) return entity.capabilities?.[action] === true
  if (action === 'edit') return canUpdate
  if (action === 'delete') return canDelete
  return false
}

async function goToPage(page: number, restoreFocus = true) {
  const boundedPage = Math.min(Math.max(page, 1), totalPages.value)
  if (boundedPage === currentPage.value) return
  offset.value = (boundedPage - 1) * limit.value
  syncBrowserQuery()
  await fetchEntities()
  if (restoreFocus) {
    await nextTick()
    paginationRef.value?.querySelector<HTMLElement>(`[data-page="${boundedPage}"]`)?.focus()
  }
}

const KNOWN_WORKFLOW_STATE_CLASSES = new Set(['draft', 'review', 'published', 'archived'])
function workflowStateClass(value: unknown): string {
  return typeof value === 'string' && KNOWN_WORKFLOW_STATE_CLASSES.has(value)
    ? `status-pill status-pill--${value}`
    : 'status-pill'
}

function getEntityLabel(entity: JsonApiResource): string {
  // Find the label field from columns (x-label: "Title" or the label key).
  for (const [fieldName] of columns.value) {
    const val = entity.attributes[fieldName]
    if (typeof val === 'string' && val !== '') return val
  }
  return entity.id
}

onMounted(async () => {
  await fetchSchema()
  initializeListControls()
  await fetchEntities()
  if (realtimeEnabled) {
    connect()
  }
})

// Auto-refresh when entity events arrive for this entity type.
watch(messages, (msgs) => {
  if (msgs.length === 0) return
  const latest = msgs[msgs.length - 1]
  if (latest === undefined) return
  // Ignore events that predate this component's mount — protects against an
  // SSE backend that ever replays history on connect (see BroadcastRouter).
  if (typeof latest.created_at === 'number' && latest.created_at < mountedAtSec) return
  if (
    (latest.event === 'entity.saved' || latest.event === 'entity.deleted') &&
    latest.data?.entityType === props.entityType
  ) {
    fetchEntities()
  }
})
</script>

<template>
  <section
    class="schema-list listing-region"
    :data-anchor="`list:${entityType}`"
    data-testid="listing-region"
    :aria-label="t('listing_region', { type: schema?.title ?? entityType })"
    :aria-busy="loading ? 'true' : 'false'"
  >
    <div v-if="schemaLoading" class="loading listing-state listing-state--loading" role="status">{{ t('loading') }}</div>
    <template v-else>
      <div v-if="loading" class="loading listing-state listing-state--loading" role="status">{{ t('loading') }}</div>
      <div v-if="listError" class="error listing-state listing-state--error" role="alert">{{ listError }}</div>
      <div v-if="deleteError" class="error error--inline" role="alert">{{ deleteError }}</div>
      <form
        v-if="listMetadata"
        class="entity-filters list-controls"
        data-testid="list-controls"
        @submit.prevent="applyListControls"
      >
        <label v-if="listMetadata.search" class="entity-filter-label">
          {{ listMetadata.search.label }}
          <input
            v-model="searchValue"
            type="search"
            class="entity-filter-input touch-target"
            data-testid="list-search"
            :aria-describedby="listMetadata.search.description ? `list-search-description-${entityType}` : undefined"
          >
          <span
            v-if="listMetadata.search.description"
            :id="`list-search-description-${entityType}`"
            class="entity-filter-description"
          >{{ listMetadata.search.description }}</span>
        </label>
        <label v-for="filter in listMetadata.filters" :key="filter.field" class="entity-filter-label">
          {{ filter.label }}
          <select
            v-if="filter.options"
            v-model="filterValues[filter.field]"
            class="entity-filter-select touch-target"
            :data-testid="`list-filter-${filter.field}`"
            @change="applyListControls"
          >
            <option value="">{{ t('list_filter_all') }}</option>
            <option v-for="option in filter.options" :key="String(option.value)" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <input
            v-else
            v-model="filterValues[filter.field]"
            class="entity-filter-input touch-target"
            :data-testid="`list-filter-${filter.field}`"
            @change="applyListControls"
          >
        </label>
        <label v-if="listMetadata.sorts.length > 0" class="entity-filter-label">
          {{ t('list_sort_label') }}
          <select
            v-model="selectedSort"
            class="entity-filter-select touch-target"
            data-testid="list-sort"
            @change="applyListControls"
          >
            <option value="">{{ t('list_sort_none') }}</option>
            <option v-for="sort in listMetadata.sorts" :key="encodeSort(sort.field, sort.direction)" :value="encodeSort(sort.field, sort.direction)">
              {{ sort.label }}
            </option>
          </select>
        </label>
        <button type="submit" class="btn touch-target">{{ t('search') }}</button>
        <button type="button" class="btn touch-target" data-testid="list-reset" @click="resetListControls">{{ t('reset') }}</button>
      </form>
      <div v-else-if="!hasDeclaredList && bundleOptions" class="entity-filters">
        <label class="entity-filter-label">
          {{ t('bundle_filter_label') }}
          <select
            v-model="bundleFilter"
            class="entity-filter-select"
            data-testid="bundle-filter"
            @change="() => { offset = 0; fetchEntities() }"
          >
            <option :value="null">{{ t('bundle_filter_all') }}</option>
            <option v-for="bundle in bundleOptions" :key="bundle" :value="bundle">
              {{ schema?.properties?.[bundleKey ?? '']?.['x-enum-labels']?.[bundle] ?? bundle }}
            </option>
          </select>
        </label>
      </div>
      <div
        class="listing-scroll"
        data-testid="listing-scroll"
        role="region"
        tabindex="0"
        :aria-label="t('listing_table', { type: schema?.title ?? entityType })"
      >
        <table class="entity-table" data-responsive-mode="table-card">
          <caption class="sr-only">{{ t('listing_table', { type: schema?.title ?? entityType }) }}</caption>
          <thead>
            <tr>
              <th
                v-for="[fieldName, fieldSchema] in columns"
                :key="fieldName"
                scope="col"
                :class="{ sortable: isColumnSortable(fieldName) }"
                :aria-sort="columnAriaSort(fieldName)"
                :data-anchor="`list-field:${entityType}:${fieldName}`"
              >
                <button
                  v-if="isColumnSortable(fieldName)"
                  type="button"
                  class="sortable-control touch-target"
                  :aria-label="fieldLabel(fieldName, fieldSchema as unknown as Record<string, unknown>)"
                  @click="toggleSort(fieldName)"
                >
                  {{ fieldSchema['x-label'] ?? fieldName }}
                  <span v-if="columnAriaSort(fieldName) === 'ascending'" aria-hidden="true"> ↑</span>
                  <span v-else-if="columnAriaSort(fieldName) === 'descending'" aria-hidden="true"> ↓</span>
                </button>
                <span v-else>{{ fieldSchema['x-label'] ?? fieldName }}</span>
              </th>
              <th v-if="showSyntheticWorkflowStateColumn" scope="col">{{ t('workflow_state_column_label') }}</th>
              <th scope="col">{{ t('actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="entities.length === 0">
              <td :colspan="columns.length + (showSyntheticWorkflowStateColumn ? 1 : 0) + 1" class="empty">{{ t('no_items') }}</td>
            </tr>
            <tr
              v-for="entity in entities"
              :key="entity.id"
              :data-row-id="entity.id"
              :aria-label="getEntityLabel(entity)"
            >
              <td
                v-for="[fieldName, fieldSchema] in columns"
                :key="fieldName"
                :data-label="fieldLabel(fieldName, fieldSchema as unknown as Record<string, unknown>)"
              >
              <span v-if="fieldName === 'workflow_state'" :class="workflowStateClass(getCellValue(entity, fieldName, fieldSchema as unknown as Record<string, unknown>))">
                {{ getCellValue(entity, fieldName, fieldSchema as unknown as Record<string, unknown>) }}
              </span>
              <template v-else>
                {{ formatCellValue(getCellValue(entity, fieldName, fieldSchema as unknown as Record<string, unknown>), fieldSchema as unknown as Record<string, unknown>) }}
              </template>
              </td>
              <td v-if="showSyntheticWorkflowStateColumn" :data-label="t('workflow_state_column_label')">
              <span v-if="entity.attributes.workflow_state" :class="workflowStateClass(entity.attributes.workflow_state)">
                {{ entity.attributes.workflow_state }}
              </span>
              </td>
              <td
                class="actions"
                :data-label="t('actions')"
                role="group"
                :aria-label="`${t('actions')}: ${getEntityLabel(entity)}`"
              >
              <NuxtLink
                v-if="rowCan(entity, 'edit')"
                :to="`/${entityType}/${entity.id}`"
                class="btn btn-sm touch-target"
                :class="{ 'is-busy': navigatingId === entity.id }"
                :aria-busy="navigatingId === entity.id ? 'true' : 'false'"
                :aria-disabled="navigatingId === entity.id ? 'true' : undefined"
                :data-anchor="`action:${entityType}:edit`"
                @click="onEditNavigate(entity, $event)"
              >
                {{ navigatingId === entity.id ? t('opening') : t('edit') }}
              </NuxtLink>
              <NuxtLink
                v-else-if="rowCan(entity, 'view')"
                :to="`/${entityType}/${entity.id}`"
                class="btn btn-sm touch-target"
                :data-anchor="`action:${entityType}:view`"
              >
                {{ t('view') }}
              </NuxtLink>
              <button
                v-if="rowCan(entity, 'delete')"
                class="btn btn-sm btn-danger touch-target"
                :aria-label="t('delete') + ': ' + getEntityLabel(entity)"
                :data-anchor="`action:${entityType}:delete`"
                @click="requestDelete(entity)"
              >
                {{ t('delete') }}
              </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav
        v-if="total > 0"
        ref="paginationRef"
        class="pagination"
        :aria-label="t('pagination')"
      >
        <template v-if="total > 0">
          <span class="pagination-summary">{{ t('showing') }} {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} {{ t('of') }} {{ total }}</span>
          <button data-pagination="previous" :aria-label="t('previous')" :disabled="offset === 0" class="btn btn-sm touch-target pagination-control" @click="prevPage">{{ t('previous') }}</button>
          <template v-for="item in paginationItems" :key="item">
            <span v-if="typeof item === 'string'" class="pagination-ellipsis" aria-hidden="true">…</span>
            <button
              v-else
              type="button"
              class="btn btn-sm touch-target pagination-control pagination-page"
              :class="{ 'pagination-page--current': item === currentPage }"
              :data-page="item"
              :aria-label="t('page_number', { page: String(item) })"
              :aria-current="item === currentPage ? 'page' : undefined"
              @click="goToPage(item)"
            >
              {{ item }}
            </button>
          </template>
          <button data-pagination="next" :aria-label="t('next')" :disabled="offset + limit >= total" class="btn btn-sm touch-target pagination-control" @click="nextPage">{{ t('next') }}</button>
        </template>
        <span v-if="connected" class="sse-status" :title="t('realtime_connected')">&#9679;</span>
        <button v-else-if="sseError" class="btn btn-sm touch-target" @click="reconnect">{{ sseError }}</button>
      </nav>

      <div v-if="total > 0" class="sr-only" role="status" aria-live="polite">
        {{ t('showing') }} {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} {{ t('of') }} {{ total }}
      </div>
    </template>
    <CommonConfirmDialog
      :open="pendingDelete !== null"
      :message="t('confirm_delete')"
      :confirm-label="t('delete')"
      dangerous
      @cancel="pendingDelete = null"
      @confirm="confirmDelete"
    />
  </section>
</template>

<style scoped>
/* Clicked Edit link while its destination editor is opening: visually muted and
   non-interactive so it reads as busy and can't be re-activated mid-navigation. */
.btn.is-busy {
  pointer-events: none;
  opacity: 0.65;
  cursor: progress;
}

/* Delete failures render inline above the table (table stays visible) rather
   than replacing the whole list like a load error. */
.error--inline {
  margin-bottom: 12px;
}

.list-controls {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.75rem;
  margin-block-end: 1rem;
}

.entity-filter-label {
  display: grid;
  gap: 0.25rem;
  min-width: min(100%, 12rem);
}

.entity-filter-input,
.entity-filter-select {
  min-height: 44px;
  max-width: 100%;
}

.entity-filter-description {
  max-width: 32rem;
  color: var(--color-muted);
  font-size: 0.875rem;
}

.listing-region,
.listing-scroll {
  min-width: 0;
  max-width: 100%;
}

.listing-scroll {
  overflow-x: auto;
  overscroll-behavior-inline: contain;
  border-radius: 4px;
}

.listing-scroll:focus-visible {
  outline: 3px solid var(--color-primary-hover);
  outline-offset: 2px;
}

.entity-table {
  min-width: 100%;
  width: max-content;
}

.sortable-control {
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-weight: inherit;
  text-transform: inherit;
  letter-spacing: inherit;
  cursor: pointer;
  padding-inline: 0.25rem;
}

.pagination-page--current {
  border-color: var(--color-primary);
  background: var(--color-primary);
  color: #fff;
}

/* Bound every data column so a long value can never stretch a row, even if one
   ever slips past the snippet truncation (UX-1 defense-in-depth). The actions
   column is exempt so its buttons stay on one line. */
.entity-table td:not(.actions) {
  max-width: 28rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Workflow-state pill (status-pill pattern, mirrors SchedulerTaskRow.vue). */
.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 9999px;
  font-size: 12px;
  font-weight: 500;
  background: var(--color-bg);
  color: var(--color-muted);
}
.status-pill--published {
  background: #d1fae5;
  color: #065f46;
}
.status-pill--review {
  background: #fef3c7;
  color: #92400e;
}
.status-pill--draft {
  background: var(--color-bg);
  color: var(--color-muted);
}
.status-pill--archived {
  background: #e5e7eb;
  color: #374151;
}

@media (max-width: 600px) {
  .listing-scroll {
    overflow: visible;
  }

  .entity-table {
    display: block;
    width: 100%;
    min-width: 0;
    background: transparent;
  }

  .entity-table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }

  .entity-table tbody,
  .entity-table tr,
  .entity-table td {
    display: block;
    width: 100%;
  }

  .entity-table tr[data-row-id] {
    margin-block-end: 1rem;
    border: 1px solid var(--color-border);
    border-radius: 0.5rem;
    background: var(--color-surface);
    overflow: hidden;
  }

  .entity-table td[data-label] {
    display: grid;
    grid-template-columns: minmax(7rem, 35%) minmax(0, 1fr);
    gap: 0.75rem;
    max-width: none;
    white-space: normal;
    overflow: visible;
    overflow-wrap: anywhere;
    text-overflow: clip;
  }

  .entity-table td[data-label]::before {
    content: attr(data-label);
    font-weight: 600;
    color: var(--color-muted);
  }

  .entity-table td.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  .entity-table td.actions::before {
    flex: 1 0 100%;
  }
}
</style>
