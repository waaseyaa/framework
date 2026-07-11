<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'
import { useRealtime } from '~/composables/useRealtime'
import { useAdmin } from '~/composables/useAdmin'

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
const sortField = ref<string | null>(null)
const sortAsc = ref(true)
const listError = ref<string | null>(null)
// Delete failures are surfaced separately from listError so they read as a
// non-blocking "delete failed" notice ABOVE the table rather than replacing the
// whole list with what looked like a "Not found" page (D7).
const deleteError = ref<string | null>(null)
const bundleFilter = ref<string | null>(null)
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
  const all = sortedProperties(false).filter(([, prop]) => prop['x-widget'] !== 'hidden')
  const explicit = all.filter(([, prop]) => prop['x-list-display'] === true)
  if (explicit.length > 0) return explicit
  const listable = all.filter(([, prop]) => !LIST_EXCLUDED_WIDGETS.has(prop['x-widget'] as string))
  return listable.slice(0, 6)
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
    if (bundleKey.value && bundleFilter.value) {
      query.filter = { ...(query.filter ?? {}), [bundleKey.value]: bundleFilter.value }
    }
    const result = await list(props.entityType, query)
    entities.value = result.data
    total.value = result.meta?.total ?? result.data.length
  } catch (e: any) {
    console.error('[Waaseyaa] Failed to fetch entities:', e)
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
  if (!confirm(t('confirm_delete'))) return
  deleteError.value = null
  try {
    await remove(props.entityType, entity.id)
    await fetchEntities()
  } catch (e: any) {
    console.error('[Waaseyaa] Failed to delete entity:', e)
    // Frame as a delete failure rather than echoing the raw backend title (a
    // bare "Not found" read like the list itself was missing). Keep the table
    // visible — deleteError renders as an inline notice, not a full-page error.
    const detail = e.data?.errors?.[0]?.detail ?? e.message
    deleteError.value = detail ? `${t('error_deleting')} ${detail}` : t('error_deleting')
  }
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
  <div class="schema-list" :data-anchor="`list:${entityType}`">
    <div v-if="schemaLoading || loading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="listError" class="error">{{ listError }}</div>
    <template v-else>
      <div v-if="deleteError" class="error error--inline" role="alert">{{ deleteError }}</div>
      <div v-if="bundleOptions" class="entity-filters">
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
              {{ bundle }}
            </option>
          </select>
        </label>
      </div>
      <table class="entity-table">
        <thead>
          <tr>
            <th
              v-for="[fieldName, fieldSchema] in columns"
              :key="fieldName"
              class="sortable"
              :data-anchor="`list-field:${entityType}:${fieldName}`"
              @click="toggleSort(fieldName)"
            >
              {{ fieldSchema['x-label'] ?? fieldName }}
              <span v-if="sortField === fieldName">{{ sortAsc ? ' ↑' : ' ↓' }}</span>
            </th>
            <th v-if="showSyntheticWorkflowStateColumn">{{ t('workflow_state_column_label') }}</th>
            <th>{{ t('actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="entities.length === 0">
            <td :colspan="columns.length + (showSyntheticWorkflowStateColumn ? 1 : 0) + 1" class="empty">{{ t('no_items') }}</td>
          </tr>
          <tr v-for="entity in entities" :key="entity.id">
            <td v-for="[fieldName, fieldSchema] in columns" :key="fieldName">
              <span v-if="fieldName === 'workflow_state'" :class="workflowStateClass(getCellValue(entity, fieldName, fieldSchema as unknown as Record<string, unknown>))">
                {{ getCellValue(entity, fieldName, fieldSchema as unknown as Record<string, unknown>) }}
              </span>
              <template v-else>
                {{ formatCellValue(getCellValue(entity, fieldName, fieldSchema as unknown as Record<string, unknown>), fieldSchema as unknown as Record<string, unknown>) }}
              </template>
            </td>
            <td v-if="showSyntheticWorkflowStateColumn">
              <span v-if="entity.attributes.workflow_state" :class="workflowStateClass(entity.attributes.workflow_state)">
                {{ entity.attributes.workflow_state }}
              </span>
            </td>
            <td class="actions">
              <NuxtLink
                v-if="canUpdate"
                :to="`/${entityType}/${entity.id}`"
                class="btn btn-sm"
                :class="{ 'is-busy': navigatingId === entity.id }"
                :aria-busy="navigatingId === entity.id ? 'true' : 'false'"
                :aria-disabled="navigatingId === entity.id ? 'true' : undefined"
                :data-anchor="`action:${entityType}:edit`"
                @click="onEditNavigate(entity, $event)"
              >
                {{ navigatingId === entity.id ? t('opening') : t('edit') }}
              </NuxtLink>
              <button
                v-if="canDelete"
                class="btn btn-sm btn-danger"
                :aria-label="t('delete') + ': ' + getEntityLabel(entity)"
                :data-anchor="`action:${entityType}:delete`"
                @click="deleteEntity(entity)"
              >
                {{ t('delete') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div class="pagination">
        <template v-if="total > 0">
          <span>{{ t('showing') }} {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} {{ t('of') }} {{ total }}</span>
          <button :disabled="offset === 0" class="btn btn-sm" @click="prevPage">{{ t('previous') }}</button>
          <button :disabled="offset + limit >= total" class="btn btn-sm" @click="nextPage">{{ t('next') }}</button>
        </template>
        <span v-if="connected" class="sse-status" :title="t('realtime_connected')">&#9679;</span>
        <button v-else-if="sseError" class="btn btn-sm" @click="reconnect">{{ sseError }}</button>
      </div>

      <div v-if="total > 0" class="sr-only" role="status" aria-live="polite">
        {{ t('showing') }} {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} {{ t('of') }} {{ total }}
      </div>
    </template>
  </div>
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
</style>
