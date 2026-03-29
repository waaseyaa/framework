<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import { useEntity } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'

const props = defineProps<{
  entityType: string
  entityId: string
}>()

const { t } = useLanguage()
const { schema, loading: schemaLoading, error: schemaError, fetch: fetchSchema, sortedProperties } = useSchema(props.entityType)
const { get } = useEntity()

const entityData = ref<Record<string, any>>({})
const loadError = ref<string | null>(null)

onMounted(async () => {
  await fetchSchema()
  if (schema.value) {
    try {
      const resource = await get(props.entityType, props.entityId)
      entityData.value = { id: resource.id, ...resource.attributes }
    } catch (e: any) {
      loadError.value = e.data?.errors?.[0]?.detail ?? e.message ?? 'Failed to load entity'
    }
  }
})

const allFields = computed(() => sortedProperties(false))

const hasValue = (fieldName: string): boolean => {
  const val = entityData.value[fieldName]
  return val != null && val !== ''
}

const populatedFields = computed(() => allFields.value.filter(([name]) => hasValue(name)))
const emptyFields = computed(() => allFields.value.filter(([name]) => !hasValue(name)))
const showEmpty = ref(false)

function formatValue(value: any, fieldSchema: Record<string, any>): string {
  if (value == null || value === '') return t('field_not_set')
  if (fieldSchema.type === 'boolean') return value ? t('yes') : t('no')
  if (fieldSchema.format === 'date-time' && typeof value === 'string') {
    try { return new Date(value).toLocaleString() } catch { return String(value) }
  }
  if (Array.isArray(value)) return value.join(', ')
  if (fieldSchema.enum && fieldSchema['x-enum-labels']) {
    return fieldSchema['x-enum-labels'][String(value)] ?? String(value)
  }
  return String(value)
}
</script>

<template>
  <div class="schema-view">
    <div v-if="schemaLoading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="schemaError" class="error">{{ schemaError }}</div>
    <div v-else-if="loadError" class="error">{{ loadError }}</div>
    <dl v-else class="field-list">
      <template v-for="[fieldName, fieldSchema] in populatedFields" :key="fieldName">
        <div class="field-row">
          <dt class="field-label">{{ fieldSchema['x-label'] || fieldName }}</dt>
          <dd
            v-if="fieldSchema['x-widget'] === 'richtext' && entityData[fieldName]"
            class="field-value field-value--html"
            v-html="entityData[fieldName]"
          />
          <dd v-else class="field-value">
            {{ formatValue(entityData[fieldName], fieldSchema) }}
          </dd>
        </div>
      </template>

      <div v-if="emptyFields.length > 0" class="empty-fields-toggle">
        <button class="btn-link" @click="showEmpty = !showEmpty">
          {{ showEmpty ? 'Hide' : 'Show' }} {{ emptyFields.length }} empty fields
        </button>
      </div>

      <template v-if="showEmpty">
        <div v-for="[fieldName, fieldSchema] in emptyFields" :key="fieldName" class="field-row field-row--empty">
          <dt class="field-label">{{ fieldSchema['x-label'] || fieldName }}</dt>
          <dd class="field-value field-value--empty">{{ t('field_not_set') }}</dd>
        </div>
      </template>
    </dl>
  </div>
</template>

<style scoped>
.field-list {
  display: grid;
  gap: 0;
}

.field-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid var(--color-border, #e2e8f0);
}

.field-row:last-child {
  border-bottom: none;
}

.field-label {
  font-weight: 600;
  font-size: 13px;
  color: var(--color-text-muted, #64748b);
}

.field-value {
  font-size: 14px;
  color: var(--color-text, #1e293b);
  word-break: break-word;
}

.field-value--html {
  line-height: 1.6;
}

.field-value--html :deep(p) {
  margin: 0 0 8px;
}

.field-value--empty {
  color: var(--color-text-muted, #94a3b8);
  font-style: italic;
}

.field-row--empty {
  opacity: 0.7;
}

.empty-fields-toggle {
  padding: 12px 0;
  border-bottom: 1px solid var(--color-border, #e2e8f0);
}

.btn-link {
  background: none;
  border: none;
  color: var(--color-primary, #3b82f6);
  cursor: pointer;
  font-size: 13px;
  padding: 0;
}

.btn-link:hover {
  text-decoration: underline;
}
</style>
