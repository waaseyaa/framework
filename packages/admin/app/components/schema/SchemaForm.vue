<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import { useEntity } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'
import { schemaFormContextKey } from './schemaFormContext'

const props = defineProps<{
  entityType: string
  entityId?: string
}>()

const emit = defineEmits<{
  saved: [resource: any]
  error: [message: string]
}>()

const { t } = useLanguage()
const { schema, error: schemaError, fetch: fetchSchema, sortedProperties } = useSchema(props.entityType)
const { get, create, update } = useEntity()

const formData = ref<Record<string, any>>({})
const saving = ref(false)
const loadError = ref<string | null>(null)
const isEditMode = computed(() => !!props.entityId)
// True for the whole initial load (schema, plus the existing record in edit
// mode). Keeps the form in one busy state instead of flashing blank between
// the schema and entity fetches.
const loading = ref(true)

provide(schemaFormContextKey, {
  formData,
  isEditMode,
})

// Load schema, then optionally load existing entity if schema succeeded.
// In edit mode the entity id scopes the schema to the record's bundle so its
// per-bundle fields (e.g. a page's body) appear in the form.
onMounted(async () => {
  if (props.entityId) {
    // Edit mode: the bundle-scoped schema and the existing record are
    // independent reads — fetch them concurrently so the slower one bounds the
    // wait. The entity GET is deduped against the sibling history widget
    // requesting the same record in the same tick (see the transport adapter).
    const [, entityResult] = await Promise.allSettled([
      fetchSchema(props.entityId),
      get(props.entityType, props.entityId),
    ])
    if (entityResult.status === 'fulfilled') {
      formData.value = { ...entityResult.value.attributes }
    } else {
      const e: any = entityResult.reason
      loadError.value = e?.data?.errors?.[0]?.detail ?? e?.message ?? t('error_loading_entity')
    }
  } else {
    // Create mode: only the schema is needed; seed defaults from it.
    await fetchSchema()
    if (schema.value) {
      const defaults: Record<string, any> = {}
      for (const [fieldName, fieldSchema] of Object.entries(schema.value.properties ?? {})) {
        if ('default' in fieldSchema) {
          defaults[fieldName] = fieldSchema.default
        } else if (fieldSchema.type === 'boolean') {
          defaults[fieldName] = false
        }
      }
      formData.value = defaults
    }
  }
  loading.value = false
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
    const msg = e.data?.errors?.[0]?.detail ?? e.message ?? t('error_saving_entity')
    emit('error', msg)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="schema-form" :aria-busy="loading ? 'true' : 'false'">
    <div v-if="loading" class="loading" role="status" aria-live="polite">{{ t('opening') }}</div>
    <div v-else-if="schemaError" class="error">{{ schemaError }}</div>
    <div v-else-if="loadError" class="error">{{ loadError }}</div>
    <form v-else @submit.prevent="onSubmit">
      <SchemaField
        v-for="[fieldName, fieldSchema] in editableFields"
        :key="fieldName"
        :name="fieldName"
        :schema="fieldSchema"
        :disabled="!!fieldSchema['x-access-restricted']"
        :model-value="formData[fieldName] ?? ''"
        @update:model-value="(val: any) => { if (!fieldSchema['x-access-restricted']) formData[fieldName] = val }"
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
