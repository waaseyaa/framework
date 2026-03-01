<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import { useEntity } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'

const props = defineProps<{
  entityType: string
  entityId?: string
}>()

const emit = defineEmits<{
  saved: [resource: any]
  error: [message: string]
}>()

const { t } = useLanguage()
const { schema, loading: schemaLoading, error: schemaError, fetch: fetchSchema, sortedProperties } = useSchema(props.entityType)
const { get, create, update } = useEntity()

const formData = ref<Record<string, any>>({})
const saving = ref(false)
const loadError = ref<string | null>(null)

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

const editableFields = computed(() => sortedProperties(true))

async function onSubmit() {
  saving.value = true
  try {
    const resource = props.entityId
      ? await update(props.entityType, props.entityId, formData.value)
      : await create(props.entityType, formData.value)
    emit('saved', resource)
  } catch (e: any) {
    const msg = e.data?.errors?.[0]?.detail ?? e.message ?? 'Save failed'
    emit('error', msg)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="schema-form">
    <div v-if="schemaLoading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="schemaError" class="error">{{ schemaError }}</div>
    <div v-else-if="loadError" class="error">{{ loadError }}</div>
    <form v-else @submit.prevent="onSubmit">
      <SchemaField
        v-for="[fieldName, fieldSchema] in editableFields"
        :key="fieldName"
        :name="fieldName"
        :schema="fieldSchema"
        :model-value="formData[fieldName] ?? ''"
        @update:model-value="formData[fieldName] = $event"
      />

      <div class="form-actions">
        <button
          type="submit"
          :disabled="saving"
          class="btn btn-primary"
          :aria-label="saving ? t('saving') : (entityId ? t('save') : t('create'))"
        >
          {{ saving ? t('saving') : (entityId ? t('save') : t('create')) }}
        </button>
      </div>
    </form>
  </div>
</template>
