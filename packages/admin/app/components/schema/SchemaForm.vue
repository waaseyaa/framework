<script setup lang="ts">
import { useSchema } from '~/composables/useSchema'
import type { SchemaProperty } from '~/composables/useSchema'
import { useEntity } from '~/composables/useEntity'
import { useLanguage } from '~/composables/useLanguage'
import { schemaFormContextKey } from './schemaFormContext'
import { normalizeValidationFailure } from './validationErrors'

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
let submitInFlight = false
const loadError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})
const globalErrors = ref<string[]>([])
const validationSummary = ref<HTMLElement | null>(null)
const isEditMode = computed(() => !!props.entityId)
const bundleKey = computed(() => schema.value?.['x-bundle-key'] ?? null)
const selectedBundle = computed(() => {
  const key = bundleKey.value
  const value = key ? formData.value[key] : null
  return typeof value === 'string' && value !== '' ? value : null
})
// True for the whole initial load (schema, plus the existing record in edit
// mode). Keeps the form in one busy state instead of flashing blank between
// the schema and entity fetches.
const loading = ref(true)

provide(schemaFormContextKey, {
  formData,
  isEditMode,
  entityType: props.entityType,
  bundleKey,
  selectedBundle,
})

function valuesForSchema(
  targetSchema: NonNullable<typeof schema.value>,
  existing: Record<string, any> = {},
): Record<string, any> {
  const values: Record<string, any> = {}
  for (const [fieldName, fieldSchema] of Object.entries(targetSchema.properties ?? {})) {
    if (fieldName in existing) {
      values[fieldName] = existing[fieldName]
    } else if ('default' in fieldSchema) {
      values[fieldName] = fieldSchema.default
    } else if (fieldSchema.type === 'boolean') {
      values[fieldName] = false
    }
  }
  return values
}

// Load schema, then optionally load existing entity if schema succeeded.
// In edit mode the entity id scopes the schema to the record's bundle so its
// per-bundle fields (e.g. a page's body) appear in the form.
onMounted(async () => {
  clearValidation()
  if (props.entityId) {
    // Edit mode: the bundle-scoped schema and the existing record are
    // independent reads — fetch them concurrently so the slower one bounds the
    // wait. The entity GET is deduped against the sibling history widget
    // requesting the same record in the same tick (see the transport adapter).
    const [, entityResult] = await Promise.allSettled([
      fetchSchema({ id: props.entityId }),
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
      formData.value = valuesForSchema(schema.value)
    }
  }
  loading.value = false
})

const editableFields = computed(() => sortedProperties(true))
const validationMessages = computed(() => [
  ...Object.entries(fieldErrors.value).map(([fieldName, message]) => ({ fieldName, message })),
  ...globalErrors.value.map(message => ({ fieldName: null, message })),
])

function fieldInputId(fieldName: string): string {
  const normalize = (value: string) => value.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '')
  // Keep the readable portion for diagnostics, then append a reversible
  // code-point token. Slugging alone aliases distinct schema keys such as
  // `foo_bar` and `foo-bar`, breaking every label/help/error association.
  const uniqueToken = [...fieldName].map(character => character.codePointAt(0)!.toString(16)).join('-')
  return `schema-${normalize(props.entityType)}-${normalize(fieldName)}-${uniqueToken}`
}

function fieldIsRequired(fieldName: string, fieldSchema: SchemaProperty): boolean {
  return fieldSchema['x-required'] === true || schema.value?.required?.includes(fieldName) === true
}

function clearValidation(fieldName?: string) {
  if (fieldName !== undefined) {
    if (fieldErrors.value[fieldName] !== undefined) {
      fieldErrors.value = Object.fromEntries(
        Object.entries(fieldErrors.value).filter(([name]) => name !== fieldName),
      )
    }
    if (Object.keys(fieldErrors.value).length === 0) globalErrors.value = []
    return
  }
  fieldErrors.value = {}
  globalErrors.value = []
}

async function focusValidationSummary() {
  await nextTick()
  validationSummary.value?.focus()
}

function validateRequiredFields(): Record<string, string> {
  const errors: Record<string, string> = {}
  for (const [fieldName, fieldSchema] of editableFields.value) {
    if (!fieldIsRequired(fieldName, fieldSchema) || fieldSchema['x-access-restricted']) continue
    const value = formData.value[fieldName]
    if (value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
      errors[fieldName] = `${fieldSchema['x-label'] ?? fieldName} is required.`
    }
  }
  return errors
}

function isValidIsoDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (match === null) return false
  const year = Number(match[1])
  const month = Number(match[2])
  const day = Number(match[3])
  if (month < 1 || month > 12 || day < 1) return false
  return day <= new Date(Date.UTC(year, month, 0)).getUTCDate()
}

function validateDateFields(errors: Record<string, string>): Record<string, string> {
  for (const [fieldName, fieldSchema] of editableFields.value) {
    if (errors[fieldName] !== undefined || fieldSchema['x-widget'] !== 'date') continue
    const value = formData.value[fieldName]
    if (value === undefined || value === null || value === '') continue
    const label = fieldSchema['x-label'] ?? fieldName
    if (typeof value !== 'string' || !isValidIsoDate(value)) {
      errors[fieldName] = `${label} must be a valid date in YYYY-MM-DD format.`
    } else if (fieldSchema['x-min'] && value < fieldSchema['x-min']) {
      errors[fieldName] = `${label} must be on or after ${fieldSchema['x-min']}.`
    } else if (fieldSchema['x-max'] && value > fieldSchema['x-max']) {
      errors[fieldName] = `${label} must be on or before ${fieldSchema['x-max']}.`
    }
  }
  return errors
}

