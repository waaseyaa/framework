<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'
import { useRealtime } from '~/composables/useRealtime'

const props = defineProps<{
  entityType: string
}>()

const { t } = useLanguage()
const { schema, loading: schemaLoading, fetch: fetchSchema, sortedProperties } = useSchema(props.entityType)
const { list, remove } = useEntity()
const { messages, connected, error: sseError, reconnect } = useRealtime(['admin'])

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

onMounted(async () => {
  await fetchSchema()
  await fetchEntities()
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
            <td v-for="[fieldName] in columns" :key="fieldName">
              {{ entity.attributes[fieldName] ?? '' }}
            </td>
            <td class="actions">
              <NuxtLink :to="`/${entityType}/${entity.id}`" class="btn btn-sm">
                {{ t('edit') }}
              </NuxtLink>
              <button
                class="btn btn-sm btn-danger"
                :aria-label="t('delete') + ': ' + (entity.attributes[columns[0]?.[0]] ?? entity.id)"
                @click="deleteEntity(entity)"
              >
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
        <span v-if="connected" class="sse-status" :title="t('realtime_connected')">&#9679;</span>
        <button v-else-if="sseError" class="btn btn-sm" @click="reconnect">{{ sseError }}</button>
      </div>

      <div class="sr-only" role="status" aria-live="polite">
        {{ t('showing') }} {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} {{ t('of') }} {{ total }}
      </div>
    </template>
  </div>
</template>
