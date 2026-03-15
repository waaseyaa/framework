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
const config = useRuntimeConfig()
const realtimeEnabled = config.public.enableRealtime === '1'
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

// Visible columns: prefer fields with x-list-display:true; fall back to first 6
// non-hidden fields when no schema field declares x-list-display.
const columns = computed(() => {
  const all = sortedProperties(false).filter(([, prop]) => prop['x-widget'] !== 'hidden')
  const explicit = all.filter(([, prop]) => prop['x-list-display'] === true)
  return explicit.length > 0 ? explicit : all.slice(0, 6)
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

async function deleteEntity(entity: JsonApiResource) {
  if (!confirm(t('confirm_delete'))) return
  try {
    await remove(props.entityType, entity.id)
    await fetchEntities()
  } catch (e: any) {
    console.error('[Waaseyaa] Failed to delete entity:', e)
    listError.value = e.data?.errors?.[0]?.detail ?? e.message ?? t('error_deleting')
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

  return String(value)
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
  if (
    (latest.event === 'entity.saved' || latest.event === 'entity.deleted') &&
    latest.data?.entityType === props.entityType
  ) {
    fetchEntities()
  }
})
</script>

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
            <td v-for="[fieldName, fieldSchema] in columns" :key="fieldName">
              {{ formatCellValue(getCellValue(entity, fieldName, fieldSchema as unknown as Record<string, unknown>), fieldSchema as unknown as Record<string, unknown>) }}
            </td>
            <td class="actions">
              <NuxtLink v-if="canUpdate" :to="`/${entityType}/${entity.id}`" class="btn btn-sm">
                {{ t('edit') }}
              </NuxtLink>
              <button
                v-if="canDelete"
                class="btn btn-sm btn-danger"
                :aria-label="t('delete') + ': ' + getEntityLabel(entity)"
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