async function onFieldUpdate(fieldName: string, value: any, accessRestricted: boolean) {
  if (accessRestricted) return

  formData.value[fieldName] = value
  clearValidation(fieldName)
  const bundleKey = schema.value?.['x-bundle-key']
  if (props.entityId || bundleKey !== fieldName || typeof value !== 'string' || value === '') return

  clearValidation()
  await fetchSchema({ bundle: value })
  if (schema.value && !schemaError.value) {
    // Preserve shared fields and defaults, but discard values belonging only to
    // the previously selected bundle so they cannot leak into create payloads.
    formData.value = valuesForSchema(schema.value, {
      ...formData.value,
      [bundleKey]: value,
    })
  }
}

async function onSubmit() {
  if (submitInFlight) return
  submitInFlight = true
  clearValidation()
  const clientErrors = validateDateFields(validateRequiredFields())
  if (Object.keys(clientErrors).length > 0) {
    fieldErrors.value = clientErrors
    await focusValidationSummary()
    submitInFlight = false
    return
  }
  saving.value = true
  try {
    const resource = props.entityId
      ? await update(props.entityType, props.entityId, formData.value)
      : await create(props.entityType, formData.value)
    emit('saved', resource)
  } catch (e: any) {
    const normalized = normalizeValidationFailure(e, new Set(editableFields.value.map(([name]) => name)))
    fieldErrors.value = normalized.fieldErrors
    globalErrors.value = normalized.globalErrors
    const msg = normalized.globalErrors[0] ?? Object.values(normalized.fieldErrors)[0] ?? t('error_saving_entity')
    emit('error', msg)
    await focusValidationSummary()
  } finally {
    saving.value = false
    submitInFlight = false
  }
}
</script>

<template>
  <div class="schema-form" :aria-busy="loading ? 'true' : 'false'" :data-anchor="`form:${entityType}`">
    <div v-if="loading" class="loading" role="status" aria-live="polite">{{ t('opening') }}</div>
    <div v-else-if="schemaError" class="error">{{ schemaError }}</div>
    <div v-else-if="loadError" class="error">{{ loadError }}</div>
    <form v-else novalidate @submit.prevent="onSubmit">
      <div
        v-if="validationMessages.length > 0"
        ref="validationSummary"
        class="validation-summary"
        data-testid="validation-summary"
        role="alert"
        aria-live="assertive"
        tabindex="-1"
      >
        <p class="validation-summary__title">The form could not be saved. Correct the following errors:</p>
        <ul>
          <li v-for="(item, index) in validationMessages" :key="`${item.fieldName ?? 'form'}-${index}`">
            <a v-if="item.fieldName" :href="`#${fieldInputId(item.fieldName)}`">{{ item.message }}</a>
            <span v-else>{{ item.message }}</span>
          </li>
        </ul>
      </div>
      <div
        v-for="[fieldName, fieldSchema] in editableFields"
        :key="fieldName"
        class="field-anchor"
        :data-anchor="`field:${entityType}:${fieldName}`"
      >
        <SchemaField
          :name="fieldName"
          :schema="fieldSchema"
          :input-id="fieldInputId(fieldName)"
          :required="fieldIsRequired(fieldName, fieldSchema)"
          :error="fieldErrors[fieldName]"
          :disabled="!!fieldSchema['x-access-restricted']"
          :model-value="formData[fieldName] ?? ''"
          @update:model-value="(val: any) => onFieldUpdate(fieldName, val, !!fieldSchema['x-access-restricted'])"
        />
      </div>

      <div class="form-actions">
        <button
          type="submit"
          :disabled="saving"
          class="btn btn-primary"
          :data-anchor="`action:${entityType}:submit`"
          :aria-label="saving ? t('saving') : (entityId ? t('save') : t('create'))"
        >
          {{ saving ? t('saving') : (entityId ? t('save') : t('create')) }}
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.validation-summary {
  margin-bottom: 1rem;
  padding: 0.875rem 1rem;
  border: 2px solid #b42318;
  border-left-width: 0.5rem;
  border-radius: 0.25rem;
  color: #7a271a;
  background: #fffbfa;
}
.validation-summary:focus { outline: 3px solid #175cd3; outline-offset: 3px; }
.validation-summary__title { margin: 0 0 0.5rem; font-weight: 700; }
.validation-summary ul { margin: 0; padding-left: 1.25rem; }
.validation-summary a { color: #7a271a; text-decoration: underline; }
:deep(.field-error) {
  margin-top: 0.375rem;
  padding-left: 0.5rem;
  border-left: 3px solid #b42318;
  color: #7a271a;
  font-size: 0.875rem;
}
:deep(.field-input[aria-invalid="true"]) { border-color: #b42318; border-width: 2px; }
:deep(.field-input:focus-visible),
:deep(button:focus-visible),
:deep(select:focus-visible),
:deep(textarea:focus-visible) {
  outline: 3px solid #175cd3;
  outline-offset: 2px;
}
</style>
